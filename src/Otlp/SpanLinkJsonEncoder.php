<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\SpanLinkDto;

/**
 * Re-encodes a list of decoded SpanLinkDto values back to OTLP/HTTP-JSON form
 * for storage in the `links_json` Parquet column. Trace and span IDs are
 * emitted as lowercase hex per the OTLP/HTTP-JSON spec; null traceState/flags
 * and zero dropped counts are omitted to keep payloads compact.
 */
final class SpanLinkJsonEncoder
{
    /**
     * @param list<SpanLinkDto> $links
     */
    public static function encode(array $links): string
    {
        $items = [];
        foreach ($links as $link) {
            $item = [
                'traceId' => bin2hex($link->traceId),
                'spanId' => bin2hex($link->spanId),
                'attributes' => AnyValueJsonEncoder::attributesToArray($link->attributes),
            ];
            if (null !== $link->traceState) {
                $item['traceState'] = $link->traceState;
            }
            if (0 !== $link->droppedAttributesCount) {
                $item['droppedAttributesCount'] = $link->droppedAttributesCount;
            }
            if (null !== $link->flags) {
                $item['flags'] = $link->flags;
            }
            $items[] = $item;
        }

        return json_encode($items, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
