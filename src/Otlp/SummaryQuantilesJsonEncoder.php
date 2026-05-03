<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\ValueAtQuantileDto;

/**
 * Re-encodes a SummaryDataPoint's quantile values to an OTLP/HTTP-JSON list
 * for storage in the `quantiles_json` Parquet column. Both `quantile` and
 * `value` are double; emitted as JSON numbers.
 */
final class SummaryQuantilesJsonEncoder
{
    /**
     * @param list<ValueAtQuantileDto> $quantiles
     */
    public static function encode(array $quantiles): string
    {
        $items = [];
        foreach ($quantiles as $q) {
            $items[] = ['quantile' => $q->quantile, 'value' => $q->value];
        }

        return json_encode($items, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
