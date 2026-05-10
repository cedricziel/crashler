<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Repository\TenantTokenRepository;
use App\Tenancy\Token\TokenIssuer;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TokenIssuer::class)]
final class TokenIssuerTest extends DatabaseTestCase
{
    public function testIssueProducesPlaintextAndPersistsHashedRow(): void
    {
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');

        /** @var TokenIssuer $issuer */
        $issuer = static::getContainer()->get(TokenIssuer::class);

        $issued = $issuer->issue($tenant, 'integration', null, null);

        self::assertMatchesRegularExpression('/^cw_[a-f0-9]{32}$/', $issued->plaintext);
        self::assertSame(hash('sha256', $issued->plaintext), $issued->token->getHash());
        self::assertSame('integration', $issued->token->getName());
        self::assertNull($issued->token->getExpiresAt());
        self::assertSame('acme-prod', $issued->token->getTenant()?->getSlug());
        self::assertNull($issued->token->getCreatedBy());
        self::assertNotNull($issued->token->getCreatedAt());

        // The persisted row exists and matches
        /** @var TenantTokenRepository $repo */
        $repo = static::getContainer()->get(TenantTokenRepository::class);
        $persisted = $repo->findOneByHash($issued->token->getHash());
        self::assertNotNull($persisted);
        self::assertSame($issued->token->getId(), $persisted->getId());
    }

    public function testIssueAcceptsExpiryAndCreator(): void
    {
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $admin = $this->createUser('admin@example.com', 'pw-12345', admin: true);
        $expires = new \DateTimeImmutable('+30 days');

        /** @var TokenIssuer $issuer */
        $issuer = static::getContainer()->get(TokenIssuer::class);

        $issued = $issuer->issue($tenant, 'with-expiry', $expires, $admin);

        self::assertSame($admin->getId(), $issued->token->getCreatedBy()?->getId());
        self::assertEquals($expires->getTimestamp(), $issued->token->getExpiresAt()?->getTimestamp());
    }

    public function testIssuedPlaintextsAreUniquePerCall(): void
    {
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');

        /** @var TokenIssuer $issuer */
        $issuer = static::getContainer()->get(TokenIssuer::class);

        $a = $issuer->issue($tenant, 'a', null, null);
        $b = $issuer->issue($tenant, 'b', null, null);

        self::assertNotSame($a->plaintext, $b->plaintext);
        self::assertNotSame($a->token->getHash(), $b->token->getHash());
    }
}
