<?php

declare(strict_types=1);

namespace App\Db;

use App\Import\UserRecord;

/**
 * Persistence boundary for imported users.
 *
 * ImportService depends on this rather than on PDO, so the tests can inject a
 * fake and never need a live database.
 */
interface UserRepositoryInterface
{
    /**
     * Create or rebuild the users table.
     *
     * @throws DatabaseException
     */
    public function createTable(): void;

    /**
     * Emails from the given list that already exist in the database.
     *
     * Used to report conflicts during a dry run without writing anything.
     *
     * @param  list<string>   $emails
     * @return list<string>   the subset already present, lowercased
     * @throws DatabaseException
     */
    public function findExistingEmails(array $emails): array;

    /**
     * Insert the given records inside a single transaction.
     *
     * Uses INSERT ... ON CONFLICT (email) DO NOTHING, so rows that already
     * exist are skipped by the database rather than by a check-then-insert
     * gap that a concurrent import could slip through.
     *
     * @param  list<UserRecord> $records
     * @return array{inserted: int, skipped: int}
     * @throws DatabaseException
     */
    public function insertMany(array $records): array;
}
