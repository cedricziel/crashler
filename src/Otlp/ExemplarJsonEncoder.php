<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\ExemplarDto;

/**
 * Re-encodes a list of ExemplarDto values to OTLP/HTTP-JSON form for storage
 * in the `exemplars_json` Parquet column. Time and asInt are emitted as
 * numeric strings; trace/span IDs are emitted as lowercase hex (matching
 * `traces/v1.trace_id_hex` so future cross-signal joins by string equality
 * work). Filtered attributes use the same proto3-JSON variant shape via
 * {@see AnyValueJsonEncoder}.
 */
final class ExemplarJsonEncoder
{
    /**
     * @param list<ExemplarDto> $exemplars
     */
    public static function encode(array $exemplars): string
    {
        $items = [];
        foreach ($exemplars as $ex) {
            $item = ['timeUnixNano' => (string) $ex->timeUnixNano];
            if (null !== $ex->valueDouble) {
                $item['asDouble'] = $ex->valueDouble;
            }
            if (null !== $ex->valueInt) {
                $item['asInt'] = (string) $ex->valueInt;
            }
            if (null !== $ex->traceId) {
                $item['traceId'] = bin2hex($ex->traceId);
            }
            if (null !== $ex->spanId) {
                $item['spanId'] = bin2hex($ex->spanId);
            }
            $item['filteredAttributes'] = AnyValueJsonEncoder::attributesToArray($ex->filteredAttributes);
            $items[] = $item;
        }

        return json_encode($items, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
