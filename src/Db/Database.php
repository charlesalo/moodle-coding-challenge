<?php

declare(strict_types=1);

namespace App\Db;

use App\Config\Config;
use PDO;
use PDOException;

/**
 * Builds PDO connections from injected configuration.
 *
 * Connection details come from Config, never from literals in the source.
 */
final class Database
{
    private ?PDO $connection = null;

    /** @param array{host: string, port: string, name: string, user: string, pass: string} $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->database());
    }

    /**
     * @throws DatabaseException when the connection cannot be established.
     */
    public function connect(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new DatabaseException(
                'The pdo_pgsql extension is not enabled in this PHP build. '
                . 'Enable it in php.ini and restart the web server.'
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $this->settings['host'],
            $this->settings['port'],
            $this->settings['name'],
        );

        try {
            $this->connection = new PDO($dsn, $this->settings['user'], $this->settings['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Report where we tried to connect, but never the password.
            throw new DatabaseException(sprintf(
                'Could not connect to PostgreSQL at %s:%s (database "%s", user "%s"): %s',
                $this->settings['host'],
                $this->settings['port'],
                $this->settings['name'],
                $this->settings['user'],
                $e->getMessage(),
            ), 0, $e);
        }

        return $this->connection;
    }
}
