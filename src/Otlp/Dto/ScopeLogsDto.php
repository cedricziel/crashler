<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ScopeLogsDto
{
    /**
     * @param list<LogRecordDto> $logRecords
     */
    public function __construct(
        public ?string $scopeName,
        public ?string $scopeVersion,
        public array $logRecords,
        public ?string $schemaUrl = null,
    ) {
    }
}
