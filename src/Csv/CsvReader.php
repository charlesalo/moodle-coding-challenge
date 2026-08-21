<?php

declare(strict_types=1);

namespace App\Csv;

use Generator;

/**
 * Streams data rows out of a comma-delimited CSV file with a header row.
 *
 * Yields the 1-based CSV line number alongside the raw fields, so validation
 * errors can point at the line a spreadsheet would show. Only file-level
 * problems throw; a row with the wrong number of columns is yielded as-is and
 * reported per-record by the validator.
 */
final class CsvReader
{
    public const REQUIRED_COLUMNS = ['name', 'surname', 'email'];

    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return Generator<int, array{int, list<string>}>
     * @throws CsvException
     */
    public function rows(): Generator
    {
        $handle = $this->open();

        try {
            $line = 0;

            $header = $this->readRecord($handle, $line);
            if ($header === null) {
                throw new CsvException(sprintf('CSV file is empty: %s', $this->path));
            }

            $this->assertHeaderIsValid($header[0]);

            while (($record = $this->readRecord($handle, $line)) !== null) {
                [$fields, $startLine] = $record;

                // Skip a wholly blank line rather than reporting it as a bad record.
                if ($fields === [null] || $fields === ['']) {
                    continue;
                }

                yield [$startLine, array_map(static fn ($v) => (string) ($v ?? ''), $fields)];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     * @throws CsvException
     */
    private function open()
    {
        if (!file_exists($this->path)) {
            throw new CsvException(sprintf('CSV file not found: %s', $this->path));
        }

        if (is_dir($this->path)) {
            throw new CsvException(sprintf('Expected a CSV file but found a directory: %s', $this->path));
        }

        if (!is_readable($this->path)) {
            throw new CsvException(sprintf('CSV file is not readable (check permissions): %s', $this->path));
        }

        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            throw new CsvException(sprintf('Could not open CSV file: %s', $this->path));
        }

        return $handle;
    }

    /**
     * Read one CSV record, advancing $line by the number of physical lines it
     * spanned and returning the line the record *started* on.
     *
     * Counting newlines from the raw bytes keeps line numbers correct even
     * when a quoted field contains a line break, so a later row still reports
     * the line a spreadsheet would show.
     *
     * @param  resource $handle
     * @return array{list<string|null>, int}|null  null at end of file
     */
    private function readRecord($handle, int &$line): ?array
    {
        $start = ftell($handle);

        $fields = fgetcsv($handle, 0, ',', '"', '\\');
        if ($fields === false) {
            return null;
        }

        $startLine = $line + 1;
        $end = ftell($handle);

        if ($start !== false && $end !== false && $end > $start) {
            fseek($handle, $start);
            $raw = (string) fread($handle, $end - $start);
            fseek($handle, $end);
            $line += max(1, substr_count($raw, "\n"));
        } else {
            $line++;
        }

        return [$fields, $startLine];
    }

    /**
     * @param list<string|null> $header
     * @throws CsvException
     */
    private function assertHeaderIsValid(array $header): void
    {
        $normalised = [];
        foreach ($header as $column) {
            $column = ltrim((string) ($column ?? ''), "\u{FEFF}");
            $normalised[] = mb_strtolower(trim($column), 'UTF-8');
        }

        foreach (self::REQUIRED_COLUMNS as $index => $expected) {
            if (($normalised[$index] ?? null) !== $expected) {
                throw new CsvException(sprintf(
                    'Invalid CSV header. Expected "%s" but found "%s".',
                    implode(',', self::REQUIRED_COLUMNS),
                    implode(',', $normalised),
                ));
            }
        }
    }
}
