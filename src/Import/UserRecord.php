<?php

declare(strict_types=1);

namespace App\Import;

/**
 * A single user from the CSV, already trimmed and normalised.
 *
 * Normalisation happens on construction, before validation, because the
 * sample data depends on that ordering: " spaces@example.com " is only a
 * valid address once trimmed, and "JOHN.SMITH@EXAMPLE.COM" is only a
 * duplicate of "john.smith@example.com" once lowercased.
 */
final class UserRecord
{
    public function __construct(
        public readonly string $name,
        public readonly string $surname,
        public readonly string $email,
    ) {
    }

    /**
     * Build a normalised record from three raw CSV fields.
     *
     * @param list<string|null> $fields
     */
    public static function fromFields(array $fields): self
    {
        return new self(
            self::capitalise($fields[0] ?? ''),
            self::capitalise($fields[1] ?? ''),
            self::normaliseEmail($fields[2] ?? ''),
        );
    }

    /**
     * Capitalise a name: first letter of each word up, the rest down.
     *
     * Deliberately predictable rather than clever. "mcdonald" becomes
     * "Mcdonald" and "o'brien" becomes "O'brien", which is not linguistically
     * perfect; special-casing surname particles is out of scope.
     */
    private static function capitalise(?string $value): string
    {
        $value = self::clean($value);
        if ($value === '') {
            return '';
        }

        return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    private static function normaliseEmail(?string $value): string
    {
        return mb_strtolower(self::clean($value), 'UTF-8');
    }

    /**
     * Trim whitespace, stray CR from CRLF files, and a leading UTF-8 BOM.
     */
    private static function clean(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = ltrim($value, "\u{FEFF}");

        return trim($value);
    }
}
