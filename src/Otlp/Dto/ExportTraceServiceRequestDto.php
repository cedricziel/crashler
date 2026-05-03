<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ExportTraceServiceRequestDto
{
    /**
     * @param list<ResourceSpansDto> $resourceSpans
     */
    public function __construct(
        public array $resourceSpans,
    ) {
    }
}
