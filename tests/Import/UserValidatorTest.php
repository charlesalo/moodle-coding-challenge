<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\UserRecord;
use App\Import\UserValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserValidatorTest extends TestCase
{
    private UserValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UserValidator();
    }

    /** @param list<string> $fields */
    private function check(array $fields, int $line = 2)
    {
        return $this->validator->validate(UserRecord::fromFields($fields), $line, count($fields));
    }

    public function testAcceptsAWellFormedRecord(): void
    {
        $result = $this->check(['john', 'smith', 'john.smith@example.com']);

        self::assertTrue($result->isValid());
        self::assertSame([], $result->messages());
    }

    /**
     * The brief names this address directly, so prove filter_var rejects it
     * rather than assuming it does.
     */
    public function testRejectsTheBriefsDoubleDomainExample(): void
    {
        self::assertFalse(UserValidator::isValidEmail('john@example.com@example.com'));

        $result = $this->check(['john', 'smith', 'john@example.com@example.com']);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Invalid email address format', $result->messages()[0]);
    }

    /**
     * Contrary to a widely repeated claim, filter_var requires a dotted
     * domain on PHP 8.3, so no supplementary TLD check is needed. Pinned
     * here because the README documents this decision.
     */
    #[DataProvider('emailCases')]
    public function testEmailFormatRules(string $email, bool $expected): void
    {
        self::assertSame($expected, UserValidator::isValidEmail($email));
    }

    /** @return array<string, array{string, bool}> */
    public static function emailCases(): array
    {
        return [
            'plain'              => ['john.smith@example.com', true],
            'short domain'       => ['a@b.co', true],
            'hyphenated domain'  => ['john@ex-ample.com', true],
            'no domain'          => ['missing@', false],
            'no at sign'         => ['invalid-email', false],
            'double at'          => ['bad@@example.com', false],
            'double domain'      => ['john@example.com@example.com', false],
            'bare host, no TLD'  => ['john@example', false],
            'localhost'          => ['john@localhost', false],
            'leading dot domain' => ['john@.com', false],
        ];
    }

    // --- the nine planted rows of the sample file -------------------------

    public function testLine42InvalidEmail(): void
    {
        $result = $this->check(['invalid', 'email', 'invalid-email'], 42);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Invalid email address format', $result->messages()[0]);
    }

    public function testLine43MissingDomain(): void
    {
        $result = $this->check(['missing', 'domain', 'missing@'], 43);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Invalid email address format', $result->messages()[0]);
    }

    public function testLine44DuplicateNamesTheEarlierLine(): void
    {
        $this->validator->validate(UserRecord::fromFields(['john', 'smith', 'JOHN.SMITH@example.com']), 2, 3);

        $result = $this->check(['duplicate', 'user', 'john.smith@example.com'], 44);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('first seen on line 2', $result->messages()[0]);
    }

    /** Only fails if duplicate detection runs after lowercasing. */
    public function testLine45DuplicateIsCaseInsensitive(): void
    {
        $this->validator->validate(UserRecord::fromFields(['john', 'smith', 'john.smith@example.com']), 2, 3);

        $result = $this->check(['another', 'duplicate', 'JOHN.SMITH@EXAMPLE.COM'], 45);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('first seen on line 2', $result->messages()[0]);
    }

    public function testLine46NameRequired(): void
    {
        $result = $this->check(['', 'noname', 'noname@example.com'], 46);

        self::assertFalse($result->isValid());
        self::assertSame(['Name is required.'], $result->messages());
    }

    public function testLine47SurnameRequired(): void
    {
        $result = $this->check(['noname', '', 'missing.surname@example.com'], 47);

        self::assertFalse($result->isValid());
        self::assertSame(['Surname is required.'], $result->messages());
    }

    public function testLine48EmailRequired(): void
    {
        $result = $this->check(['missing', 'email', ''], 48);

        self::assertFalse($result->isValid());
        self::assertSame(['Email is required.'], $result->messages());
    }

    public function testLine49BadFormat(): void
    {
        $result = $this->check(['bad', 'format', 'bad@@example.com'], 49);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Invalid email address format', $result->messages()[0]);
    }

    /** Only passes if trimming runs before validation. */
    public function testLine50PaddedEmailIsValidAfterTrimming(): void
    {
        $result = $this->check(['spaces', 'test', ' spaces@example.com '], 50);

        self::assertTrue($result->isValid());
        self::assertSame('spaces@example.com', $result->record->email);
    }

    // --- other rules ------------------------------------------------------

    public function testAnEmptyEmailIsReportedAsRequiredNotAsBadFormat(): void
    {
        self::assertSame(['Email is required.'], $this->check(['a', 'b', ''])->messages());
    }

    public function testARowCanCollectSeveralErrors(): void
    {
        $result = $this->check(['', '', 'nope'], 7);

        self::assertCount(3, $result->errors);
        self::assertSame(7, $result->errors[0]->line);
    }

    public function testWrongColumnCountIsReported(): void
    {
        $result = $this->validator->validate(
            UserRecord::fromFields(['john', 'smith', 'a@b.com']),
            9,
            4,
        );

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Expected 3 columns', $result->messages()[0]);
    }

    public function testAnInvalidEmailDoesNotReserveTheDuplicateSlot(): void
    {
        $this->check(['a', 'b', 'invalid-email'], 2);
        $second = $this->check(['c', 'd', 'invalid-email'], 3);

        // Reported as a format problem both times, never as a duplicate.
        self::assertCount(1, $second->errors);
        self::assertStringContainsString('Invalid email address format', $second->messages()[0]);
    }

    public function testResetClearsDuplicateTracking(): void
    {
        $this->check(['a', 'b', 'a@b.com'], 2);
        $this->validator->reset();

        self::assertTrue($this->check(['a', 'b', 'a@b.com'], 2)->isValid());
    }
}
