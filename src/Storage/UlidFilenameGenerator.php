<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\Uid\Ulid;

final class UlidFilenameGenerator implements FilenameGenerator
{
    public function generate(): string
    {
        return (new Ulid())->toBase32();
    }
}
