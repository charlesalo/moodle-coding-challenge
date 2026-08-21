<?php

declare(strict_types=1);

namespace App\Import;

use App\Csv\CsvReader;

/**
 * Validates normalised records and detects duplicates within a single file.
 *
 * Stateful for the duration of one import: it remembers which email was seen
 * on which line so a later duplicate can name the earlier row. Call reset()
 * before reusing it for another file.
 */
final class UserValidator
{
    private const EXPECTED_COLUMNS = 3;

    /** @var array<string, int> lowercased email => line it first appeared on */
    private array $seenEmails = [];

    public function reset(): void
    {
        $this->seenEmails = [];
    }

    /**
     * Validate one row. Duplicates are judged against rows already passed in,
     * so the first occurrence wins and every later one is an error.
     *
     * @param int $fieldCount how many columns the raw CSV row actually had
     */
    public function validate(UserRecord $record, int $line, int $fieldCount = self::EXPECTED_COLUMNS): RecordResult
    {
        $errors = [];

        if ($fieldCount !== self::EXPECTED_COLUMNS) {
            $errors[] = new ValidationError($line, null, sprintf(
                'Expected %d columns (%s) but found %d.',
                self::EXPECTED_COLUMNS,
                implode(', ', CsvReader::REQUIRED_COLUMNS),
                $fieldCount,
            ));
        }

        if ($record->name === '') {
            $errors[] = new ValidationError($line, 'name', 'Name is required.');
        }

        if ($record->surname === '') {
            $errors[] = new ValidationError($line, 'surname', 'Surname is required.');
        }

        if ($record->email === '') {
            $errors[] = new ValidationError($line, 'email', 'Email is required.');
        } elseif (!self::isValidEmail($record->email)) {
            $errors[] = new ValidationError($line, 'email', sprintf(
                'Invalid email address format: "%s".',
                $record->email,
            ));
        } else {
            // Only rows with a usable address take part in duplicate detection.
            $firstSeen = $this->seenEmails[$record->email] ?? null;
            if ($firstSeen !== null) {
                $errors[] = new ValidationError($line, 'email', sprintf(
                    'Duplicate email address "%s", first seen on line %d.',
                    $record->email,
                    $firstSeen,
                ));
            } else {
                $this->seenEmails[$record->email] = $line;
            }
        }

        return new RecordResult($line, $record, $errors);
    }

    /**
     * filter_var already requires a dotted domain, so it rejects both the
     * brief's "john@example.com@example.com" and the bare-host "john@example".
     * No supplementary check is needed.
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
