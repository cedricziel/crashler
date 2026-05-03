<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ExportMetricsServiceRequestDto
{
    /**
     * @param list<ResourceMetricsDto> $resourceMetrics
     */
    public function __construct(
        public array $resourceMetrics,
    ) {
    }
}
