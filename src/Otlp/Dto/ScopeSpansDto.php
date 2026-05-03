<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ScopeSpansDto
{
    /**
     * @param list<SpanDto> $spans
     */
    public function __construct(
        public ?string $scopeName,
        public ?string $scopeVersion,
        public array $spans,
        public ?string $schemaUrl = null,
    ) {
    }
}
