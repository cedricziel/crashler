<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

/**
 * Positive or negative bucket structure of an ExponentialHistogramDataPoint.
 *
 * @phpstan-type BucketCounts list<int>
 */
final readonly class ExponentialHistogramBucketsDto
{
    /**
     * @param list<int> $bucketCounts
     */
    public function __construct(
        public int $offset,
        public array $bucketCounts,
    ) {
    }
}
