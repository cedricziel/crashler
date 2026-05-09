<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Read\Resource\Trace;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(Trace::class)]
final class ReadTracesSmokeTest extends KernelTestCase
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

    public function testGetTracesRequiresBearer(): void
    {
        $this->browser()->get('/v1/traces')->assertStatus(401);
    }

    public function testGetTracesWithBearerReturns200(): void
    {
        $this->browser()
            ->get('/v1/traces?since=1h&limit=10', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);
    }

    public function testKindEnumValidationRejectsBanana(): void
    {
        // AP's QueryParameter schema validation returns 422 for enum
        // mismatches (its convention). Our state-provider-level checks
        // would return 400, but AP runs first.
        $this->browser()
            ->get('/v1/traces?since=1h&kind=BANANA', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(422);
    }
}
