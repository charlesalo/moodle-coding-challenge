<?php

declare(strict_types=1);

namespace App\Tests\Cli;

use App\Cli\ReportRenderer;
use App\Import\ImportService;
use App\Import\UserValidator;
use App\Tests\Doubles\FakeUserRepository;
use PHPUnit\Framework\TestCase;

final class ReportRendererTest extends TestCase
{
    private const SAMPLE = __DIR__ . '/../../users.csv';

    private function render(bool $dryRun, ?FakeUserRepository $repo = null): string
    {
        $report = (new ImportService(new UserValidator(), $repo ?? new FakeUserRepository()))
            ->run(self::SAMPLE, $dryRun);

        return (new ReportRenderer())->render($report);
    }

    public function testShowsTheSummaryShapeTheBriefIllustrates(): void
    {
        $output = $this->render(dryRun: true);

        self::assertStringContainsString('Users found: 49', $output);
        self::assertStringContainsString('Valid:       41', $output);
        self::assertStringContainsString('Invalid:     8', $output);
    }

    public function testDryRunSaysNothingWasWrittenAndOmitsImportTotals(): void
    {
        $output = $this->render(dryRun: true);

        self::assertStringContainsString('Dry run: no changes were written', $output);
        self::assertStringNotContainsString('Imported:', $output);
    }

    public function testRealRunShowsImportedAndSkipped(): void
    {
        $output = $this->render(dryRun: false);

        self::assertStringContainsString('Imported:    41', $output);
        self::assertStringContainsString('Skipped:     0', $output);
        self::assertStringContainsString('Imported 41 users successfully.', $output);
    }

    public function testListsEveryErrorWithItsCsvLineNumber(): void
    {
        $output = $this->render(dryRun: true);

        self::assertStringContainsString('Validation errors (8):', $output);
        self::assertStringContainsString('Line 42 [email]: Invalid email address format', $output);
        self::assertStringContainsString('Line 46 [name]: Name is required.', $output);
        self::assertStringContainsString('first seen on line 2', $output);
    }

    public function testShowsNoticesWhenTheDatabaseCheckWasSkipped(): void
    {
        $report = (new ImportService(new UserValidator(), null))->run(self::SAMPLE, true);

        self::assertStringContainsString('Note:', (new ReportRenderer())->render($report));
    }

    public function testShowsAReadableDatabaseFailure(): void
    {
        $repo = new FakeUserRepository([], new \App\Db\DatabaseException('connection refused'));
        $report = (new ImportService(new UserValidator(), $repo))->run(self::SAMPLE);

        $output = (new ReportRenderer())->render($report);

        self::assertStringContainsString('Error: connection refused', $output);
        self::assertStringNotContainsString('#0 ', $output, 'no stack trace should reach the terminal');
    }
}
