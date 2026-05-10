<?php

declare(strict_types=1);

namespace App\Explorer;

final class UnknownSignalException extends \RuntimeException
{
    public function __construct(string $signal)
    {
        parent::__construct(\sprintf('Unknown telemetry signal "%s"; expected one of: logs, traces, metrics.', $signal));
    }
}
