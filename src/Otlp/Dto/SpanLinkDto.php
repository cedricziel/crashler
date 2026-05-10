<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class SpanLinkDto
{
    /**
     * @param non-empty-string  $traceId    raw 16 bytes
     * @param non-empty-string  $spanId     raw 8 bytes
     * @param list<KeyValueDto> $attributes
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $traceState,
        public array $attributes,
        public int $droppedAttributesCount,
        public ?int $flags,
    ) {
    }
}
