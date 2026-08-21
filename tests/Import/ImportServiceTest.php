<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Csv\CsvException;
use App\Db\DatabaseException;
use App\Import\ImportService;
use App\Import\UserValidator;
use App\Tests\Doubles\FakeUserRepository;
use PHPUnit\Framework\TestCase;

final class ImportServiceTest extends TestCase
{
    private const SAMPLE = __DIR__ . '/../../users.csv';

    private function service(?FakeUserRepository $repo): ImportService
    {
        return new ImportService(new UserValidator(), $repo);
    }

    /** The acceptance criteria: the shipped sample file must total 49 / 41 / 8. */
    public function testSampleFileProducesTheExpectedTotals(): void
    {
        $report = $this->service(new FakeUserRepository())->run(self::SAMPLE, dryRun: true);

        self::assertSame(49, $report->found());
        self::assertSame(41, $report->validCount());
        self::assertSame(8, $report->invalidCount());
    }

    public function testSampleFileFlagsTheExpectedLines(): void
    {
        $report = $this->service(new FakeUserRepository())->run(self::SAMPLE, dryRun: true);

        $failedLines = array_values(array_unique(array_map(
            static fn ($e): int => $e->line,
            $report->errors(),
        )));

        self::assertSame([42, 43, 44, 45, 46, 47, 48, 49], $failedLines);
    }

    /** Row 50 is only valid because trimming runs before validation. */
    public function testPaddedRowFiftyIsImported(): void
    {
        $repo = new FakeUserRepository();
        $this->service($repo)->run(self::SAMPLE);

        $emails = array_map(static fn ($r): string => $r->email, $repo->inserted);

        self::assertContains('spaces@example.com', $emails);
        self::assertCount(41, $repo->inserted);
    }

    public function testDryRunNeverInserts(): void
    {
        $repo = new FakeUserRepository();

        $report = $this->service($repo)->run(self::SAMPLE, dryRun: true);

        self::assertSame(0, $repo->insertCalls, 'dry run must not call insertMany');
        self::assertSame([], $repo->inserted);
        self::assertSame(0, $report->imported);
        self::assertTrue($report->dryRun);
    }

    public function testRealRunInsertsOnlyValidRecords(): void
    {
        $repo = new FakeUserRepository();

        $report = $this->service($repo)->run(self::SAMPLE);

        self::assertSame(41, $report->imported);
        self::assertSame(0, $report->skipped);
        self::assertCount(41, $repo->inserted);
        self::assertFalse($report->hasFailed());
    }

    public function testNormalisedValuesAreWhatGetInserted(): void
    {
        $repo = new FakeUserRepository();
        $this->service($repo)->run(self::SAMPLE);

        $first = $repo->inserted[0];

        self::assertSame('John', $first->name);
        self::assertSame('Smith', $first->surname);
        self::assertSame('john.smith@example.com', $first->email);
    }

    public function testExistingDatabaseAddressIsRejectedNotReimported(): void
    {
        $repo = new FakeUserRepository(['john.smith@example.com']);

        $report = $this->service($repo)->run(self::SAMPLE);

        self::assertSame(40, $report->validCount());
        self::assertSame(9, $report->invalidCount());
        self::assertSame(40, $report->imported);

        $messages = array_map(static fn ($e): string => $e->message, $report->errors());
        self::assertContains('A user with this email address already exists.', $messages);
    }

    public function testDryRunReportsConflictsAsWouldConflict(): void
    {
        $repo = new FakeUserRepository(['jane.doe@example.com']);

        $report = $this->service($repo)->run(self::SAMPLE, dryRun: true);

        $messages = array_map(static fn ($e): string => $e->message, $report->errors());
        self::assertContains(
            'Would conflict with a user that already exists in the database.',
            $messages,
        );
        self::assertSame(0, $repo->insertCalls);
    }

    public function testDatabaseFailureIsReportedNotFatal(): void
    {
        $repo = new FakeUserRepository([], new DatabaseException('connection refused'));

        $report = $this->service($repo)->run(self::SAMPLE);

        self::assertTrue($report->hasFailed());
        self::assertStringContainsString('connection refused', (string) $report->failure);
        self::assertSame(0, $report->imported);
        // Parsing and validation still produced a usable report.
        self::assertSame(49, $report->found());
    }

    public function testDryRunDegradesGracefullyWithNoRepository(): void
    {
        $report = $this->service(null)->run(self::SAMPLE, dryRun: true);

        self::assertSame(41, $report->validCount());
        self::assertFalse($report->hasFailed());
        self::assertNotEmpty($report->notices);
    }

    public function testDryRunSurvivesADatabaseOutage(): void
    {
        $repo = new FakeUserRepository([], new DatabaseException('server unavailable'));

        $report = $this->service($repo)->run(self::SAMPLE, dryRun: true);

        self::assertFalse($report->hasFailed(), 'a preview must still be usable');
        self::assertSame(41, $report->validCount());
        self::assertNotEmpty($report->notices);
    }

    public function testMissingFileRaisesACsvException(): void
    {
        $this->expectException(CsvException::class);

        $this->service(new FakeUserRepository())->run(__DIR__ . '/nope.csv');
    }

    public function testFileWithNoValidRowsNeverTouchesTheDatabase(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "name,surname,email\n,,\n");

        try {
            $repo = new FakeUserRepository();
            $report = $this->service($repo)->run($path);

            self::assertSame(1, $report->found());
            self::assertSame(0, $report->validCount());
            self::assertSame(0, $repo->insertCalls);
        } finally {
            unlink($path);
        }
    }

    public function testHeaderOnlyFileIsAnEmptySuccess(): void
    {
        $repo = new FakeUserRepository();

        $report = $this->service($repo)->run(__DIR__ . '/../Fixtures/header-only.csv');

        self::assertSame(0, $report->found());
        self::assertFalse($report->hasFailed());
        self::assertSame(0, $repo->insertCalls);
    }

    public function testValidateFileNeverConsultsTheRepository(): void
    {
        $repo = new FakeUserRepository(['john.smith@example.com']);

        $report = $this->service($repo)->validateFile(self::SAMPLE);

        self::assertSame(41, $report->validCount(), 'no database check was performed');
        self::assertSame(0, $repo->insertCalls);
    }

    public function testRunningTwiceDoesNotLeakDuplicateState(): void
    {
        $service = $this->service(new FakeUserRepository());

        $first  = $service->run(self::SAMPLE, dryRun: true);
        $second = $service->run(self::SAMPLE, dryRun: true);

        self::assertSame($first->validCount(), $second->validCount());
        self::assertSame(41, $second->validCount());
    }
}
