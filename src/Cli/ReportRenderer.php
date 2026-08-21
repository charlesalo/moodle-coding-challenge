<?php

declare(strict_types=1);

namespace App\Cli;

use App\Import\ImportReport;

/**
 * Renders an ImportReport as plain text for the terminal.
 */
final class ReportRenderer
{
    public function render(ImportReport $report): string
    {
        $lines = [];

        if ($report->dryRun) {
            $lines[] = 'Dry run: no changes were written to the database.';
            $lines[] = '';
        }

        $lines[] = sprintf('Users found: %d', $report->found());
        $lines[] = sprintf('Valid:       %d', $report->validCount());
        $lines[] = sprintf('Invalid:     %d', $report->invalidCount());

        if (!$report->dryRun) {
            $lines[] = sprintf('Imported:    %d', $report->imported);
            $lines[] = sprintf('Skipped:     %d', $report->skipped);
        }

        $errors = $report->errors();
        if ($errors !== []) {
            $lines[] = '';
            $lines[] = sprintf('Validation errors (%d):', count($errors));
            foreach ($errors as $error) {
                $lines[] = '  ' . $error->describe();
            }
        }

        foreach ($report->notices as $notice) {
            $lines[] = '';
            $lines[] = 'Note: ' . $notice;
        }

        if ($report->hasFailed()) {
            $lines[] = '';
            $lines[] = 'Error: ' . $report->failure;
        } elseif (!$report->dryRun && $report->imported > 0) {
            $lines[] = '';
            $lines[] = sprintf(
                'Imported %d user%s successfully.',
                $report->imported,
                $report->imported === 1 ? '' : 's',
            );
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
