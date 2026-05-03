<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Otlp\AttributeColumnExtractor;
use App\Schema\SchemaCatalog;
use App\Schema\SchemaDefinition;

/**
 * Lazy-cached helpers for tests that need the real shipped metrics/v1 schema.
 */
final class MetricsSchemaFixture
{
    private static ?SchemaDefinition $metricsV1 = null;

    public static function metricsV1(): SchemaDefinition
    {
        if (null !== self::$metricsV1) {
            return self::$metricsV1;
        }

        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 2).'/config/schemas');

        return self::$metricsV1 = $catalog->latestFor('metrics');
    }

    public static function metricsV1Extractor(): AttributeColumnExtractor
    {
        return new AttributeColumnExtractor(self::metricsV1());
    }
}
