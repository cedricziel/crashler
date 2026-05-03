<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Otlp\AttributeColumnExtractor;
use App\Schema\SchemaCatalog;
use App\Schema\SchemaDefinition;

/**
 * Lazy-cached helpers for tests that need the real shipped logs/v1 schema.
 *
 * Loading from disk once per test process is plenty fast; this avoids each
 * test reinventing a minimal schema fixture.
 */
final class LogsSchemaFixture
{
    private static ?SchemaDefinition $logsV1 = null;

    public static function logsV1(): SchemaDefinition
    {
        if (null !== self::$logsV1) {
            return self::$logsV1;
        }

        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 2).'/config/schemas');

        return self::$logsV1 = $catalog->latestFor('logs');
    }

    public static function logsV1Extractor(): AttributeColumnExtractor
    {
        return new AttributeColumnExtractor(self::logsV1());
    }
}
