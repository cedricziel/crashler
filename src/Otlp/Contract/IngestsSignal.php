<?php

declare(strict_types=1);

namespace App\Otlp\Contract;

use App\Tenancy\Tenant;

/**
 * Persists a decoded signal request to storage. One implementation per signal
 * (LogsIngestService, TracesIngestService, MetricsIngestService).
 */
interface IngestsSignal
{
    public function write(object $request, Tenant $tenant): void;
}
