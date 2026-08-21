<?php

declare(strict_types=1);

namespace App\Db;

use App\Import\UserRecord;
use PDO;
use PDOException;

/**
 * PostgreSQL persistence for imported users.
 *
 * Every PDOException is translated into a DatabaseException carrying a
 * message that is safe to print, so no stack trace or connection detail
 * reaches the terminal or the browser.
 */
final class PostgresUserRepository implements UserRepositoryInterface
{
    private const SCHEMA_FILE = __DIR__ . '/../../sql/schema.sql';

    public function __construct(private readonly Database $database)
    {
    }

    public function createTable(): void
    {
        $sql = @file_get_contents(self::SCHEMA_FILE);
        if ($sql === false) {
            throw new DatabaseException('Could not read sql/schema.sql.');
        }

        try {
            $this->database->connect()->exec($sql);
        } catch (PDOException $e) {
            throw new DatabaseException('Could not create the users table: ' . $e->getMessage(), 0, $e);
        }
    }

    public function findExistingEmails(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($emails), '?'));

        try {
            $statement = $this->database->connect()->prepare(
                "SELECT email FROM users WHERE email IN ($placeholders)"
            );
            $statement->execute(array_values($emails));

            /** @var list<string> $found */
            $found = $statement->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            throw new DatabaseException(
                'Could not check existing email addresses: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return $found;
    }

    public function insertMany(array $records): array
    {
        if ($records === []) {
            return ['inserted' => 0, 'skipped' => 0];
        }

        $pdo = $this->database->connect();

        try {
            $pdo->beginTransaction();

            // ON CONFLICT lets the database settle races and pre-existing rows,
            // rather than a check-then-insert gap a concurrent import could slip
            // through. RETURNING id tells us whether the row was actually written.
            $statement = $pdo->prepare(
                'INSERT INTO users (name, surname, email)
                 VALUES (:name, :surname, :email)
                 ON CONFLICT (email) DO NOTHING
                 RETURNING id'
            );

            $inserted = 0;
            foreach ($records as $record) {
                $statement->execute([
                    ':name'    => $record->name,
                    ':surname' => $record->surname,
                    ':email'   => $record->email,
                ]);

                if ($statement->fetchColumn() !== false) {
                    $inserted++;
                }

                $statement->closeCursor();
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new DatabaseException('Import failed: ' . $e->getMessage(), 0, $e);
        }

        return ['inserted' => $inserted, 'skipped' => count($records) - $inserted];
    }
}
