<?php

declare(strict_types=1);

namespace App\Schema;

final readonly class SchemaColumn
{
    public function __construct(
        public string $name,
        public string $type,
        public string $repetition,
    ) {
    }
}
