#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CLI entrypoint for the user import.
 *
 * Lives at the repository root so the command matches the brief exactly:
 *   php user_upload.php --file users.csv
 *
 * Exit codes:
 *   0  success, including a partial import where some rows were rejected
 *   1  fatal error: bad arguments, unreadable file, or a database failure
 */

use App\Cli\Arguments;
use App\Cli\ReportRenderer;
use App\Config\Config;
use App\Csv\CsvException;
use App\Db\Database;
use App\Db\DatabaseException;
use App\Db\PostgresUserRepository;
use App\Db\UserRepositoryInterface;
use App\Import\ImportService;
use App\Import\UserValidator;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("user_upload.php must be run from the command line.\n");
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}

require $autoload;

const EXIT_SUCCESS = 0;
const EXIT_FAILURE = 1;

exit(main($argv));

/** @param list<string> $argv */
function main(array $argv): int
{
    try {
        $arguments = Arguments::parse($argv);
    } catch (InvalidArgumentException $e) {
        // No arguments at all is a usage error, so it also exits non-zero.
        fwrite(STDERR, $e->getMessage() . PHP_EOL . PHP_EOL);
        fwrite(STDERR, Arguments::usage());

        return EXIT_FAILURE;
    }

    if ($arguments->help) {
        fwrite(STDOUT, Arguments::usage());

        return EXIT_SUCCESS;
    }

    $config = Config::load(__DIR__ . '/.env');

    if ($arguments->createTable) {
        $status = createTable($config);

        // --create-table runs standalone; only continue if a file was also given.
        if ($status !== EXIT_SUCCESS || $arguments->file === null) {
            return $status;
        }
    }

    return import($config, $arguments);
}

function createTable(Config $config): int
{
    try {
        repository($config)->createTable();
    } catch (DatabaseException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

        return EXIT_FAILURE;
    }

    fwrite(STDOUT, 'The users table was created (any existing table was dropped).' . PHP_EOL);

    return EXIT_SUCCESS;
}

function import(Config $config, Arguments $arguments): int
{
    // A dry run must still work with no database reachable, so a connection
    // failure downgrades to a validation-only preview instead of aborting.
    try {
        $repository = repository($config);
    } catch (DatabaseException $e) {
        if (!$arguments->dryRun) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return EXIT_FAILURE;
        }

        $repository = null;
    }

    $service = new ImportService(new UserValidator(), $repository);

    try {
        $report = $service->run((string) $arguments->file, $arguments->dryRun);
    } catch (CsvException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

        return EXIT_FAILURE;
    }

    fwrite($report->hasFailed() ? STDERR : STDOUT, (new ReportRenderer())->render($report));

    // A partial import is a success: invalid rows were reported, not fatal.
    return $report->hasFailed() ? EXIT_FAILURE : EXIT_SUCCESS;
}

function repository(Config $config): UserRepositoryInterface
{
    $database = Database::fromConfig($config);
    $database->connect();

    return new PostgresUserRepository($database);
}
