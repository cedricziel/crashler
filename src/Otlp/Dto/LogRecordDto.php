<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class LogRecordDto
{
    /**
     * @param list<KeyValueDto>     $attributes
     * @param non-empty-string|null $traceId    raw 16 bytes when present
     * @param non-empty-string|null $spanId     raw 8 bytes when present
     */
    public function __construct(
        public int $timeUnixNano,
        public ?int $observedTimeUnixNano,
        public ?int $severityNumber,
        public ?string $severityText,
        public ?AnyValueDto $body,
        public array $attributes,
        public int $droppedAttributesCount,
        public ?string $traceId,
        public ?string $spanId,
        public ?int $flags,
    ) {
    }
}
