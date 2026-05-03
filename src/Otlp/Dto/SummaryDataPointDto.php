<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

/**
 * SummaryDataPoint — used by the deprecated Summary metric type. Carries
 * a count, sum, and a list of pre-computed quantile values.
 */
final readonly class SummaryDataPointDto
{
    /**
     * @param list<KeyValueDto>        $attributes
     * @param list<ValueAtQuantileDto> $quantileValues
     */
    public function __construct(
        public ?int $startTimeUnixNano,
        public int $timeUnixNano,
        public int $count,
        public float $sum,
        public array $quantileValues,
        public array $attributes,
        public ?int $flags,
    ) {
    }
}
