<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\UploadStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UploadStoreTest extends TestCase
{
    private string $directory;
    private UploadStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/upload-store-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
        $this->store = new UploadStore($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function storeSample(string $contents = "name,surname,email\njohn,smith,a@b.com\n"): string
    {
        $source = tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($source, $contents);

        // isUpload: false because there is no real HTTP upload under test.
        $token = $this->store->store($source, isUpload: false);
        @unlink($source);

        return $token;
    }

    public function testStoreReturnsAHexTokenAndKeepsTheContents(): void
    {
        $token = $this->storeSample();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        self::assertFileExists($this->store->pathForToken($token));
        self::assertStringContainsString('john,smith', file_get_contents($this->store->pathForToken($token)));
    }

    public function testTokensAreUniquePerUpload(): void
    {
        self::assertNotSame($this->storeSample(), $this->storeSample());
    }

    /**
     * A token is only ever used to build a filename after matching a strict
     * hex pattern, so traversal attempts are rejected outright.
     */
    #[DataProvider('unsafeTokens')]
    public function testRejectsUnsafeTokens(string $token): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid upload token.');

        $this->store->pathForToken($token);
    }

    /** @return array<string, array{string}> */
    public static function unsafeTokens(): array
    {
        return [
            'traversal'        => ['../../../../etc/passwd'],
            'traversal in hex' => ['../' . str_repeat('a', 29)],
            'absolute path'    => ['/etc/passwd'],
            'null byte'        => ["abcdef0123456789abcdef0123456789\0"],
            'wrong length'     => ['abc'],
            'uppercase hex'    => ['ABCDEF0123456789ABCDEF0123456789'],
            'not hex'          => ['zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'],
            'empty'            => [''],
        ];
    }

    public function testUnknownTokenIsReportedAsExpired(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer available');

        $this->store->pathForToken(str_repeat('a', 32));
    }

    public function testDeleteRemovesTheStoredFile(): void
    {
        $token = $this->storeSample();
        $path  = $this->store->pathForToken($token);

        $this->store->delete($token);

        self::assertFileDoesNotExist($path);
    }

    public function testDeleteIgnoresAnUnsafeToken(): void
    {
        $this->expectNotToPerformAssertions();

        $this->store->delete('../../etc/passwd');
    }

    public function testSweepRemovesFilesOlderThanAnHour(): void
    {
        $stale  = $this->storeSample();
        $fresh  = $this->storeSample();
        $stalePath = $this->store->pathForToken($stale);

        touch($stalePath, time() - 3601);

        $this->store->sweep();

        self::assertFileDoesNotExist($stalePath);
        self::assertFileExists($this->store->pathForToken($fresh));
    }

    public function testStoreRejectsAPathThatIsNotAnUpload(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'src');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('not received as an upload');
            $this->store->store($source, isUpload: true);
        } finally {
            @unlink($source);
        }
    }

    /**
     * This message is rendered straight into a JSON error response, so it must
     * not disclose where the application is installed.
     */
    public function testUnwritableDirectoryErrorDoesNotLeakTheServerPath(): void
    {
        chmod($this->directory, 0500);

        if (is_writable($this->directory)) {
            chmod($this->directory, 0775);
            self::markTestSkipped('Cannot make a directory unwritable as this user.');
        }

        $source = tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($source, "name,surname,email\n");

        try {
            $this->store->store($source, isUpload: false);
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString($this->directory, $e->getMessage());
            self::assertStringNotContainsString('/', $e->getMessage());
        } finally {
            chmod($this->directory, 0775);
            @unlink($source);
        }
    }

    public function testCreatesTheDirectoryWhenMissing(): void
    {
        $nested = $this->directory . '/nested/deeper';
        $store  = new UploadStore($nested);

        $source = tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($source, "name,surname,email\n");
        $token = $store->store($source, isUpload: false);
        @unlink($source);

        self::assertFileExists($store->pathForToken($token));

        @unlink($store->pathForToken($token));
        @rmdir($nested);
        @rmdir($this->directory . '/nested');
    }
}
