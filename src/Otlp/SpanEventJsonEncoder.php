<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\SpanEventDto;

/**
 * Re-encodes a list of decoded SpanEventDto values back to OTLP/HTTP-JSON form
 * for storage in the `events_json` Parquet column. Per the OTLP/HTTP-JSON spec,
 * `time_unix_nano` is emitted as a numeric string. AnyValue variants are
 * preserved via {@see AnyValueJsonEncoder}.
 */
final class SpanEventJsonEncoder
{
    /**
     * @param list<SpanEventDto> $events
     */
    public static function encode(array $events): string
    {
        $items = [];
        foreach ($events as $event) {
            $item = [
                'timeUnixNano' => (string) $event->timeUnixNano,
                'name' => $event->name,
                'attributes' => AnyValueJsonEncoder::attributesToArray($event->attributes),
            ];
            if (0 !== $event->droppedAttributesCount) {
                $item['droppedAttributesCount'] = $event->droppedAttributesCount;
            }
            $items[] = $item;
        }

        return json_encode($items, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
