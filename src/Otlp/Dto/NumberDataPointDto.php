<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

/**
 * NumberDataPoint — used by Sum and Gauge metrics. Exactly one of
 * $valueDouble / $valueInt is non-null per OTLP (proto3 oneof).
 */
final readonly class NumberDataPointDto
{
    /**
     * @param list<KeyValueDto> $attributes
     * @param list<ExemplarDto> $exemplars
     */
    public function __construct(
        public ?int $startTimeUnixNano,
        public int $timeUnixNano,
        public ?float $valueDouble,
        public ?int $valueInt,
        public array $attributes,
        public array $exemplars,
        public ?int $flags,
    ) {
    }
}
