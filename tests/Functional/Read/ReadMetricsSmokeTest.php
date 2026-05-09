<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Read\Resource\Metric;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(Metric::class)]
final class ReadMetricsSmokeTest extends KernelTestCase
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

    public function testGetMetricsRequiresBearer(): void
    {
        $this->browser()->get('/v1/metrics')->assertStatus(401);
    }

    public function testGetMetricsWithBearerReturns200(): void
    {
        $this->browser()
            ->get('/v1/metrics?since=1h&limit=10', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);
    }

    public function testMetricTypeEnumValidationRejectsBanana(): void
    {
        // AP's QueryParameter `enum` validation runs first → 422.
        $this->browser()
            ->get('/v1/metrics?since=1h&metricType=BANANA', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(422);
    }

    public function testMetricNameWildcardRejected(): void
    {
        // No `enum` constraint on metricName — state-provider-level
        // BadRequestException returns 400 (the spec-aligned status).
        $this->browser()
            ->get('/v1/metrics?since=1h&metricName=http.*', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }
}
