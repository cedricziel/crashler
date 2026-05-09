<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Read\Controller\ReadSpanController;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(ReadSpanController::class)]
final class ReadSpanByIdTest extends KernelTestCase
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

    public function testSpanByIdRequiresBearer(): void
    {
        $this->browser()
            ->get('/v1/spans/051581bf3cb55c13')
            ->assertStatus(401);
    }

    public function testSpanByIdMalformedHexRejected(): void
    {
        $this->browser()
            ->get('/v1/spans/zzzz')
            ->assertStatus(404);
    }

    public function testSpanByIdNotFoundReturns404(): void
    {
        $response = $this->browser()
            ->get('/v1/spans/0123456789abcdef', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(404);

        $message = $response->json()->decoded()['message'];
        self::assertStringContainsString('Span 0123456789abcdef', $message);
        self::assertStringContainsString('not found', $message);
    }
}
