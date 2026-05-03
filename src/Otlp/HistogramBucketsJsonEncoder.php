<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\HistogramDataPointDto;

/**
 * Re-encodes a HistogramDataPoint's bucket structure to OTLP/HTTP-JSON wire
 * shape (`{bucketCounts: ["<numstr>"...], explicitBounds: [...]}`) for
 * storage in the `buckets_json` Parquet column. Per OTLP/HTTP-JSON spec,
 * uint64 bucket counts are emitted as numeric strings.
 *
 * Returns null when the data-point has no bucket structure at all (both
 * arrays empty), so the column stays NULL in those rows.
 */
final class HistogramBucketsJsonEncoder
{
    public static function encode(HistogramDataPointDto $dp): ?string
    {
        if ([] === $dp->bucketCounts && [] === $dp->explicitBounds) {
            return null;
        }

        return json_encode([
            'bucketCounts' => array_map(static fn (int $c): string => (string) $c, $dp->bucketCounts),
            'explicitBounds' => $dp->explicitBounds,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
