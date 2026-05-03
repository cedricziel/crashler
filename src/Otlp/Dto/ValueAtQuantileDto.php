<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ValueAtQuantileDto
{
    public function __construct(
        public float $quantile,
        public float $value,
    ) {
    }
}
