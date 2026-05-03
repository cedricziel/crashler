<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\IngestTokenAuthenticator;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(IngestTokenAuthenticator::class)]
final class IngestAuthenticationTest extends KernelTestCase
{
    use HasBrowser;

    /** Plaintext token whose SHA-256 is configured in config/packages/test/crashler.yaml. */
    private const string VALID_TOKEN = 'cw_test_token_aaaaaaaaaaaaaaaaaa';

    public function testValidTokenAttachesAuthenticatedTenant(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN]])
            ->assertStatus(200)
            ->assertJson()
            ->assertJsonMatches('tenant_slug', 'test-tenant')
            ->assertJsonMatches('tenant_name', 'Test Tenant')
        ;
    }

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck')
            ->assertStatus(401)
            ->assertJsonMatches('message', 'Unauthorized.')
        ;
    }

    public function testUnknownTokenReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => 'Bearer cw_unknown_token_value_xxxxxxx']])
            ->assertStatus(401)
        ;
    }

    public function testNonBearerSchemeReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => 'Basic dXNlcjpwYXNz']])
            ->assertStatus(401)
        ;
    }

    public function testEmptyBearerReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => 'Bearer ']])
            ->assertStatus(401)
        ;
    }

    public function testRawTokenWithoutBearerPrefixReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => self::VALID_TOKEN]])
            ->assertStatus(401)
        ;
    }
}
