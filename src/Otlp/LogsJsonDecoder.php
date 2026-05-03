<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\ResourceLogsDto;
use App\Otlp\Dto\ScopeLogsDto;
use App\Otlp\Exception\OtlpDecodeException;

/**
 * Parses OTLP/HTTP-JSON ExportLogsServiceRequest bodies into the DTO tree.
 *
 * Follows proto3-JSON encoding rules per the OTLP HTTP specification:
 * - int64 fields (timeUnixNano, intValue) are accepted as either JSON number
 *   or numeric string (because JS cannot represent int64 precisely).
 * - bytes fields use base64 except traceId/spanId which are lowercase hex
 *   strings (32 / 16 chars respectively).
 * - AnyValue is preserved across all variants.
 */
final class LogsJsonDecoder
{
    public function decode(string $json): ExportLogsServiceRequestDto
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw OtlpDecodeException::malformedJson($e);
        }

        if (!\is_array($decoded)) {
            throw OtlpDecodeException::schemaMismatch('top-level value must be an object.');
        }

        $resourceLogsRaw = $decoded['resourceLogs'] ?? null;
        if (!\is_array($resourceLogsRaw)) {
            throw OtlpDecodeException::schemaMismatch('"resourceLogs" must be an array.');
        }

        $resourceLogs = [];
        foreach ($resourceLogsRaw as $i => $entry) {
            $resourceLogs[] = $this->decodeResourceLogs($entry, "resourceLogs[$i]");
        }

        return new ExportLogsServiceRequestDto($resourceLogs);
    }

    /**
     * @param mixed $raw
     */
    private function decodeResourceLogs($raw, string $path): ResourceLogsDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }

        $resourceAttrs = [];
        if (isset($raw['resource']) && \is_array($raw['resource'])) {
            $attrs = $raw['resource']['attributes'] ?? [];
            if (!\is_array($attrs)) {
                throw OtlpDecodeException::schemaMismatch("$path.resource.attributes must be an array.");
            }
            foreach ($attrs as $i => $kv) {
                $resourceAttrs[] = $this->decodeKeyValue($kv, "$path.resource.attributes[$i]");
            }
        }

        $scopeLogsRaw = $raw['scopeLogs'] ?? null;
        if (!\is_array($scopeLogsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.scopeLogs must be an array.");
        }

        $scopeLogs = [];
        foreach ($scopeLogsRaw as $i => $entry) {
            $scopeLogs[] = $this->decodeScopeLogs($entry, "$path.scopeLogs[$i]");
        }

        return new ResourceLogsDto($resourceAttrs, $scopeLogs);
    }

    /**
     * @param mixed $raw
     */
    private function decodeScopeLogs($raw, string $path): ScopeLogsDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }

        $scopeName = null;
        $scopeVersion = null;
        if (isset($raw['scope']) && \is_array($raw['scope'])) {
            $scopeName = $this->stringOrNull($raw['scope']['name'] ?? null, "$path.scope.name");
            $scopeVersion = $this->stringOrNull($raw['scope']['version'] ?? null, "$path.scope.version");
        }

        // schemaUrl lives at the ScopeLogs level (not inside scope itself), per
        // the OTLP proto. It identifies the OTel schema that the scope's data
        // is recorded in.
        $schemaUrl = $this->stringOrNull($raw['schemaUrl'] ?? null, "$path.schemaUrl");

        $logRecordsRaw = $raw['logRecords'] ?? null;
        if (!\is_array($logRecordsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.logRecords must be an array.");
        }

        $logRecords = [];
        foreach ($logRecordsRaw as $i => $entry) {
            $logRecords[] = $this->decodeLogRecord($entry, "$path.logRecords[$i]");
        }

        return new ScopeLogsDto($scopeName, $scopeVersion, $logRecords, $schemaUrl);
    }

    /**
     * @param mixed $raw
     */
    private function decodeLogRecord($raw, string $path): LogRecordDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }

        if (!\array_key_exists('timeUnixNano', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.timeUnixNano is required.");
        }

        $time = $this->int64($raw['timeUnixNano'], "$path.timeUnixNano");
        $observed = isset($raw['observedTimeUnixNano']) ? $this->int64($raw['observedTimeUnixNano'], "$path.observedTimeUnixNano") : null;
        $severityNumber = isset($raw['severityNumber']) ? $this->int32($raw['severityNumber'], "$path.severityNumber") : null;
        $severityText = $this->stringOrNull($raw['severityText'] ?? null, "$path.severityText");
        $body = isset($raw['body']) ? $this->decodeAnyValue($raw['body'], "$path.body") : null;
        $traceId = $this->hexBytesOrNull($raw['traceId'] ?? null, 32, "$path.traceId");
        $spanId = $this->hexBytesOrNull($raw['spanId'] ?? null, 16, "$path.spanId");
        $flags = isset($raw['flags']) ? $this->int32($raw['flags'], "$path.flags") : null;
        $dropped = isset($raw['droppedAttributesCount']) ? $this->int32($raw['droppedAttributesCount'], "$path.droppedAttributesCount") : 0;

        $attributesRaw = $raw['attributes'] ?? [];
        if (!\is_array($attributesRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.attributes must be an array.");
        }
        $attributes = [];
        foreach ($attributesRaw as $i => $kv) {
            $attributes[] = $this->decodeKeyValue($kv, "$path.attributes[$i]");
        }

        return new LogRecordDto(
            timeUnixNano: $time,
            observedTimeUnixNano: $observed,
            severityNumber: $severityNumber,
            severityText: $severityText,
            body: $body,
            attributes: $attributes,
            droppedAttributesCount: $dropped,
            traceId: $traceId,
            spanId: $spanId,
            flags: $flags,
        );
    }

    /**
     * @param mixed $raw
     */
    private function decodeKeyValue($raw, string $path): KeyValueDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        if (!isset($raw['key']) || !\is_string($raw['key'])) {
            throw OtlpDecodeException::schemaMismatch("$path.key is required and must be a string.");
        }
        $value = $this->decodeAnyValue($raw['value'] ?? [], "$path.value");

        return new KeyValueDto($raw['key'], $value);
    }

    /**
     * @param mixed $raw
     */
    private function decodeAnyValue($raw, string $path): AnyValueDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object (AnyValue).");
        }

        if (\array_key_exists('stringValue', $raw)) {
            return AnyValueDto::string($this->stringOrNull($raw['stringValue'], "$path.stringValue") ?? '');
        }
        if (\array_key_exists('intValue', $raw)) {
            return AnyValueDto::int($this->int64($raw['intValue'], "$path.intValue"));
        }
        if (\array_key_exists('doubleValue', $raw)) {
            $v = $raw['doubleValue'];
            if (!\is_int($v) && !\is_float($v)) {
                throw OtlpDecodeException::schemaMismatch("$path.doubleValue must be a number.");
            }

            return AnyValueDto::double((float) $v);
        }
        if (\array_key_exists('boolValue', $raw)) {
            $v = $raw['boolValue'];
            if (!\is_bool($v)) {
                throw OtlpDecodeException::schemaMismatch("$path.boolValue must be a boolean.");
            }

            return AnyValueDto::bool($v);
        }
        if (\array_key_exists('bytesValue', $raw)) {
            $v = $raw['bytesValue'];
            if (!\is_string($v)) {
                throw OtlpDecodeException::schemaMismatch("$path.bytesValue must be a base64 string.");
            }
            $decoded = base64_decode($v, true);
            if (false === $decoded) {
                throw OtlpDecodeException::schemaMismatch("$path.bytesValue is not valid base64.");
            }

            return AnyValueDto::bytes($decoded);
        }
        if (\array_key_exists('arrayValue', $raw)) {
            $av = $raw['arrayValue'];
            if (!\is_array($av) || !\is_array($av['values'] ?? null)) {
                throw OtlpDecodeException::schemaMismatch("$path.arrayValue.values must be an array.");
            }
            $items = [];
            foreach ($av['values'] as $i => $entry) {
                $items[] = $this->decodeAnyValue($entry, "$path.arrayValue.values[$i]");
            }

            return AnyValueDto::array($items);
        }
        if (\array_key_exists('kvlistValue', $raw)) {
            $kv = $raw['kvlistValue'];
            if (!\is_array($kv) || !\is_array($kv['values'] ?? null)) {
                throw OtlpDecodeException::schemaMismatch("$path.kvlistValue.values must be an array.");
            }
            $items = [];
            foreach ($kv['values'] as $i => $entry) {
                $items[] = $this->decodeKeyValue($entry, "$path.kvlistValue.values[$i]");
            }

            return AnyValueDto::kvlist($items);
        }

        // Empty AnyValue: spec allows; encode as a string with empty value.
        return new AnyValueDto();
    }

    /**
     * @param mixed $raw
     */
    private function int64($raw, string $path): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw) && '' !== $raw && 1 === preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        throw OtlpDecodeException::schemaMismatch("$path must be an integer or numeric string.");
    }

    /**
     * @param mixed $raw
     */
    private function int32($raw, string $path): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw) && '' !== $raw && 1 === preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        throw OtlpDecodeException::schemaMismatch("$path must be an integer.");
    }

    /**
     * @param mixed $raw
     */
    private function stringOrNull($raw, string $path): ?string
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be a string.");
        }

        return $raw;
    }

    /**
     * @param mixed $raw
     */
    private function hexBytesOrNull($raw, int $expectedLengthChars, string $path): ?string
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be a hex string.");
        }
        if (\strlen($raw) !== $expectedLengthChars || 1 !== preg_match('/^[0-9a-f]+$/', $raw)) {
            throw OtlpDecodeException::schemaMismatch(\sprintf(
                '%s must be exactly %d lowercase hex characters.',
                $path,
                $expectedLengthChars,
            ));
        }
        $bytes = hex2bin($raw);
        if (false === $bytes) {
            throw OtlpDecodeException::schemaMismatch("$path could not be decoded from hex.");
        }

        return $bytes;
    }
}
