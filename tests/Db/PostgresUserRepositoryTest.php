<?php

declare(strict_types=1);

namespace App\Tests\Db;

use App\Db\Database;
use App\Db\DatabaseException;
use App\Db\PostgresUserRepository;
use App\Import\UserRecord;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real SQL against a live PostgreSQL instance.
 *
 * Skipped automatically when no database is reachable, so the rest of the
 * suite stays runnable with no infrastructure. Uses its own database
 * (DB_TEST_NAME, default user_import_test) because createTable() drops the
 * table it rebuilds.
 */
#[Group('integration')]
final class PostgresUserRepositoryTest extends TestCase
{
    private PostgresUserRepository $repository;
    private Database $database;

    protected function setUp(): void
    {
        $this->database = new Database([
            'host' => getenv('DB_HOST') ?: 'localhost',
            'port' => getenv('DB_PORT') ?: '5432',
            'name' => getenv('DB_TEST_NAME') ?: 'user_import_test',
            'user' => getenv('DB_USER') ?: 'postgres',
            'pass' => getenv('DB_PASS') ?: '',
        ]);

        try {
            $this->database->connect();
        } catch (DatabaseException $e) {
            self::markTestSkipped('No PostgreSQL available: ' . $e->getMessage());
        }

        $this->repository = new PostgresUserRepository($this->database);
        $this->repository->createTable();
    }

    private function rowCount(): int
    {
        return (int) $this->database->connect()
            ->query('SELECT COUNT(*) FROM users')
            ->fetchColumn();
    }

    public function testCreateTableRebuildsACleanTable(): void
    {
        $this->repository->insertMany([new UserRecord('John', 'Smith', 'john@example.com')]);
        self::assertSame(1, $this->rowCount());

        // The brief asks for create *or rebuild*: a second call must not no-op.
        $this->repository->createTable();

        self::assertSame(0, $this->rowCount());
    }

    public function testInsertManyStoresRecords(): void
    {
        $outcome = $this->repository->insertMany([
            new UserRecord('John', 'Smith', 'john@example.com'),
            new UserRecord('Jane', 'Doe', 'jane@example.com'),
        ]);

        self::assertSame(['inserted' => 2, 'skipped' => 0], $outcome);
        self::assertSame(2, $this->rowCount());
    }

    public function testOnConflictSkipsAnExistingEmailInsteadOfFailing(): void
    {
        $this->repository->insertMany([new UserRecord('John', 'Smith', 'john@example.com')]);

        $outcome = $this->repository->insertMany([
            new UserRecord('Johnny', 'Smythe', 'john@example.com'),
            new UserRecord('Jane', 'Doe', 'jane@example.com'),
        ]);

        self::assertSame(['inserted' => 1, 'skipped' => 1], $outcome);
        self::assertSame(2, $this->rowCount());
    }

    public function testDuplicatesWithinASingleBatchAreSkippedNotFatal(): void
    {
        $outcome = $this->repository->insertMany([
            new UserRecord('John', 'Smith', 'john@example.com'),
            new UserRecord('Johnny', 'Smythe', 'john@example.com'),
        ]);

        self::assertSame(['inserted' => 1, 'skipped' => 1], $outcome);
    }

    public function testFindExistingEmails(): void
    {
        $this->repository->insertMany([
            new UserRecord('John', 'Smith', 'john@example.com'),
            new UserRecord('Jane', 'Doe', 'jane@example.com'),
        ]);

        $found = $this->repository->findExistingEmails(['john@example.com', 'nobody@example.com']);

        self::assertSame(['john@example.com'], $found);
    }

    /**
     * PostgreSQL caps a prepared statement at 65535 bind parameters, so the
     * lookup is chunked. Exercised with more addresses than one chunk holds.
     */
    public function testFindExistingEmailsHandlesMoreAddressesThanOneChunk(): void
    {
        $records = [];
        for ($i = 0; $i < 2500; $i++) {
            $records[] = new UserRecord('User', (string) $i, "user{$i}@example.com");
        }
        $this->repository->insertMany($records);

        // Ask about every stored address plus as many that are not stored.
        $lookup = array_map(static fn (int $i): string => "user{$i}@example.com", range(0, 4999));

        $found = $this->repository->findExistingEmails($lookup);

        self::assertCount(2500, $found);
        self::assertContains('user0@example.com', $found);
        self::assertContains('user2499@example.com', $found);
        self::assertNotContains('user2500@example.com', $found);
    }

    public function testFindExistingEmailsWithNoInputDoesNotQuery(): void
    {
        self::assertSame([], $this->repository->findExistingEmails([]));
    }

    public function testInsertManyWithNoRecordsIsANoOp(): void
    {
        self::assertSame(['inserted' => 0, 'skipped' => 0], $this->repository->insertMany([]));
    }

    public function testEmailUniquenessIsEnforcedByTheDatabase(): void
    {
        $pdo = $this->database->connect();
        $pdo->exec("INSERT INTO users (name, surname, email) VALUES ('A', 'B', 'dup@example.com')");

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO users (name, surname, email) VALUES ('C', 'D', 'dup@example.com')");
    }

    public function testConnectionFailureRaisesAReadableDatabaseException(): void
    {
        $broken = new Database([
            'host' => '127.0.0.1', 'port' => '1', 'name' => 'nope',
            'user' => 'nobody', 'pass' => 'secret-password',
        ]);

        try {
            $broken->connect();
            self::fail('Expected a DatabaseException.');
        } catch (DatabaseException $e) {
            self::assertStringNotContainsString('secret-password', $e->getMessage());
            self::assertStringContainsString('Could not connect to PostgreSQL', $e->getMessage());
        }
    }
}
