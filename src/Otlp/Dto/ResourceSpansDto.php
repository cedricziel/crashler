<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ResourceSpansDto
{
    /**
     * @param list<KeyValueDto>   $resourceAttributes
     * @param list<ScopeSpansDto> $scopeSpans
     */
    public function __construct(
        public array $resourceAttributes,
        public array $scopeSpans,
        public ?string $schemaUrl = null,
    ) {
    }
}
