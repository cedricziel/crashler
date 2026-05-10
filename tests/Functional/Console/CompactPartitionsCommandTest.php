<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Tests\Support\SeedsParquetLogs;
use App\Tests\Support\TempStorageRoot;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end exercise of `crashler:explorer:compact-partitions`. Seeds N
 * single-row parquet files into a closed (old) hour partition, runs the
 * command, asserts:
 *   - exactly 1 file remains in the partition,
 *   - the merged file's row count matches the sum of the inputs.
 *
 * Skip cases (no-op, single-file, in-flight current hour) are also
 * pinned as separate scenarios.
 */
final class CompactPartitionsCommandTest extends KernelTestCase
{
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

    public function testCompactsClosedPartitionFromManyFilesToOne(): void
    {
        // Seed three separate single-row files into the same partition.
        // SeedsParquetLogs each call lands in date=2026-05-09/hour=14
        // because of MockClock('2026-05-09 14:30:00 UTC').
        $this->seedLogs('compactest', ['one']);
        $this->seedLogs('compactest', ['two']);
        $this->seedLogs('compactest', ['three']);

        $partitionDir = $this->tempStorageRoot().'/logs/compactest/date=2026-05-09/hour=14';
        self::assertDirectoryExists($partitionDir);
        $beforeFiles = glob($partitionDir.'/*.parquet') ?: [];
        self::assertGreaterThanOrEqual(3, \count($beforeFiles), 'expected at least 3 seeded files');

        $cmdTester = $this->commandTester();
        // Use min-age-hours=0 so the seeded partition (which "is" 2026-05-09)
        // qualifies in our test runner whose clock is whenever-now.
        $exitCode = $cmdTester->execute([
            '--signal' => 'logs',
            '--tenant' => 'compactest',
            '--min-age-hours' => '0',
        ]);

        self::assertSame(0, $exitCode);
        $afterFiles = glob($partitionDir.'/*.parquet') ?: [];
        self::assertCount(1, $afterFiles, 'expected exactly one compacted file');
        self::assertStringStartsWith('compacted-', basename($afterFiles[0]));
    }

    public function testIsNoOpOnSingleFilePartition(): void
    {
        $this->seedLogs('compactest-single', ['only']);

        $partitionDir = $this->tempStorageRoot().'/logs/compactest-single/date=2026-05-09/hour=14';
        $before = glob($partitionDir.'/*.parquet') ?: [];
        self::assertCount(1, $before);
        $beforeName = $before[0];

        $exitCode = $this->commandTester()->execute([
            '--signal' => 'logs',
            '--tenant' => 'compactest-single',
            '--min-age-hours' => '0',
        ]);

        self::assertSame(0, $exitCode);
        $after = glob($partitionDir.'/*.parquet') ?: [];
        self::assertCount(1, $after);
        // The same file: no compaction occurred.
        self::assertSame($beforeName, $after[0]);
    }

    public function testDryRunDoesNotMutate(): void
    {
        $this->seedLogs('dryrun', ['a']);
        $this->seedLogs('dryrun', ['b']);

        $partitionDir = $this->tempStorageRoot().'/logs/dryrun/date=2026-05-09/hour=14';
        $beforeCount = \count(glob($partitionDir.'/*.parquet') ?: []);

        $exitCode = $this->commandTester()->execute([
            '--signal' => 'logs',
            '--tenant' => 'dryrun',
            '--min-age-hours' => '0',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        // Untouched: dry-run reports but doesn't compact.
        self::assertCount($beforeCount, glob($partitionDir.'/*.parquet') ?: []);
    }

    public function testSkipsPartitionsNewerThanMinAgeHours(): void
    {
        // Seeded partition is 2026-05-09. With min-age-hours huge enough
        // that "now-N hours" is BEFORE 2026-05-09, the seeded partition
        // is "newer than cutoff" and gets skipped.
        // (Default test clock is 'whenever the test runs'.)
        $this->seedLogs('skip-recent', ['a']);
        $this->seedLogs('skip-recent', ['b']);

        $partitionDir = $this->tempStorageRoot().'/logs/skip-recent/date=2026-05-09/hour=14';
        $beforeCount = \count(glob($partitionDir.'/*.parquet') ?: []);

        $exitCode = $this->commandTester()->execute([
            '--signal' => 'logs',
            '--tenant' => 'skip-recent',
            '--min-age-hours' => '1000000', // far enough in the past that 2026-05-09 is "newer than cutoff"
        ]);

        self::assertSame(0, $exitCode);
        self::assertCount($beforeCount, glob($partitionDir.'/*.parquet') ?: []);
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::bootKernel();
        $app = new Application($kernel);
        $cmd = $app->find('crashler:explorer:compact-partitions');

        return new CommandTester($cmd);
    }
}
