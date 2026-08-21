<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\UserRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserRecordTest extends TestCase
{
    public function testCapitalisesNameAndSurnameAndLowercasesEmail(): void
    {
        $record = UserRecord::fromFields(['john', 'smith', 'JOHN.SMITH@example.com']);

        self::assertSame('John', $record->name);
        self::assertSame('Smith', $record->surname);
        self::assertSame('john.smith@example.com', $record->email);
    }

    /** The brief's own example: john,smith,JOHN@EXAMPLE.COM -> John,Smith,john@example.com */
    public function testMatchesTheBriefsWorkedExample(): void
    {
        $record = UserRecord::fromFields(['john', 'smith', 'JOHN@EXAMPLE.COM']);

        self::assertSame('John', $record->name);
        self::assertSame('Smith', $record->surname);
        self::assertSame('john@example.com', $record->email);
    }

    public function testLowercasesAnAlreadyCapitalisedName(): void
    {
        $record = UserRecord::fromFields(['JOHN', 'McDONALD', 'a@b.com']);

        self::assertSame('John', $record->name);
        self::assertSame('Mcdonald', $record->surname);
    }

    /** Row 50 of the sample file: padded fields must survive trimming. */
    public function testTrimsSurroundingWhitespace(): void
    {
        $record = UserRecord::fromFields(['  spaces  ', "\ttest\t", ' spaces@example.com ']);

        self::assertSame('Spaces', $record->name);
        self::assertSame('Test', $record->surname);
        self::assertSame('spaces@example.com', $record->email);
    }

    /** The sample file uses CRLF; a stray CR must not survive into the data. */
    public function testStripsStrayCarriageReturn(): void
    {
        $record = UserRecord::fromFields(['jane', 'doe', "jane.doe@example.com\r"]);

        self::assertSame('jane.doe@example.com', $record->email);
    }

    public function testStripsLeadingUtf8Bom(): void
    {
        $record = UserRecord::fromFields(["\u{FEFF}john", 'smith', 'a@b.com']);

        self::assertSame('John', $record->name);
    }

    public function testHandlesMissingFields(): void
    {
        $record = UserRecord::fromFields(['john']);

        self::assertSame('John', $record->name);
        self::assertSame('', $record->surname);
        self::assertSame('', $record->email);
    }

    #[DataProvider('multibyteNames')]
    public function testHandlesMultibyteInput(string $input, string $expected): void
    {
        self::assertSame($expected, UserRecord::fromFields([$input, 'x', 'a@b.com'])->name);
    }

    /** @return array<string, array{string, string}> */
    public static function multibyteNames(): array
    {
        return [
            'accented'   => ['josé', 'José'],
            'uppercase accented' => ['JOSÉ', 'José'],
            'tilde'      => ['ñoño', 'Ñoño'],
            'cyrillic'   => ['иван', 'Иван'],
            'umlaut'     => ['MÜLLER', 'Müller'],
        ];
    }

    /**
     * MB_CASE_TITLE treats spaces and hyphens as word boundaries but not
     * apostrophes. Pinned here so the README's documented behaviour stays
     * true if the implementation is ever changed.
     */
    #[DataProvider('capitalisationEdgeCases')]
    public function testDocumentedCapitalisationLimits(string $input, string $expected): void
    {
        self::assertSame($expected, UserRecord::fromFields(['x', $input, 'a@b.com'])->surname);
    }

    /** @return array<string, array{string, string}> */
    public static function capitalisationEdgeCases(): array
    {
        return [
            'no particle handling'   => ['mcdonald', 'Mcdonald'],
            'apostrophe not a break' => ["o'brien", "O'brien"],
            'hyphen is a break'      => ['mary-jane', 'Mary-Jane'],
            'particles capitalised'  => ['van der berg', 'Van Der Berg'],
        ];
    }
}
