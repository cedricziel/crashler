<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Otlp\AttributeColumnExtractor;
use App\Schema\SchemaCatalog;
use App\Schema\SchemaDefinition;

/**
 * Lazy-cached helpers for tests that need the real shipped traces/v1 schema.
 */
final class TracesSchemaFixture
{
    private static ?SchemaDefinition $tracesV1 = null;

    public static function tracesV1(): SchemaDefinition
    {
        if (null !== self::$tracesV1) {
            return self::$tracesV1;
        }

        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 2).'/config/schemas');

        return self::$tracesV1 = $catalog->latestFor('traces');
    }

    public static function tracesV1Extractor(): AttributeColumnExtractor
    {
        return new AttributeColumnExtractor(self::tracesV1());
    }
}
