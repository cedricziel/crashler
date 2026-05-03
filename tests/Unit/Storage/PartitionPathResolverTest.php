<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\StubFilenameGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PartitionPathResolver::class)]
final class PartitionPathResolverTest extends TestCase
{
    public function testProducesExpectedFinalAndTmpPaths(): void
    {
        $resolver = new PartitionPathResolver(
            clock: new MockClock('2026-05-03 14:37:00 UTC'),
            filenames: new StubFilenameGenerator('01J0001AAAA000000000000000'),
            storageRoot: '/tmp/x',
        );

        $paths = $resolver->resolve(new Tenant('acme', 'Acme Corp'), 'logs');

        self::assertSame(
            '/tmp/x/logs/acme/date=2026-05-03/hour=14/part-01J0001AAAA000000000000000.parquet',
            $paths->finalPath,
        );
        self::assertSame($paths->finalPath.'.tmp', $paths->tmpPath);
        self::assertSame('/tmp/x/logs/acme/date=2026-05-03/hour=14', $paths->partitionDir);
    }

    public function testHourPaddedToTwoDigits(): void
    {
        $resolver = new PartitionPathResolver(
            clock: new MockClock('2026-05-03 01:05:00 UTC'),
            filenames: new StubFilenameGenerator('FILEFILEFILEFILEFILEFILE12'),
            storageRoot: '/r',
        );

        $paths = $resolver->resolve(new Tenant('acme', 'Acme Corp'), 'logs');

        self::assertStringContainsString('hour=01', $paths->finalPath);
    }

    public function testHour09(): void
    {
        $resolver = new PartitionPathResolver(
            clock: new MockClock('2026-05-03 09:00:00 UTC'),
            filenames: new StubFilenameGenerator('FILEFILEFILEFILEFILEFILE12'),
            storageRoot: '/r',
        );

        $paths = $resolver->resolve(new Tenant('acme', 'Acme Corp'), 'logs');

        self::assertStringContainsString('hour=09', $paths->finalPath);
    }

    public function testMidnightBoundaryProducesDifferentDates(): void
    {
        $atEnd = (new PartitionPathResolver(
            clock: new MockClock('2026-05-03 23:59:59 UTC'),
            filenames: new StubFilenameGenerator('A'),
            storageRoot: '/r',
        ))->resolve(new Tenant('acme', 'Acme Corp'), 'logs');

        $atStart = (new PartitionPathResolver(
            clock: new MockClock('2026-05-04 00:00:00 UTC'),
            filenames: new StubFilenameGenerator('B'),
            storageRoot: '/r',
        ))->resolve(new Tenant('acme', 'Acme Corp'), 'logs');

        self::assertStringContainsString('date=2026-05-03', $atEnd->finalPath);
        self::assertStringContainsString('hour=23', $atEnd->finalPath);
        self::assertStringContainsString('date=2026-05-04', $atStart->finalPath);
        self::assertStringContainsString('hour=00', $atStart->finalPath);
    }

    public function testNonUtcClockTimezoneIsCoercedToUtc(): void
    {
        $resolver = new PartitionPathResolver(
            // 02:30 in Berlin (UTC+2 during summer) is 00:30 UTC
            clock: new MockClock('2026-07-15 02:30:00 Europe/Berlin'),
            filenames: new StubFilenameGenerator('U'),
            storageRoot: '/r',
        );

        $paths = $resolver->resolve(new Tenant('acme', 'Acme Corp'), 'logs');

        self::assertStringContainsString('date=2026-07-15', $paths->finalPath);
        self::assertStringContainsString('hour=00', $paths->finalPath);
    }

    public function testDifferentTenantSlugsProduceDifferentPaths(): void
    {
        $resolver = new PartitionPathResolver(
            clock: new MockClock('2026-05-03 14:37:00 UTC'),
            filenames: new StubFilenameGenerator(['SAME', 'SAME']),
            storageRoot: '/r',
        );

        $a = $resolver->resolve(new Tenant('acme', 'Acme'), 'logs');
        $b = $resolver->resolve(new Tenant('widget', 'Widget'), 'logs');

        self::assertStringContainsString('/logs/acme/', $a->finalPath);
        self::assertStringContainsString('/logs/widget/', $b->finalPath);
    }

    public function testSignalSubdirSwitchesTopLevelDirectory(): void
    {
        $resolver = new PartitionPathResolver(
            clock: new MockClock('2026-05-03 14:37:00 UTC'),
            filenames: new StubFilenameGenerator(['SAME', 'SAME', 'SAME']),
            storageRoot: '/r',
        );

        $tenant = new Tenant('acme', 'Acme');

        self::assertStringContainsString('/logs/acme/', $resolver->resolve($tenant, 'logs')->finalPath);
        self::assertStringContainsString('/traces/acme/', $resolver->resolve($tenant, 'traces')->finalPath);
        self::assertStringContainsString('/metrics/acme/', $resolver->resolve($tenant, 'metrics')->finalPath);
    }
}
