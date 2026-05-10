<?php

declare(strict_types=1);

namespace App\Explorer;

final readonly class TableColumn
{
    public function __construct(
        public string $key,         // resource attribute name, e.g. 'service.name' or 'time'
        public string $label,
        public ?string $cssWidth = null,    // '20ch', '6em', etc.
        public bool $monospace = false,
    ) {
    }
}
