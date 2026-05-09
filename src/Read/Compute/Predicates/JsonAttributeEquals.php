<?php

declare(strict_types=1);

namespace App\Read\Compute\Predicates;

/**
 * Decoded-walk match against an OTLP-shaped attribute list inside a JSON-string
 * column.
 *
 * The target column carries a JSON array of `{key, value}` objects per the OTLP
 * KeyValue wire shape:
 *
 *   [
 *     {"key": "exception.type", "value": {"stringValue": "RuntimeException"}},
 *     {"key": "http.method",    "value": {"stringValue": "GET"}}
 *   ]
 *
 * For exemplars (metrics), the target column holds an array of OTLP Exemplar
 * objects where `traceId` is the matched key. The predicate handles both
 * shapes.
 *
 * Returns true only when an entry's `key` equals `$attrKey` AND the entry's
 * value (whichever AnyValue variant — stringValue, intValue, doubleValue,
 * boolValue, bytesValue) string-matches `$attrValue`. Substring matches
 * elsewhere in the JSON are NOT treated as a match — that's the defense
 * against false positives.
 *
 * Tier 4 (most expensive): each evaluation does a `json_decode` + walk.
 */
final readonly class JsonAttributeEquals implements Predicate
{
    public function __construct(
        public string $column,
        public string $attrKey,
        public string $attrValue,
    ) {
    }

    public function evaluate(array $row): bool
    {
        $cellValue = $row[$this->column] ?? null;
        if (!\is_string($cellValue) || '' === $cellValue) {
            return false;
        }

        try {
            $decoded = json_decode($cellValue, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Defensive: corrupt/unexpected JSON → predicate fails rather
            // than throws. Scanner keeps going on the next row.
            return false;
        }

        if (!\is_array($decoded)) {
            return false;
        }

        foreach ($decoded as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            // Two shapes: OTLP attribute list (`key` + `value` AnyValue) and
            // OTLP exemplar list (top-level fields like `traceId`, `spanId`).
            // For exemplar match, the attrKey is a top-level field name and
            // attrValue is its expected string.
            if (isset($entry[$this->attrKey]) && \is_string($entry[$this->attrKey]) && $entry[$this->attrKey] === $this->attrValue) {
                return true;
            }
            // OTLP attribute shape: {"key": "...", "value": {"stringValue": ...}}
            if (
                isset($entry['key'], $entry['value'])
                && $entry['key'] === $this->attrKey
                && \is_array($entry['value'])
                && self::anyValueMatches($entry['value'], $this->attrValue)
            ) {
                return true;
            }
        }

        return false;
    }

    public function tier(): int
    {
        return 4;
    }

    /**
     * @param array<string, mixed> $value AnyValue wire shape
     */
    private static function anyValueMatches(array $value, string $expected): bool
    {
        if (isset($value['stringValue']) && \is_string($value['stringValue'])) {
            return $value['stringValue'] === $expected;
        }
        if (isset($value['intValue'])) {
            // OTLP/HTTP-JSON encodes int64 as numeric string.
            return (string) $value['intValue'] === $expected;
        }
        if (isset($value['doubleValue'])) {
            return (string) $value['doubleValue'] === $expected;
        }
        if (isset($value['boolValue']) && \is_bool($value['boolValue'])) {
            return ($value['boolValue'] ? 'true' : 'false') === $expected;
        }
        if (isset($value['bytesValue']) && \is_string($value['bytesValue'])) {
            return $value['bytesValue'] === $expected;
        }

        return false;
    }
}
