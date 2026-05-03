<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

/**
 * ExponentialHistogramDataPoint — base-2 exponential histogram with positive
 * and negative bucket arrays plus a separate zero bucket. Scale is the
 * resolution parameter; doubling the scale doubles the number of buckets per
 * decade.
 */
final readonly class ExponentialHistogramDataPointDto
{
    /**
     * @param list<KeyValueDto> $attributes
     * @param list<ExemplarDto> $exemplars
     */
    public function __construct(
        public ?int $startTimeUnixNano,
        public int $timeUnixNano,
        public int $count,
        public ?float $sum,
        public int $scale,
        public int $zeroCount,
        public ?float $zeroThreshold,
        public ?ExponentialHistogramBucketsDto $positive,
        public ?ExponentialHistogramBucketsDto $negative,
        public ?float $min,
        public ?float $max,
        public array $attributes,
        public array $exemplars,
        public ?int $flags,
    ) {
    }
}
