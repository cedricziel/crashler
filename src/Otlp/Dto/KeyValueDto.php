<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class KeyValueDto
{
    public function __construct(
        public string $key,
        public AnyValueDto $value,
    ) {
    }
}
