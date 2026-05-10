<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ResourceLogsDto
{
    /**
     * @param list<KeyValueDto>  $resourceAttributes
     * @param list<ScopeLogsDto> $scopeLogs
     */
    public function __construct(
        public array $resourceAttributes,
        public array $scopeLogs,
    ) {
    }
}
