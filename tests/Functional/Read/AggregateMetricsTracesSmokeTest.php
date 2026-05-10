<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Tests\Support\TempStorageRoot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Smoke tests for the metrics/traces aggregate endpoints. The full happy
 * path is exercised by AggregateLogsTest (the abstract handle() lives on
 * AggregateController and is shared); these tests pin the per-signal
 * subclass routing and the per-signal allowed-column lists by hitting
 * each error path that exercises the controller without requiring a
 * full parquet seed.
 */
final class AggregateMetricsTracesSmokeTest extends KernelTestCase
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

    public function testMetricsAggregateRejectsMissingFunction(): void
    {
        $this->browser()
            ->get('/v1/metrics/aggregate?since=1h', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    public function testMetricsAggregateRejectsSumWithoutColumn(): void
    {
        $this->browser()
            ->get('/v1/metrics/aggregate?since=1h&function=sum', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    public function testMetricsAggregateGroupByOnDisallowedColumnRejected(): void
    {
        $this->browser()
            ->get('/v1/metrics/aggregate?since=1h&function=count&groupBy=resourceAttributesJson', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    public function testMetricsAggregateCountReturnsZeroWhenNoData(): void
    {
        // Empty storage dir → handle() returns count=0 with no rows.
        $this->browser()
            ->get('/v1/metrics/aggregate?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&function=count', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);
    }

    public function testTracesAggregateRejectsMissingFunction(): void
    {
        $this->browser()
            ->get('/v1/traces/aggregate?since=1h', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    public function testTracesAggregateRejectsSumOnDisallowedColumn(): void
    {
        $this->browser()
            ->get('/v1/traces/aggregate?since=1h&function=sum&column=resource_service_name', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    public function testTracesAggregateCountReturnsZeroWhenNoData(): void
    {
        $this->browser()
            ->get('/v1/traces/aggregate?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&function=count', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);
    }

    public function testTracesAggregateGroupByOnJsonColumnRejected(): void
    {
        $this->browser()
            ->get('/v1/traces/aggregate?since=1h&function=count&groupBy=resourceAttributesJson', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }
}
