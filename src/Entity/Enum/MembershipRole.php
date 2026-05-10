<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function precedence(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->precedence() >= $other->precedence();
    }

    public static function highest(self ...$roles): ?self
    {
        $best = null;
        foreach ($roles as $role) {
            if (null === $best || $role->precedence() > $best->precedence()) {
                $best = $role;
            }
        }

        return $best;
    }
}
