<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Json;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    private function capture(mixed $payload): string
    {
        ob_start();
        Json::send($payload);

        return (string) ob_get_clean();
    }

    public function testEncodesAPlainPayload(): void
    {
        self::assertSame('{"found":49,"valid":41}', $this->capture(['found' => 49, 'valid' => 41]));
    }

    /**
     * An uploaded filename is echoed back inside error messages and is not
     * guaranteed to be valid UTF-8. Encoding must not collapse to an empty
     * body, which the client cannot distinguish from a working response.
     */
    public function testInvalidUtf8DoesNotProduceAnEmptyBody(): void
    {
        $output = $this->capture(['error' => "CSV file is empty: \xB1\x31\x9F.csv"]);

        self::assertNotSame('', $output);
        self::assertJson($output);

        $decoded = json_decode($output, true);
        self::assertArrayHasKey('error', $decoded);
        self::assertStringContainsString('CSV file is empty', $decoded['error']);
    }

    public function testInvalidUtf8InNestedDataStillEncodes(): void
    {
        $output = $this->capture(['records' => [['name' => "Jos\xE9"]]]);

        self::assertNotSame('', $output);
        self::assertJson($output);
    }

    public function testUnencodablePayloadFallsBackToAnErrorBody(): void
    {
        // INF cannot be represented in JSON and is not covered by the
        // UTF-8 substitution flag.
        $output = $this->capture(['value' => INF]);

        self::assertNotSame('', $output);
        self::assertJson($output);
        self::assertArrayHasKey('error', json_decode($output, true));
    }

    public function testSlashesAndUnicodeAreLeftReadable(): void
    {
        $output = $this->capture(['path' => 'a/b', 'name' => 'José']);

        self::assertStringContainsString('a/b', $output);
        self::assertStringContainsString('José', $output);
    }
}
