<?php

declare(strict_types=1);

namespace App\Security;

use App\Tenancy\Tenant;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class IngestUser implements UserInterface
{
    public function __construct(
        public Tenant $tenant,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->tenant->slug;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_INGEST'];
    }

    public function eraseCredentials(): void
    {
    }
}
