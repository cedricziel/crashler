<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class SpanDto
{
    /**
     * @param non-empty-string      $traceId      raw 16 bytes (REQUIRED)
     * @param non-empty-string      $spanId       raw 8 bytes (REQUIRED)
     * @param non-empty-string|null $parentSpanId raw 8 bytes when present
     * @param list<KeyValueDto>     $attributes
     * @param list<SpanEventDto>    $events
     * @param list<SpanLinkDto>     $links
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public ?string $traceState,
        public ?int $flags,
        public string $name,
        public int $kind,
        public int $startTimeUnixNano,
        public int $endTimeUnixNano,
        public array $attributes,
        public array $events,
        public array $links,
        public ?SpanStatusDto $status,
        public int $droppedAttributesCount,
        public int $droppedEventsCount,
        public int $droppedLinksCount,
    ) {
    }
}
