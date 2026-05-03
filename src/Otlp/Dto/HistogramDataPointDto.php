<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

/**
 * HistogramDataPoint — used by Histogram metrics. `bucketCounts` length is
 * `explicitBounds` length + 1 (the +inf overflow bucket has no upper bound).
 */
final readonly class HistogramDataPointDto
{
    /**
     * @param list<KeyValueDto> $attributes
     * @param list<int>         $bucketCounts
     * @param list<float>       $explicitBounds
     * @param list<ExemplarDto> $exemplars
     */
    public function __construct(
        public ?int $startTimeUnixNano,
        public int $timeUnixNano,
        public int $count,
        public ?float $sum,
        public ?float $min,
        public ?float $max,
        public array $bucketCounts,
        public array $explicitBounds,
        public array $attributes,
        public array $exemplars,
        public ?int $flags,
    ) {
    }
}
