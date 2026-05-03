<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

final readonly class ExportLogsServiceRequestDto
{
    /**
     * @param list<ResourceLogsDto> $resourceLogs
     */
    public function __construct(
        public array $resourceLogs,
    ) {
    }
}
