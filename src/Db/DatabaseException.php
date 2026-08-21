<?php

declare(strict_types=1);

namespace App\Db;

use RuntimeException;

/**
 * A database failure already translated into something safe to show a user.
 *
 * Raw PDOException messages can carry connection strings and credentials, so
 * they are wrapped here rather than surfaced to the terminal or the browser.
 */
final class DatabaseException extends RuntimeException
{
}
