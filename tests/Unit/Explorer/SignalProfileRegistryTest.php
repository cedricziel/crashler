<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explorer;

use App\Explorer\LogsProfile;
use App\Explorer\MetricsProfile;
use App\Explorer\SignalProfileRegistry;
use App\Explorer\TracesProfile;
use App\Explorer\UnknownSignalException;
use PHPUnit\Framework\TestCase;

final class SignalProfileRegistryTest extends TestCase
{
    public function testResolvesEachKnownSignal(): void
    {
        $registry = new SignalProfileRegistry([new LogsProfile(), new TracesProfile(), new MetricsProfile()]);

        self::assertInstanceOf(LogsProfile::class, $registry->get('logs'));
        self::assertInstanceOf(TracesProfile::class, $registry->get('traces'));
        self::assertInstanceOf(MetricsProfile::class, $registry->get('metrics'));
    }

    public function testKnownSignalsListMatchesRegisteredProfiles(): void
    {
        $registry = new SignalProfileRegistry([new LogsProfile(), new TracesProfile(), new MetricsProfile()]);

        self::assertSame(['logs', 'traces', 'metrics'], $registry->knownSignals());
    }

    public function testUnknownSignalThrows(): void
    {
        $registry = new SignalProfileRegistry([new LogsProfile()]);

        $this->expectException(UnknownSignalException::class);
        $this->expectExceptionMessage('foo');
        $registry->get('foo');
    }

    public function testEachProfileDeclaresExactlyFiveKpis(): void
    {
        foreach ([new LogsProfile(), new TracesProfile(), new MetricsProfile()] as $profile) {
            self::assertCount(5, $profile->kpis(), \sprintf('%s should declare 5 KPIs, got %d', $profile->name(), \count($profile->kpis())));
        }
    }
}
