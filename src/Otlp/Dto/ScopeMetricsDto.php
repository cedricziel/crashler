<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ScopeMetricsDto
{
    /**
     * @param list<MetricDto> $metrics
     */
    public function __construct(
        public ?string $scopeName,
        public ?string $scopeVersion,
        public array $metrics,
        public ?string $schemaUrl = null,
    ) {
    }
}
