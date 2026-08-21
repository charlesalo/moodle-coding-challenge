<?php

declare(strict_types=1);

namespace App\Tests\Csv;

use App\Csv\CsvException;
use App\Csv\CsvReader;
use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    /** @return list<array{int, list<string>}> */
    private function read(string $fixture): array
    {
        return iterator_to_array((new CsvReader($this->fixture($fixture)))->rows(), false);
    }

    public function testReadsWellFormedFileAndSkipsHeader(): void
    {
        $rows = $this->read('well-formed.csv');

        self::assertCount(2, $rows);
        self::assertSame([2, ['john', 'smith', 'john@example.com']], $rows[0]);
        self::assertSame([3, ['jane', 'doe', 'jane@example.com']], $rows[1]);
    }

    public function testThrowsWhenFileIsMissing(): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('CSV file not found');

        $this->read('does-not-exist.csv');
    }

    public function testThrowsWhenPathIsADirectory(): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('found a directory');

        iterator_to_array((new CsvReader(__DIR__))->rows(), false);
    }

    public function testThrowsWhenFileIsUnreadable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "name,surname,email\njohn,smith,a@b.com\n");
        chmod($path, 0000);

        // Running as root defeats the permission bit, so skip rather than fail.
        if (is_readable($path)) {
            chmod($path, 0644);
            unlink($path);
            self::markTestSkipped('Cannot make a file unreadable as this user.');
        }

        try {
            $this->expectException(CsvException::class);
            $this->expectExceptionMessage('not readable');
            iterator_to_array((new CsvReader($path))->rows(), false);
        } finally {
            chmod($path, 0644);
            unlink($path);
        }
    }

    public function testThrowsOnEmptyFile(): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('empty');

        $this->read('empty.csv');
    }

    public function testHeaderOnlyFileYieldsNoRows(): void
    {
        self::assertSame([], $this->read('header-only.csv'));
    }

    public function testThrowsOnUnexpectedHeader(): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('Invalid CSV header');

        $this->read('bad-header.csv');
    }

    public function testAcceptsHeaderWithUtf8Bom(): void
    {
        $rows = $this->read('bom-header.csv');

        self::assertCount(1, $rows);
        self::assertSame(['john', 'smith', 'john@example.com'], $rows[0][1]);
    }

    /** Wrong column counts are yielded, not thrown: the validator reports them per row. */
    public function testYieldsRowsWithWrongColumnCount(): void
    {
        $rows = $this->read('wrong-column-count.csv');

        self::assertCount(3, $rows);
        self::assertCount(4, $rows[1][1], 'row with an extra column is still yielded');
        self::assertCount(2, $rows[2][1], 'short row is still yielded');
    }

    public function testSkipsBlankLinesButKeepsLineNumbersAccurate(): void
    {
        $rows = $this->read('blank-lines.csv');

        self::assertCount(2, $rows);
        self::assertSame(2, $rows[0][0]);
        self::assertSame(5, $rows[1][0], 'the row after two blank lines is on line 5');
    }

    /** A quoted field containing a newline must not desynchronise line numbers. */
    public function testCountsLinesAcrossAQuotedNewline(): void
    {
        $rows = $this->read('embedded-newline.csv');

        self::assertCount(2, $rows);
        self::assertSame(2, $rows[0][0]);
        self::assertSame("multi\nline", $rows[0][1][0]);
        self::assertSame(4, $rows[1][0], 'the following row is on line 4, not line 3');
    }

    /** Line numbers must match the sample file the brief ships. */
    public function testLineNumbersMatchTheSampleFile(): void
    {
        $rows = iterator_to_array(
            (new CsvReader(__DIR__ . '/../../users.csv'))->rows(),
            false,
        );

        self::assertCount(49, $rows);
        self::assertSame(2, $rows[0][0]);
        self::assertSame(50, $rows[48][0]);
        self::assertSame(['spaces', 'test', ' spaces@example.com '], $rows[48][1]);
    }
}
