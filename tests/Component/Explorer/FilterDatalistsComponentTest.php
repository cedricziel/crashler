<?php

declare(strict_types=1);

namespace App\Tests\Component\Explorer;

use App\Tests\Support\SeedsParquetLogs;
use App\Tests\Support\TempStorageRoot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Hydrates the FilterDatalists Live Component, which emits one
 * <datalist> per filter that should autocomplete (text-kind +
 * declared parquet column).
 */
final class FilterDatalistsComponentTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use SeedsParquetLogs;
    use TempStorageRoot;

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    public function testZeroWindowRendersNoDatalists(): void
    {
        $component = $this->createLiveComponent('Explorer:FilterDatalists', [
            'tenantSlug' => '',
            'signal' => 'logs',
            'windowSinceNs' => 0,
            'windowUntilNs' => 0,
        ]);

        $rendered = (string) $component->render();

        // No suggestions before hydration → no datalists.
        self::assertStringNotContainsString('<datalist', $rendered);
    }

    public function testHydratedWithSeededDataEmitsDatalistForServiceFilter(): void
    {
        // Two rows from `checkout`, one from `payments`.
        $window = $this->seedLogs('test-datalist', ['one', 'two'], service: 'checkout');
        $this->seedLogs('test-datalist', ['three'], service: 'payments', atIso: '2026-05-09 14:30:01 UTC');

        $component = $this->createLiveComponent('Explorer:FilterDatalists', [
            'tenantSlug' => 'test-datalist',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        // Datalist for the `service` filter, containing both seeded values.
        self::assertStringContainsString('id="filter-options-service"', $rendered);
        self::assertStringContainsString('value="checkout"', $rendered);
        self::assertStringContainsString('value="payments"', $rendered);
    }

    public function testEnumFilterStillRendersStaticSuggestions(): void
    {
        // No parquet seeded — but `severity` is KIND_ENUM with hardcoded
        // suggestions, so its datalist MUST still appear.
        $component = $this->createLiveComponent('Explorer:FilterDatalists', [
            'tenantSlug' => 'no-data',
            'signal' => 'logs',
            'windowSinceNs' => 1_000_000_000,
            'windowUntilNs' => 2_000_000_000,
        ]);

        $rendered = (string) $component->render();

        self::assertStringContainsString('id="filter-options-severity"', $rendered);
        // Static enum values from LogsProfile.
        self::assertStringContainsString('value="ERROR"', $rendered);
        self::assertStringContainsString('value="INFO"', $rendered);
    }

    public function testHighCardinalityFilterIsSkipped(): void
    {
        // `traceId` filter has parquetColumn=null → MUST NOT appear.
        $window = $this->seedLogs('test-skip', ['x']);

        $component = $this->createLiveComponent('Explorer:FilterDatalists', [
            'tenantSlug' => 'test-skip',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        self::assertStringNotContainsString('id="filter-options-traceId"', $rendered);
    }
}
