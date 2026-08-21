<?php

declare(strict_types=1);

namespace App\Import;

use App\Csv\CsvReader;
use App\Db\DatabaseException;
use App\Db\UserRepositoryInterface;

/**
 * Owns the import flow: parse, normalise, validate, dedupe, then write.
 *
 * The single source of truth for both entrypoints. user_upload.php, the
 * preview endpoint and the import endpoint all call this; none of them
 * re-implement any part of the pipeline.
 */
final class ImportService
{
    public function __construct(
        private readonly UserValidator $validator = new UserValidator(),
        private readonly ?UserRepositoryInterface $repository = null,
    ) {
    }

    /**
     * Parse and validate a CSV file, importing the valid records unless this
     * is a dry run.
     *
     * A dry run never writes. It still performs a read-only lookup for
     * addresses that already exist, so the preview can warn about conflicts;
     * the brief forbids modifying the database, not reading it. If no
     * repository is available the lookup is skipped with a notice rather than
     * failing, which keeps dry runs usable with no database configured.
     *
     * @param string|null $label name to use in file-level error messages,
     *                            so the web UI can name the file the user
     *                            chose rather than the server path.
     * @throws \App\Csv\CsvException on a file-level problem
     */
    public function run(string $path, bool $dryRun = false, ?string $label = null): ImportReport
    {
        $report = $this->validateFile($path, $dryRun, $label);

        if ($report->validCount() === 0) {
            return $report;
        }

        $report = $this->flagExistingEmails($report);

        if ($dryRun || $report->hasFailed() || $report->validCount() === 0) {
            return $report;
        }

        return $this->insert($report);
    }

    /**
     * Parse and validate only. Used directly where the caller must be certain
     * nothing can touch the database.
     */
    public function validateFile(string $path, bool $dryRun = false, ?string $label = null): ImportReport
    {
        $this->validator->reset();

        $results = [];
        foreach ((new CsvReader($path, $label))->rows() as [$line, $fields]) {
            $results[] = $this->validator->validate(
                UserRecord::fromFields($fields),
                $line,
                count($fields),
            );
        }

        return new ImportReport($results, $dryRun);
    }

    /**
     * Mark records whose address is already stored, so neither a preview nor
     * a real run presents them as importable.
     */
    private function flagExistingEmails(ImportReport $report): ImportReport
    {
        if ($this->repository === null) {
            return $report->withNotice(
                'No database connection available, so existing addresses were not checked.',
            );
        }

        $emails = array_map(
            static fn (RecordResult $r): string => $r->record->email,
            $report->validRecords(),
        );

        try {
            $existing = $this->repository->findExistingEmails($emails);
        } catch (DatabaseException $e) {
            // On a dry run this is a degraded preview, not a failure. On a real
            // run the insert would fail too, so stop here with a clear message.
            return $report->dryRun
                ? $report->withNotice('Could not check existing addresses: ' . $e->getMessage())
                : $report->withOutcome(0, 0, $e->getMessage());
        }

        if ($existing === []) {
            return $report;
        }

        $existing = array_flip($existing);
        $message = $report->dryRun
            ? 'Would conflict with a user that already exists in the database.'
            : 'A user with this email address already exists.';

        $results = array_map(
            static fn (RecordResult $r): RecordResult => $r->isValid() && isset($existing[$r->record->email])
                ? $r->withError(new ValidationError($r->line, 'email', $message))
                : $r,
            $report->results,
        );

        return new ImportReport(
            array_values($results),
            $report->dryRun,
            $report->imported,
            $report->skipped,
            $report->notices,
            $report->failure,
        );
    }

    private function insert(ImportReport $report): ImportReport
    {
        if ($this->repository === null) {
            return $report->withOutcome(0, 0, 'No database connection is configured, so nothing was imported.');
        }

        $records = array_map(
            static fn (RecordResult $r): UserRecord => $r->record,
            $report->validRecords(),
        );

        try {
            $outcome = $this->repository->insertMany($records);
        } catch (DatabaseException $e) {
            // Reported on the report rather than thrown, so callers can render
            // a readable message and choose their own exit status.
            return $report->withOutcome(0, 0, $e->getMessage());
        }

        return $report->withOutcome($outcome['inserted'], $outcome['skipped']);
    }
}
