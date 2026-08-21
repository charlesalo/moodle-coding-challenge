<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Config;
use App\Db\Database;
use App\Db\DatabaseException;
use App\Db\PostgresUserRepository;
use App\Db\UserRepositoryInterface;
use App\Import\ImportService;
use App\Import\UserValidator;

/**
 * Wiring shared by the two API endpoints.
 */
final class Bootstrap
{
    public const ROOT = __DIR__ . '/../..';

    public static function config(): Config
    {
        return Config::load(self::ROOT . '/.env');
    }

    public static function uploads(): UploadStore
    {
        return new UploadStore(self::ROOT . '/storage/tmp');
    }

    /**
     * @throws DatabaseException when the database is unreachable
     */
    public static function repository(): UserRepositoryInterface
    {
        $database = Database::fromConfig(self::config());
        $database->connect();

        return new PostgresUserRepository($database);
    }

    public static function importService(?UserRepositoryInterface $repository): ImportService
    {
        return new ImportService(new UserValidator(), $repository);
    }

    /**
     * Turn any uncaught throwable into a JSON error instead of an HTML stack
     * trace, so nothing internal is ever rendered in the browser.
     */
    public static function guardAgainstUncaughtErrors(): void
    {
        set_exception_handler(static function (\Throwable $e): void {
            error_log((string) $e);
            Json::error('An unexpected server error occurred.', 500);
        });
    }
}
