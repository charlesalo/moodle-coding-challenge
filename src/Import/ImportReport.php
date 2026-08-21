<?php

declare(strict_types=1);

namespace App\Import;

use JsonSerializable;

/**
 * The outcome of one import run: per-record results plus the totals the CLI
 * and the web UI both display.
 */
final class ImportReport implements JsonSerializable
{
    /**
     * @param list<RecordResult> $results
     * @param list<string>       $notices non-fatal remarks, e.g. a skipped database check
     * @param string|null        $failure a database failure that stopped the import
     */
    public function __construct(
        public readonly array $results,
        public readonly bool $dryRun,
        public readonly int $imported = 0,
        public readonly int $skipped = 0,
        public readonly array $notices = [],
        public readonly ?string $failure = null,
    ) {
    }

    public function found(): int
    {
        return count($this->results);
    }

    public function validCount(): int
    {
        return count($this->validRecords());
    }

    public function invalidCount(): int
    {
        return $this->found() - $this->validCount();
    }

    /** @return list<RecordResult> */
    public function validRecords(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (RecordResult $r): bool => $r->isValid(),
        ));
    }

    /** @return list<ValidationError> every error across every record, in line order */
    public function errors(): array
    {
        $errors = [];
        foreach ($this->results as $result) {
            foreach ($result->errors as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    public function hasFailed(): bool
    {
        return $this->failure !== null;
    }

    /** Return a copy carrying insert totals and any failure from the write phase. */
    public function withOutcome(int $imported, int $skipped, ?string $failure = null): self
    {
        return new self(
            $this->results,
            $this->dryRun,
            $imported,
            $skipped,
            $this->notices,
            $failure ?? $this->failure,
        );
    }

    /** Return a copy with an extra notice appended. */
    public function withNotice(string $notice): self
    {
        return new self(
            $this->results,
            $this->dryRun,
            $this->imported,
            $this->skipped,
            [...$this->notices, $notice],
            $this->failure,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'dryRun'   => $this->dryRun,
            'found'    => $this->found(),
            'valid'    => $this->validCount(),
            'invalid'  => $this->invalidCount(),
            'imported' => $this->imported,
            'skipped'  => $this->skipped,
            'notices'  => $this->notices,
            'failure'  => $this->failure,
            'records'  => $this->results,
        ];
    }
}
