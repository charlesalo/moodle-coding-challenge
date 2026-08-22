<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        $this->envFile = tempnam(sys_get_temp_dir(), 'env');
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
        putenv('DB_HOST');
        putenv('APP_SAMPLE');
    }

    public function testFallsBackToDefaultsWhenNothingIsConfigured(): void
    {
        $config = new Config();

        self::assertSame([
            'host' => 'localhost',
            'port' => '5432',
            'name' => 'user_import',
            'user' => 'postgres',
            'pass' => '',
        ], $config->database());
    }

    public function testReadsValuesFromEnvFile(): void
    {
        file_put_contents($this->envFile, "DB_HOST=db.internal\nDB_PORT=6543\nDB_NAME=imports\n");

        $config = Config::load($this->envFile);

        self::assertSame('db.internal', $config->database()['host']);
        self::assertSame('6543', $config->database()['port']);
        self::assertSame('imports', $config->database()['name']);
    }

    public function testEnvironmentVariableOverridesEnvFile(): void
    {
        file_put_contents($this->envFile, "DB_HOST=from-file\n");
        putenv('DB_HOST=from-environment');

        $config = Config::load($this->envFile);

        self::assertSame('from-environment', $config->database()['host']);
    }

    /**
     * An exported-but-empty variable is still set, so it must win. Otherwise
     * deliberately blanking DB_PASS silently picks up a stale .env value.
     */
    public function testEmptyEnvironmentVariableStillOverridesEnvFile(): void
    {
        file_put_contents($this->envFile, "DB_HOST=from-file\n");
        putenv('DB_HOST=');

        self::assertSame('', Config::load($this->envFile)->database()['host']);
    }

    public function testUnsetVariableFallsBackToEnvFile(): void
    {
        file_put_contents($this->envFile, "DB_HOST=from-file\n");
        putenv('DB_HOST');

        self::assertSame('from-file', Config::load($this->envFile)->database()['host']);
    }

    public function testWorksWithNoEnvFilePresent(): void
    {
        $config = Config::load('/nonexistent/.env');

        self::assertSame('localhost', $config->database()['host']);
    }

    public function testIgnoresCommentsAndBlankLinesAndStripsQuotes(): void
    {
        file_put_contents($this->envFile, <<<ENV
            # a comment
            
            DB_PASS="s3cret"
            APP_SAMPLE='quoted'
            NOT_A_PAIR
            ENV);

        $config = Config::load($this->envFile);

        self::assertSame('s3cret', $config->database()['pass']);
        self::assertSame('quoted', $config->get('APP_SAMPLE'));
        self::assertNull($config->get('NOT_A_PAIR'));
    }
}
