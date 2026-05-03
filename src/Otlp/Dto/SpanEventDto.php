<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class SpanEventDto
{
    /**
     * @param list<KeyValueDto> $attributes
     */
    public function __construct(
        public int $timeUnixNano,
        public string $name,
        public array $attributes,
        public int $droppedAttributesCount,
    ) {
    }
}
