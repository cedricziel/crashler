<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\KeyValueDto;
use App\Schema\SchemaDefinition;

/**
 * Reads promotion rules off a SchemaDefinition and converts a flat
 * KeyValueDto[] list into a map keyed by promoted column name.
 *
 * Returns NULL for a promoted column whose matching attribute is an empty
 * AnyValue (so callers can distinguish "promoted but unset" from "not
 * configured"). The input list is never modified — callers continue to
 * serialise the original verbatim into the JSON blob.
 */
final class AttributeColumnExtractor
{
    public function __construct(
        private readonly SchemaDefinition $schema,
    ) {
    }

    /**
     * @param list<KeyValueDto> $attributes
     *
     * @return array<string, scalar|null>
     */
    public function extractResource(array $attributes): array
    {
        return $this->extractWith($this->schema->resourcePromotions, $attributes);
    }

    /**
     * @param list<KeyValueDto> $attributes
     *
     * @return array<string, scalar|null>
     */
    public function extractScope(array $attributes): array
    {
        return $this->extractWith($this->schema->scopePromotions, $attributes);
    }

    /**
     * @param list<KeyValueDto> $attributes
     *
     * @return array<string, scalar|null>
     */
    public function extractRecord(array $attributes): array
    {
        return $this->extractWith($this->schema->recordPromotions, $attributes);
    }

    /**
     * @param array<string, list<string>> $promotions  column → ordered semconv keys
     * @param list<KeyValueDto>           $attributes
     *
     * @return array<string, scalar|null>
     */
    private function extractWith(array $promotions, array $attributes): array
    {
        if ([] === $promotions) {
            return [];
        }

        // Index inputs by key for O(1) lookup; later occurrences of the same
        // key win (last-write-wins matches OTel's collapse semantics).
        $byKey = [];
        foreach ($attributes as $kv) {
            $byKey[$kv->key] = $kv->value;
        }

        $out = [];
        foreach ($promotions as $column => $keysInOrder) {
            $matched = false;
            foreach ($keysInOrder as $key) {
                if (\array_key_exists($key, $byKey)) {
                    $out[$column] = self::coerce($byKey[$key]);
                    $matched = true;
                    break;
                }
            }
            // If none matched, the column is absent from the output map; the
            // writer fills it with NULL via the column's optional repetition.
            if (!$matched) {
                continue;
            }
        }

        return $out;
    }

    /**
     * @return scalar|null
     */
    private static function coerce(AnyValueDto $value): mixed
    {
        if (null !== $value->stringValue) {
            return $value->stringValue;
        }
        if (null !== $value->intValue) {
            return $value->intValue;
        }
        if (null !== $value->doubleValue) {
            return $value->doubleValue;
        }
        if (null !== $value->boolValue) {
            return $value->boolValue;
        }
        if (null !== $value->bytesValue) {
            return $value->bytesValue;
        }
        if (null !== $value->arrayValue || null !== $value->kvlistValue) {
            // Complex variants can't fit a scalar column; serialize as the
            // canonical OTLP/HTTP-JSON wire form so the value stays readable.
            return AnyValueJsonEncoder::encode($value);
        }

        return null;
    }
}
