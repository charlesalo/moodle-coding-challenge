<?php

declare(strict_types=1);

namespace App\Tests\Cli;

use App\Cli\Arguments;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArgumentsTest extends TestCase
{
    /** @param list<string> $args */
    private function parse(array $args): Arguments
    {
        return Arguments::parse(['user_upload.php', ...$args]);
    }

    public function testParsesFile(): void
    {
        $arguments = $this->parse(['--file', 'users.csv']);

        self::assertSame('users.csv', $arguments->file);
        self::assertFalse($arguments->dryRun);
        self::assertFalse($arguments->createTable);
        self::assertFalse($arguments->help);
    }

    public function testParsesFileWithEqualsForm(): void
    {
        self::assertSame('users.csv', $this->parse(['--file=users.csv'])->file);
    }

    public function testParsesDryRun(): void
    {
        $arguments = $this->parse(['--file', 'users.csv', '--dry-run']);

        self::assertTrue($arguments->dryRun);
        self::assertSame('users.csv', $arguments->file);
    }

    public function testCreateTableDoesNotRequireAFile(): void
    {
        $arguments = $this->parse(['--create-table']);

        self::assertTrue($arguments->createTable);
        self::assertNull($arguments->file);
    }

    public function testCreateTableCanBeCombinedWithAFile(): void
    {
        $arguments = $this->parse(['--create-table', '--file', 'users.csv']);

        self::assertTrue($arguments->createTable);
        self::assertSame('users.csv', $arguments->file);
    }

    /** Regression: flags stored as null must still be detected. */
    public function testHelpIsDetectedAndNeedsNoFile(): void
    {
        $arguments = $this->parse(['--help']);

        self::assertTrue($arguments->help);
        self::assertNull($arguments->file);
    }

    public function testOptionOrderDoesNotMatter(): void
    {
        $arguments = $this->parse(['--dry-run', '--file', 'users.csv']);

        self::assertTrue($arguments->dryRun);
        self::assertSame('users.csv', $arguments->file);
    }

    #[DataProvider('invalidInvocations')]
    public function testRejectsInvalidInvocations(array $args, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->parse($args);
    }

    /** @return array<string, array{list<string>, string}> */
    public static function invalidInvocations(): array
    {
        return [
            'no arguments'         => [[], 'No arguments given.'],
            'file with no value'   => [['--file'], '--file requires a filename'],
            'file followed by flag'=> [['--file', '--dry-run'], '--file requires a filename'],
            'empty file value'     => [['--file='], '--file requires a filename'],
            'unknown option'       => [['--nope'], 'Unknown option "--nope"'],
            'bare argument'        => [['users.csv'], 'Unexpected argument "users.csv"'],
            'flag given a value'   => [['--dry-run=yes'], '--dry-run does not take a value'],
            'dry run with no file' => [['--dry-run'], '--file is required'],
            // --create-table drops the table; --dry-run promises not to write.
            'create-table with dry-run' => [
                ['--create-table', '--dry-run'],
                '--create-table cannot be combined with --dry-run',
            ],
            'create-table, file and dry-run' => [
                ['--create-table', '--file', 'users.csv', '--dry-run'],
                '--create-table cannot be combined with --dry-run',
            ],
        ];
    }

    public function testUsageMentionsEveryOptionAndExitCodes(): void
    {
        $usage = Arguments::usage();

        foreach (['--file', '--dry-run', '--create-table', '--help'] as $option) {
            self::assertStringContainsString($option, $usage);
        }

        self::assertStringContainsString('Exit codes:', $usage);
    }
}
