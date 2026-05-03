<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\MetricDto;

/**
 * Re-encodes a Metric envelope (name, unit, description, type discriminator,
 * temporality, monotonicity) to OTLP/HTTP-JSON form for storage in the
 * `metric_attributes_json` Parquet column. The data-points list is
 * deliberately excluded — each row is already one data-point, so reproducing
 * the envelope's data-point list inside every row would denormalize the
 * data-point onto itself and bloat the file.
 */
final class MetricEnvelopeJsonEncoder
{
    public static function encode(MetricDto $metric): string
    {
        $payload = [
            'name' => $metric->name,
            'metricType' => $metric->type->text(),
        ];
        if (null !== $metric->unit) {
            $payload['unit'] = $metric->unit;
        }
        if (null !== $metric->description) {
            $payload['description'] = $metric->description;
        }
        if (null !== $metric->aggregationTemporality) {
            $payload['aggregationTemporality'] = $metric->aggregationTemporality;
        }
        if (null !== $metric->isMonotonic) {
            $payload['isMonotonic'] = $metric->isMonotonic;
        }

        return json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
