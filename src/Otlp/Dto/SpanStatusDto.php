<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class SpanStatusDto
{
    public function __construct(
        public int $code,
        public ?string $message,
    ) {
    }
}
