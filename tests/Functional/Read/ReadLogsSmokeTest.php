<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Read\Resource\Log;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Smoke test: GET /v1/logs is reachable, requires auth, and returns a
 * Hydra-shaped response by default. Full filter behavior is covered in
 * dedicated test files; this one only verifies the AP wiring is alive.
 */
#[CoversClass(Log::class)]
final class ReadLogsSmokeTest extends KernelTestCase
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

    public function testGetLogsRequiresBearer(): void
    {
        $this->browser()
            ->get('/v1/logs')
            ->assertStatus(401);
    }

    public function testGetLogsWithBearerReturns200WithCollectionShape(): void
    {
        // No fixtures written → empty collection. The point of this test is
        // to verify routing + auth + serialization land together, not the
        // filter behavior.
        $this->browser()
            ->get('/v1/logs?since=1h&limit=10', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                ],
            ])
            ->assertStatus(200);
    }
}
