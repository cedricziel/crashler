<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\ExponentialHistogramBucketsDto;
use App\Otlp\Dto\ExponentialHistogramDataPointDto;

/**
 * Re-encodes an ExponentialHistogramDataPoint to OTLP/HTTP-JSON wire shape
 * for storage in the `exponential_histogram_json` Parquet column. Carries
 * scale, zero count, zero threshold, and positive/negative bucket arrays.
 *
 * uint64 fields (count, zeroCount, bucketCounts entries) are emitted as
 * numeric strings per OTLP/HTTP-JSON spec.
 */
final class ExponentialHistogramJsonEncoder
{
    public static function encode(ExponentialHistogramDataPointDto $dp): string
    {
        $payload = [
            'scale' => $dp->scale,
            'zeroCount' => (string) $dp->zeroCount,
        ];
        if (null !== $dp->zeroThreshold) {
            $payload['zeroThreshold'] = $dp->zeroThreshold;
        }
        if (null !== $dp->positive) {
            $payload['positive'] = self::bucketsToArray($dp->positive);
        }
        if (null !== $dp->negative) {
            $payload['negative'] = self::bucketsToArray($dp->negative);
        }

        return json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{offset: int, bucketCounts: list<string>}
     */
    private static function bucketsToArray(ExponentialHistogramBucketsDto $buckets): array
    {
        return [
            'offset' => $buckets->offset,
            'bucketCounts' => array_map(static fn (int $c): string => (string) $c, $buckets->bucketCounts),
        ];
    }
}
