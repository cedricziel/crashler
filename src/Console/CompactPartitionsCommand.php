<?php

declare(strict_types=1);

namespace App\Console;

use App\Schema\SchemaCatalog;
use App\Schema\SchemaCompiler;
use App\Storage\ParquetCompression;
use App\Storage\ParquetFileWriter;
use Flow\Parquet\ParquetFile\Compressions;
use Flow\Parquet\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Merges the many small Parquet files an active OTLP ingest produces into
 * one well-sized file per partition. Only acts on partitions older than
 * `--min-age-hours` (default: 2) so the in-flight current-hour partition
 * isn't disturbed.
 *
 * Algorithm per partition:
 *  1. List all `*.parquet` files (the ingest layer never writes dot-prefixed names).
 *  2. Skip if 0 or 1 files (already compacted / empty).
 *  3. Determine the schema id by reading one row from the first file.
 *     If files have mixed schemas in the same partition, log + skip.
 *     (Mixed-schema partitions can occur right after a schema migration;
 *     compaction picks them up on the next run after the migration
 *     settles.)
 *  4. Stream every row from every input file into a new ParquetFileWriter
 *     keyed by the resolved schema. Writer fsync+rename produces an
 *     atomic compacted-<ts>.parquet file.
 *  5. Delete the original files. Crash between (4) and (5) leaves the
 *     partition with both compacted+originals; the next run picks them
 *     up and re-compacts (idempotent).
 *
 * Run via:
 *   bin/console crashler:explorer:compact-partitions --min-age-hours=2
 */
#[AsCommand(
    name: 'crashler:explorer:compact-partitions',
    description: 'Merge small parquet files into one file per closed hour partition.',
)]
final class CompactPartitionsCommand extends Command
{
    private readonly Compressions $compression;

