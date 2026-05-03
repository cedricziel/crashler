<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

/**
 * One Metric envelope. The `type` discriminator selects which of the four
 * data-point lists is populated; the others are empty arrays.
 *
 * `aggregationTemporality` and `isMonotonic` are populated only for the
 * metric types where they're defined (Sum, Histogram, ExponentialHistogram
 * for temporality; Sum for monotonicity); null otherwise.
 */
final readonly class MetricDto
{
    /**
     * @param list<NumberDataPointDto>               $numberDataPoints
     * @param list<HistogramDataPointDto>            $histogramDataPoints
     * @param list<ExponentialHistogramDataPointDto> $exponentialHistogramDataPoints
     * @param list<SummaryDataPointDto>              $summaryDataPoints
     */
    public function __construct(
        public string $name,
        public ?string $unit,
        public ?string $description,
        public MetricType $type,
        public ?int $aggregationTemporality,
        public ?bool $isMonotonic,
        public array $numberDataPoints,
        public array $histogramDataPoints,
        public array $exponentialHistogramDataPoints,
        public array $summaryDataPoints,
    ) {
    }
}
