<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Schema\SchemaCatalog;

/**
 * Builds an AttributeColumnExtractor wired to the latest schema for a given
 * signal. Used in services.yaml to provide one instance per signal.
 */
final class AttributeColumnExtractorFactory
{
    public function __construct(
        private readonly SchemaCatalog $catalog,
    ) {
    }

    public function forSignal(string $signal): AttributeColumnExtractor
    {
        return new AttributeColumnExtractor($this->catalog->latestFor($signal));
    }
}
