<?php

declare(strict_types=1);

namespace App\Tenancy\Token;

use App\Entity\Tenant as TenantEntity;
use App\Entity\TenantToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates a fresh plaintext token, persists its SHA-256 hash, and returns
 * both the entity and the plaintext together so the caller can render the
 * plaintext exactly once.
 */
final class TokenIssuer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function issue(
        TenantEntity $tenant,
        string $name,
        ?\DateTimeImmutable $expiresAt = null,
        ?User $createdBy = null,
    ): IssuedToken {
        $plaintext = 'cw_'.bin2hex(random_bytes(16));
        $hash = hash('sha256', $plaintext);

        $token = new TenantToken();
        $token->setTenant($tenant);
        $token->setName($name);
        $token->setHash($hash);
        $token->setExpiresAt($expiresAt);
        $token->setCreatedBy($createdBy);

        $this->em->persist($token);
        $this->em->flush();

        return new IssuedToken($token, $plaintext);
    }
}
