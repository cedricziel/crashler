<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Contract\SignalDecoder;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\ResourceLogsDto;
use App\Otlp\Dto\ScopeLogsDto;
use App\Otlp\Exception\OtlpDecodeException;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Logs\V1\LogRecord;
use Opentelemetry\Proto\Logs\V1\ResourceLogs;
use Opentelemetry\Proto\Logs\V1\ScopeLogs;

/**
 * Parses OTLP/HTTP-protobuf ExportLogsServiceRequest bodies into the same DTO
 * tree the JSON decoder produces, so downstream code is encoding-agnostic.
 *
 * trace_id and span_id come back as the raw bytes the wire delivered (16 and
 * 8 bytes respectively); empty values are normalised to null.
 */
final class LogsProtobufDecoder implements SignalDecoder
{
    public function decode(string $bytes): ExportLogsServiceRequestDto
    {
        // An empty serialized protobuf body is valid and represents an
        // ExportLogsServiceRequest with no resource_logs (no records to
        // ingest). The HTTP layer caps body size, so we don't reject
        // empty bodies here.
        $proto = new ExportLogsServiceRequest();
        try {
            $proto->mergeFromString($bytes);
        } catch (\Throwable $e) {
            throw new OtlpDecodeException('Failed to parse OTLP/protobuf body: '.$e->getMessage(), previous: $e);
        }

        $resourceLogs = [];
        foreach ($proto->getResourceLogs() as $rl) {
            $resourceLogs[] = $this->decodeResourceLogs($rl);
        }

        return new ExportLogsServiceRequestDto($resourceLogs);
    }

    private function decodeResourceLogs(ResourceLogs $proto): ResourceLogsDto
    {
        $resourceAttrs = [];
        if (null !== ($resource = $proto->getResource())) {
            foreach ($resource->getAttributes() as $kv) {
                $resourceAttrs[] = $this->decodeKeyValue($kv);
            }
        }

        $scopeLogs = [];
        foreach ($proto->getScopeLogs() as $sl) {
            $scopeLogs[] = $this->decodeScopeLogs($sl);
        }

        return new ResourceLogsDto($resourceAttrs, $scopeLogs);
    }

    private function decodeScopeLogs(ScopeLogs $proto): ScopeLogsDto
    {
        $scopeName = null;
        $scopeVersion = null;
        if (null !== ($scope = $proto->getScope())) {
            $scopeName = '' !== $scope->getName() ? $scope->getName() : null;
            $scopeVersion = '' !== $scope->getVersion() ? $scope->getVersion() : null;
        }

        $schemaUrl = '' !== $proto->getSchemaUrl() ? $proto->getSchemaUrl() : null;

        $logRecords = [];
        foreach ($proto->getLogRecords() as $lr) {
            $logRecords[] = $this->decodeLogRecord($lr);
        }

        return new ScopeLogsDto($scopeName, $scopeVersion, $logRecords, $schemaUrl);
    }

    private function decodeLogRecord(LogRecord $proto): LogRecordDto
    {
        $body = null !== ($protoBody = $proto->getBody()) && $this->anyValueIsSet($protoBody)
            ? $this->decodeAnyValue($protoBody)
            : null;

        $attributes = [];
        foreach ($proto->getAttributes() as $kv) {
            $attributes[] = $this->decodeKeyValue($kv);
        }

        $traceIdBytes = $proto->getTraceId();
        $spanIdBytes = $proto->getSpanId();

        return new LogRecordDto(
            timeUnixNano: $proto->getTimeUnixNano(),
            observedTimeUnixNano: 0 === $proto->getObservedTimeUnixNano() ? null : $proto->getObservedTimeUnixNano(),
            severityNumber: 0 === $proto->getSeverityNumber() ? null : $proto->getSeverityNumber(),
            severityText: '' === $proto->getSeverityText() ? null : $proto->getSeverityText(),
            body: $body,
            attributes: $attributes,
            droppedAttributesCount: $proto->getDroppedAttributesCount(),
            traceId: '' === $traceIdBytes ? null : $traceIdBytes,
            spanId: '' === $spanIdBytes ? null : $spanIdBytes,
            flags: 0 === $proto->getFlags() ? null : $proto->getFlags(),
        );
    }

    private function decodeKeyValue(KeyValue $proto): KeyValueDto
    {
        $value = null !== ($protoValue = $proto->getValue()) && $this->anyValueIsSet($protoValue)
            ? $this->decodeAnyValue($protoValue)
            : new AnyValueDto();

        return new KeyValueDto($proto->getKey(), $value);
    }

    private function decodeAnyValue(AnyValue $proto): AnyValueDto
    {
        return match ($proto->getValue()) {
            'string_value' => AnyValueDto::string($proto->getStringValue()),
            'int_value' => AnyValueDto::int($proto->getIntValue()),
            'double_value' => AnyValueDto::double($proto->getDoubleValue()),
            'bool_value' => AnyValueDto::bool($proto->getBoolValue()),
            'bytes_value' => AnyValueDto::bytes($proto->getBytesValue()),
            'array_value' => $this->decodeArrayValue($proto),
            'kvlist_value' => $this->decodeKvlistValue($proto),
            default => new AnyValueDto(),
        };
    }

    private function decodeArrayValue(AnyValue $proto): AnyValueDto
    {
        $array = $proto->getArrayValue();
        $items = [];
        if (null !== $array) {
            foreach ($array->getValues() as $entry) {
                $items[] = $this->decodeAnyValue($entry);
            }
        }

        return AnyValueDto::array($items);
    }

    private function decodeKvlistValue(AnyValue $proto): AnyValueDto
    {
        $list = $proto->getKvlistValue();
        $items = [];
        if (null !== $list) {
            foreach ($list->getValues() as $entry) {
                $items[] = $this->decodeKeyValue($entry);
            }
        }

        return AnyValueDto::kvlist($items);
    }

    /**
     * google/protobuf treats an unset oneof as `getValue()` returning `''`; an
     * AnyValue field set to a default-valued variant still reports the variant
     * name. Use that to distinguish "no body" from "body = stringValue('')".
     */
    private function anyValueIsSet(AnyValue $proto): bool
    {
        return '' !== $proto->getValue();
    }
}
