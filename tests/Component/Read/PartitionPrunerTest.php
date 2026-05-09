<?php

declare(strict_types=1);

namespace App\Tests\Component\Read;

use App\Read\Compute\PartitionPruner;
use App\Read\Criteria\TimeWindow;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PartitionPruner::class)]
final class PartitionPrunerTest extends TestCase
{
    use TempStorageRoot;

    public function testReturnsOnlyDirectoriesInsideTheWindow(): void
    {
        // Build a 3-day fixture tree with 24 hours per day for tenant `acme`.
        $root = $this->tempStorageRoot();
        $base = $root.'/logs/acme';
        foreach (['2026-05-07', '2026-05-08', '2026-05-09'] as $date) {
            for ($h = 0; $h < 24; ++$h) {
                $hour = str_pad((string) $h, 2, '0', \STR_PAD_LEFT);
                $dir = "$base/date=$date/hour=$hour";
                mkdir($dir, 0o750, recursive: true);
                touch("$dir/part-test.parquet");
            }
        }

        // Window: 2026-05-08T22:00:00Z .. 2026-05-09T02:00:00Z (4 hours,
        // crossing midnight UTC).
        $since = (int) (new \DateTimeImmutable('2026-05-08T22:00:00Z'))->format('U') * 1_000_000_000;
        $until = (int) (new \DateTimeImmutable('2026-05-09T02:00:00Z'))->format('U') * 1_000_000_000;
        $window = new TimeWindow($since, $until);

        $pruner = new PartitionPruner($root);
        $globs = $pruner->globsFor('acme', 'logs', $window);

        // Should yield 5 hour-partitions: 22, 23 on 05-08 and 00, 01, 02 on 05-09.
        sort($globs);
        self::assertSame([
            "$base/date=2026-05-08/hour=22/part-*.parquet",
            "$base/date=2026-05-08/hour=23/part-*.parquet",
            "$base/date=2026-05-09/hour=00/part-*.parquet",
            "$base/date=2026-05-09/hour=01/part-*.parquet",
            "$base/date=2026-05-09/hour=02/part-*.parquet",
        ], $globs);
    }

    public function testTenantScopeCannotEscape(): void
    {
        $root = $this->tempStorageRoot();
        $window = new TimeWindow(0, 1_000_000_000);

        $pruner = new PartitionPruner($root);

        // Globs SHALL contain the tenant slug literally; no `..` traversal,
        // no concatenation that could allow path injection. The scanner
        // operating on these globs cannot reach outside the tenant tree.
        foreach ($pruner->globsFor('acme', 'logs', $window) as $glob) {
            self::assertStringStartsWith($root.'/logs/acme/', $glob);
            self::assertStringNotContainsString('..', $glob);
        }
    }

    public function testNonExistentPartitionDirsAreStillReturnedAsGlobs(): void
    {
        // Pruner doesn't probe filesystem — it computes the *expected* glob
        // set. The scanner is responsible for handling missing directories
        // gracefully (no rows = empty).
        $root = $this->tempStorageRoot();
        $window = new TimeWindow(
            (int) (new \DateTimeImmutable('2026-05-09T10:00:00Z'))->format('U') * 1_000_000_000,
            (int) (new \DateTimeImmutable('2026-05-09T11:00:00Z'))->format('U') * 1_000_000_000,
        );

        $pruner = new PartitionPruner($root);
        $globs = $pruner->globsFor('newcomer', 'logs', $window);

        self::assertCount(2, $globs); // hours 10 and 11
        self::assertSame("$root/logs/newcomer/date=2026-05-09/hour=10/part-*.parquet", $globs[0]);
    }
}
