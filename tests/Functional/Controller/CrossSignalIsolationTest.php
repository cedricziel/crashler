<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Support\TempStorageRoot;
use Flow\Parquet\Reader;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Cross-signal sanity: a single tenant posting both `/v1/logs` and `/v1/traces`
 * in the same process must yield files under their own top-level subdirs and
 * carry the right `_schema_id` writer marker.
 */
final class CrossSignalIsolationTest extends KernelTestCase
{
    use HasBrowser;
    use TempStorageRoot;

    private const string VALID_TOKEN = 'cw_test_token_aaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    private function logsPayload(): string
    {
        return (string) json_encode([
            'resourceLogs' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                ]],
                'scopeLogs' => [[
                    'scope' => ['name' => 'app', 'version' => '1.0'],
                    'logRecords' => [[
                        'timeUnixNano' => '1714752000000000000',
                        'severityNumber' => 9,
                        'severityText' => 'INFO',
                        'body' => ['stringValue' => 'hello'],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    private function tracesPayload(): string
    {
        return (string) json_encode([
            'resourceSpans' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                ]],
                'scopeSpans' => [[
                    'scope' => ['name' => 'app', 'version' => '1.0'],
                    'spans' => [[
                        'traceId' => '5b8aa5a2d2c872e8321cf37308d69df2',
                        'spanId' => '051581bf3cb55c13',
                        'name' => 'GET /orders/:id',
                        'kind' => 2,
                        'startTimeUnixNano' => '1714752000000000000',
                        'endTimeUnixNano' => '1714752000050000000',
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    public function testLogsAndTracesWriteIntoSeparateTopLevelDirectories(): void
    {
        $this->browser()
            ->post('/v1/logs', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => $this->logsPayload(),
            ])
            ->assertStatus(200);

        $this->browser()
            ->post('/v1/traces', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => $this->tracesPayload(),
            ])
            ->assertStatus(200);

        $logFiles = glob($this->tempStorageRoot().'/logs/test-tenant/date=*/hour=*/part-*.parquet') ?: [];
        $traceFiles = glob($this->tempStorageRoot().'/traces/test-tenant/date=*/hour=*/part-*.parquet') ?: [];

        self::assertCount(1, $logFiles, 'logs ingest should produce exactly one file under logs/');
        self::assertCount(1, $traceFiles, 'traces ingest should produce exactly one file under traces/');

        // Cross-pollination guard: the traces tree must contain no logs files
        // (they would have logs-shaped columns and break readers).
        self::assertSame(
            [],
            glob($this->tempStorageRoot().'/traces/**/logs-*') ?: [],
            'no log-shaped artefact may leak into the traces tree',
        );
        self::assertSame(
            [],
            glob($this->tempStorageRoot().'/logs/**/traces-*') ?: [],
            'no trace-shaped artefact may leak into the logs tree',
        );
    }

    public function testEachSignalCarriesItsOwnSchemaIdRowMarker(): void
    {
        $this->browser()
            ->post('/v1/logs', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => $this->logsPayload(),
            ])
            ->assertStatus(200);

        $this->browser()
            ->post('/v1/traces', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => $this->tracesPayload(),
            ])
            ->assertStatus(200);

        $logFile = (glob($this->tempStorageRoot().'/logs/test-tenant/date=*/hour=*/part-*.parquet') ?: [])[0] ?? null;
        $traceFile = (glob($this->tempStorageRoot().'/traces/test-tenant/date=*/hour=*/part-*.parquet') ?: [])[0] ?? null;

        TestCase::assertNotNull($logFile);
        TestCase::assertNotNull($traceFile);

        $logRows = iterator_to_array((new Reader())->read($logFile)->values(), false);
        $traceRows = iterator_to_array((new Reader())->read($traceFile)->values(), false);

        self::assertNotEmpty($logRows);
        self::assertNotEmpty($traceRows);
        self::assertSame('logs/v1', $logRows[0]['_schema_id']);
        self::assertSame('traces/v1', $traceRows[0]['_schema_id']);
    }
}
