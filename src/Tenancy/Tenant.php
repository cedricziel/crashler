<?php

declare(strict_types=1);

namespace App\Tenancy;

final readonly class Tenant
{
    public function __construct(
        public string $slug,
        public string $name,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->slug === $other->slug
            && $this->name === $other->name;
    }
}