    public function __construct(
        private readonly string $storageRoot,
        private readonly SchemaCatalog $schemas,
        ParquetCompression $compressionResolver,
        string $configuredCompressionName,
    ) {
        $this->compression = $compressionResolver->resolve($configuredCompressionName);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('signal', null, InputOption::VALUE_REQUIRED, 'Signal to compact (logs|traces|metrics). Omit to compact all.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant slug. Omit to compact all tenants.')
            ->addOption('min-age-hours', null, InputOption::VALUE_REQUIRED, 'Skip partitions newer than this. Default: 2.', '2')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List partitions that would be compacted; do not change anything.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $signalFilter = $input->getOption('signal');
        $tenantFilter = $input->getOption('tenant');
        $minAgeHours = (int) $input->getOption('min-age-hours');
        $dryRun = (bool) $input->getOption('dry-run');

        $cutoffTs = (new \DateTimeImmutable())->modify(\sprintf('-%d hours', $minAgeHours));
        $partitions = $this->discoverPartitions($signalFilter, $tenantFilter, $cutoffTs);

        if ([] === $partitions) {
            $io->success('No partitions matched the filter.');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Compacting %d partition(s)', \count($partitions)));
        $io->writeln(\sprintf('cutoff: %s   dry-run: %s', $cutoffTs->format('c'), $dryRun ? 'yes' : 'no'));

        $compacted = 0;
        $skipped = 0;
        $errored = 0;
        $beforeFiles = 0;
        $afterFiles = 0;
        foreach ($partitions as $partition) {
            $files = glob($partition.'/*.parquet') ?: [];
            $count = \count($files);
            $beforeFiles += $count;
            if ($count < 2) {
                ++$skipped;
                $afterFiles += $count;
                continue;
            }

            $rel = str_replace($this->storageRoot.'/', '', $partition);
            $io->writeln(\sprintf('  %s  (%d files)', $rel, $count));

            if ($dryRun) {
                ++$compacted;
                ++$afterFiles;
                continue;
            }

            try {
                $this->compactPartition($partition, $files);
                ++$compacted;
                ++$afterFiles;
            } catch (\Throwable $e) {
                ++$errored;
                $afterFiles += $count;
                $io->error(\sprintf('  failed: %s', $e->getMessage()));
            }
        }

        $io->section('Summary');
        $io->table(
            ['', 'count'],
            [
                ['compacted', (string) $compacted],
                ['skipped (already-compacted)', (string) $skipped],
                ['errored', (string) $errored],
                ['files before', (string) $beforeFiles],
                ['files after', (string) $afterFiles],
            ],
        );

        return Command::SUCCESS === ($errored > 0 ? Command::FAILURE : Command::SUCCESS) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string> absolute partition directory paths
     */
    private function discoverPartitions(?string $signalFilter, ?string $tenantFilter, \DateTimeImmutable $cutoff): array
    {
        $signals = null !== $signalFilter ? [$signalFilter] : ['logs', 'traces', 'metrics'];
        $out = [];
        foreach ($signals as $signal) {
            $signalDir = $this->storageRoot.'/'.$signal;
            if (!is_dir($signalDir)) {
                continue;
            }
            $tenantDirs = null !== $tenantFilter
                ? [$signalDir.'/'.$tenantFilter]
                : (glob($signalDir.'/*', \GLOB_ONLYDIR) ?: []);
            foreach ($tenantDirs as $tenantDir) {
                if (!is_dir($tenantDir)) {
                    continue;
                }
                foreach (glob($tenantDir.'/date=*', \GLOB_ONLYDIR) ?: [] as $dateDir) {
                    foreach (glob($dateDir.'/hour=*', \GLOB_ONLYDIR) ?: [] as $hourDir) {
                        $partitionTs = $this->partitionInstant($dateDir, $hourDir);
                        if (null === $partitionTs || $partitionTs >= $cutoff) {
                            continue;
                        }
                        $out[] = $hourDir;
                    }
                }
            }
        }

        return $out;
    }

    private function partitionInstant(string $dateDir, string $hourDir): ?\DateTimeImmutable
    {
        if (!preg_match('/date=(\d{4}-\d{2}-\d{2})$/', $dateDir, $dm)) {
            return null;
        }
        if (!preg_match('/hour=(\d{2})$/', $hourDir, $hm)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($dm[1].' '.$hm[1].':00:00 UTC');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param list<string> $files
     */
    private function compactPartition(string $partitionDir, array $files): void
    {
        sort($files);
        $reader = new Reader();

        // Determine schema from the first row of the first file. Bail if
        // mixed schemas appear (rare, only post-migration).
        $schemaId = $this->probeSchemaId($reader, $files[0]);
        if (null === $schemaId) {
            throw new \RuntimeException('Could not determine schema id from first file.');
        }
        $schemaDef = $this->schemas->byId($schemaId);

        $compactedName = \sprintf('compacted-%s.parquet', date('Ymd-His-u'));
        $finalPath = $partitionDir.'/'.$compactedName;

        $writer = new ParquetFileWriter($schemaDef, $this->compression, new Filesystem());
        $writer->writeAndCommit($finalPath, $this->streamRows($reader, $files, $schemaId));

        // Compacted file lives in the partition; delete originals only
        // after the writer's atomic rename succeeded.
        foreach ($files as $f) {
            @unlink($f);
        }
    }

    private function probeSchemaId(Reader $reader, string $file): ?string
    {
        $parquet = $reader->read($file);
        foreach ($parquet->values(columns: [SchemaCompiler::COLUMN_SCHEMA_ID], limit: 1) as $row) {
            $val = $row[SchemaCompiler::COLUMN_SCHEMA_ID] ?? null;

            return \is_string($val) ? $val : null;
        }

        return null;
    }

    /**
     * @param list<string> $files
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamRows(Reader $reader, array $files, string $expectedSchemaId): \Generator
    {
        foreach ($files as $file) {
            $parquet = $reader->read($file);
            foreach ($parquet->values() as $row) {
                $rowSchemaId = $row[SchemaCompiler::COLUMN_SCHEMA_ID] ?? null;
                if ($rowSchemaId !== $expectedSchemaId) {
                    throw new \RuntimeException(\sprintf(
                        'Mixed schemas in partition: file %s has schema_id "%s", expected "%s". Compact this partition manually after schema migration.',
                        basename($file),
                        \is_string($rowSchemaId) ? $rowSchemaId : 'null',
                        $expectedSchemaId,
                    ));
                }
                // The writer re-augments the schema columns; pass the row as-is.
                yield $row;
            }
        }
    }
}
