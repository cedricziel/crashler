<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\KeyValueDto;

/**
 * Re-encodes decoded OTLP AnyValue / KeyValue trees back to their proto3-JSON
 * wire form so the variant tag (stringValue / intValue / ...) is preserved
 * when the data is stored as a JSON-string Parquet column.
 *
 * Per the OTLP/HTTP-JSON spec, int64 fields are encoded as numeric strings and
 * bytes are base64-encoded.
 */
final class AnyValueJsonEncoder
{
    public static function encode(AnyValueDto $value): string
    {
        $array = self::toArray($value);
        // OTLP encodes AnyValue as a JSON object even when empty.
        if ([] === $array) {
            return '{}';
        }

        return json_encode($array, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<KeyValueDto> $attributes
     */
    public static function encodeAttributes(array $attributes): string
    {
        return json_encode(self::attributesToArray($attributes), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<KeyValueDto> $attributes
     *
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    public static function attributesToArray(array $attributes): array
    {
        $items = [];
        foreach ($attributes as $kv) {
            $items[] = [
                'key' => $kv->key,
                'value' => self::toArray($kv->value),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(AnyValueDto $value): array
    {
        if (null !== $value->stringValue) {
            return ['stringValue' => $value->stringValue];
        }
        if (null !== $value->intValue) {
            return ['intValue' => (string) $value->intValue];
        }
        if (null !== $value->doubleValue) {
            return ['doubleValue' => $value->doubleValue];
        }
        if (null !== $value->boolValue) {
            return ['boolValue' => $value->boolValue];
        }
        if (null !== $value->bytesValue) {
            return ['bytesValue' => base64_encode($value->bytesValue)];
        }
        if (null !== $value->arrayValue) {
            return ['arrayValue' => ['values' => array_map(self::toArray(...), $value->arrayValue)]];
        }
        if (null !== $value->kvlistValue) {
            $items = [];
            foreach ($value->kvlistValue as $kv) {
                $items[] = [
                    'key' => $kv->key,
                    'value' => self::toArray($kv->value),
                ];
            }

            return ['kvlistValue' => ['values' => $items]];
        }

        return [];
    }
}
