<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Reads configuration from the environment, falling back to a .env file.
 *
 * Environment variables win so the application can run in CI, or under a
 * process manager, with no .env file present.
 */
final class Config
{
    /** @var array<string, string> */
    private array $values;

    /** @param array<string, string> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /**
     * Build configuration from the process environment plus an optional .env file.
     */
    public static function load(?string $envFile = null): self
    {
        $values = $envFile !== null && is_readable($envFile)
            ? self::parseEnvFile($envFile)
            : [];

        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        // getenv() first: a real environment variable overrides the .env file.
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }

        return $this->values[$key] ?? $default;
    }

    /**
     * Database settings, with defaults matching .env.example.
     *
     * @return array{host: string, port: string, name: string, user: string, pass: string}
     */
    public function database(): array
    {
        return [
            'host' => $this->get('DB_HOST', 'localhost') ?? 'localhost',
            'port' => $this->get('DB_PORT', '5432') ?? '5432',
            'name' => $this->get('DB_NAME', 'user_import') ?? 'user_import',
            'user' => $this->get('DB_USER', 'postgres') ?? 'postgres',
            'pass' => $this->get('DB_PASS', '') ?? '',
        ];
    }

    /**
     * Minimal .env parser: KEY=VALUE per line, # comments, optional quotes.
     *
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // Strip a single layer of matching quotes.
            if (strlen($value) >= 2) {
                $first = $value[0];
                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                }
            }

            $values[$key] = $value;
        }

        return $values;
    }
}
