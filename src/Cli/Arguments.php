<?php

declare(strict_types=1);

namespace App\Cli;

use InvalidArgumentException;

/**
 * Parsed and checked command-line options.
 */
final class Arguments
{
    private const OPTIONS = ['file:', 'dry-run', 'create-table', 'help'];

    public function __construct(
        public readonly ?string $file,
        public readonly bool $dryRun,
        public readonly bool $createTable,
        public readonly bool $help,
    ) {
    }

    /**
     * @param  list<string> $argv the full argv, including the script name
     * @throws InvalidArgumentException on unknown or malformed arguments
     */
    public static function parse(array $argv): self
    {
        $given = array_slice($argv, 1);

        if ($given === []) {
            throw new InvalidArgumentException('No arguments given.');
        }

        // getopt() reads the real argv, so validate the tokens we were handed
        // ourselves. This also lets the CLI be tested without a subprocess.
        $parsed = self::tokenise($given);

        $file = $parsed['file'] ?? null;
        if (array_key_exists('file', $parsed) && ($file === null || trim($file) === '')) {
            throw new InvalidArgumentException('--file requires a filename, for example --file users.csv');
        }

        $arguments = new self(
            $file,
            array_key_exists('dry-run', $parsed),
            array_key_exists('create-table', $parsed),
            array_key_exists('help', $parsed),
        );

        if (!$arguments->help && !$arguments->createTable && $arguments->file === null) {
            throw new InvalidArgumentException('--file is required unless you pass --create-table or --help.');
        }

        return $arguments;
    }

    /**
     * @param  list<string> $given
     * @return array<string, string|null>
     */
    private static function tokenise(array $given): array
    {
        $valueOptions = [];
        $flagOptions  = [];
        foreach (self::OPTIONS as $option) {
            if (str_ends_with($option, ':')) {
                $valueOptions[] = rtrim($option, ':');
            } else {
                $flagOptions[] = $option;
            }
        }

        $parsed = [];
        for ($i = 0, $count = count($given); $i < $count; $i++) {
            $token = $given[$i];

            if (!str_starts_with($token, '--')) {
                throw new InvalidArgumentException(sprintf('Unexpected argument "%s".', $token));
            }

            $name  = substr($token, 2);
            $value = null;

            // Support both --file=users.csv and --file users.csv.
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (in_array($name, $valueOptions, true)) {
                if ($value === null) {
                    $next = $given[$i + 1] ?? null;
                    if ($next === null || str_starts_with($next, '--')) {
                        throw new InvalidArgumentException(
                            sprintf('--%s requires a filename, for example --%s users.csv', $name, $name),
                        );
                    }
                    $value = $next;
                    $i++;
                }

                $parsed[$name] = $value;
                continue;
            }

            if (in_array($name, $flagOptions, true)) {
                if ($value !== null) {
                    throw new InvalidArgumentException(sprintf('--%s does not take a value.', $name));
                }

                $parsed[$name] = null;
                continue;
            }

            throw new InvalidArgumentException(sprintf('Unknown option "--%s".', $name));
        }

        return $parsed;
    }

    public static function usage(): string
    {
        return <<<TXT
        Import users into PostgreSQL from a CSV file.

        Usage:
          php user_upload.php --file <filename> [--dry-run]
          php user_upload.php --create-table
          php user_upload.php --help

        Options:
          --file <filename>    CSV file to process
          --dry-run            Parse and validate without importing
          --create-table       Create/rebuild the users table, then exit
          --help               Display available options

        The CSV must be comma-delimited, UTF-8, and start with a
        "name,surname,email" header row.

        Exit codes:
          0  success, including a partial import where some rows were rejected
          1  fatal error: bad arguments, unreadable file, or a database failure

        TXT;
    }
}
