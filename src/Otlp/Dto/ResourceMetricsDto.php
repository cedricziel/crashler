<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ResourceMetricsDto
{
    /**
     * @param list<KeyValueDto>     $resourceAttributes
     * @param list<ScopeMetricsDto> $scopeMetrics
     */
    public function __construct(
        public array $resourceAttributes,
        public array $scopeMetrics,
        public ?string $schemaUrl = null,
    ) {
    }
}
