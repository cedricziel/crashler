<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Verifies that the hybrid TenantRegistry resolves both DB-stored and
 * YAML-configured tokens against the authcheck endpoint.
 */
#[CoversNothing]
final class HybridRegistryAuthTest extends DatabaseTestCase
{
    /** Plaintext that hashes to f97cb…507e — already configured in test/crashler.yaml under tenant 'test-tenant'. */
    private const string YAML_TOKEN = 'cw_test_token_aaaaaaaaaaaaaaaaaa';

    public function testYamlConfiguredTokenStillAuthenticates(): void
    {
        $this->client->request('GET', '/v1/_authcheck', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::YAML_TOKEN,
        ]);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('test-tenant', $body['tenant_slug'] ?? null);
    }

    public function testDbStoredTokenAuthenticates(): void
    {
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');

        $plaintext = 'cw_'.bin2hex(random_bytes(16));
        $hash = hash('sha256', $plaintext);
        $this->createTenantToken($tenant, 'integration-test', $hash);

        $this->client->request('GET', '/v1/_authcheck', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plaintext,
        ]);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('acme-prod', $body['tenant_slug'] ?? null);
        self::assertSame('Acme Production', $body['tenant_name'] ?? null);
    }

    public function testExpiredDbTokenIsRejected(): void
    {
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');

        $plaintext = 'cw_'.bin2hex(random_bytes(16));
        $hash = hash('sha256', $plaintext);
        $this->createTenantToken($tenant, 'expired', $hash, new \DateTimeImmutable('-1 hour'));

        $this->client->request('GET', '/v1/_authcheck', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plaintext,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownTokenIsRejectedWhenNeitherSourceMatches(): void
    {
        $this->client->request('GET', '/v1/_authcheck', server: [
            'HTTP_AUTHORIZATION' => 'Bearer cw_unknown_token_zzzzzzzzzzzz',
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
