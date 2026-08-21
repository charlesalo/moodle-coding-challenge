<?php

declare(strict_types=1);

namespace App\Tests\Doubles;

use App\Db\DatabaseException;
use App\Db\UserRepositoryInterface;
use App\Import\UserRecord;

/**
 * In-memory repository so the import tests never need a live database.
 */
final class FakeUserRepository implements UserRepositoryInterface
{
    /** @var list<UserRecord> */
    public array $inserted = [];

    public int $insertCalls = 0;

    public int $createTableCalls = 0;

    /** @param list<string> $existingEmails addresses pretended to be already stored */
    public function __construct(
        private array $existingEmails = [],
        private readonly ?DatabaseException $failWith = null,
    ) {
    }

    public function createTable(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->createTableCalls++;
    }

    public function findExistingEmails(array $emails): array
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return array_values(array_intersect($this->existingEmails, $emails));
    }

    public function insertMany(array $records): array
    {
        $this->insertCalls++;

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $inserted = 0;
        $skipped  = 0;

        foreach ($records as $record) {
            // Mirrors ON CONFLICT (email) DO NOTHING.
            if (in_array($record->email, $this->existingEmails, true)) {
                $skipped++;
                continue;
            }

            $this->existingEmails[] = $record->email;
            $this->inserted[]       = $record;
            $inserted++;
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }
}
