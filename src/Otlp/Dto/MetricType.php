<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

/**
 * Discriminator for the five OTLP metric data shapes. Codes match the row
 * column `metric_type_code` (0=SUM, 1=GAUGE, 2=HISTOGRAM, 3=EXPONENTIAL_HISTOGRAM,
 * 4=SUMMARY); names match the `metric_type` text column.
 */
enum MetricType: int
{
    case Sum = 0;
    case Gauge = 1;
    case Histogram = 2;
    case ExponentialHistogram = 3;
    case Summary = 4;

    public function text(): string
    {
        return match ($this) {
            self::Sum => 'SUM',
            self::Gauge => 'GAUGE',
            self::Histogram => 'HISTOGRAM',
            self::ExponentialHistogram => 'EXPONENTIAL_HISTOGRAM',
            self::Summary => 'SUMMARY',
        };
    }
}
