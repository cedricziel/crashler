<?php

declare(strict_types=1);

namespace App\Tests\Component\Storage;

use App\Storage\ParquetFileWriter;
use App\Storage\ParquetSchema;
use App\Tests\Support\TempStorageRoot;
use Flow\Parquet\ParquetFile\Compressions;
use Flow\Parquet\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParquetFileWriter::class)]
final class ParquetFileWriterTest extends TestCase
{
    use TempStorageRoot;

    /**
     * @return list<array<string, mixed>>
     */
    private function exampleRows(): array
    {
        return [
            [
                'time_unix_nano' => 1714752000000000000,
                'observed_time_unix_nano' => null,
                'severity_number' => 9,
                'severity_text' => 'INFO',
                'body_json' => '"hello"',
                'service_name' => 'checkout',
                'scope_name' => 'app',
                'scope_version' => '1.0',
                'trace_id_hex' => '5b8aa5a2d2c872e8321cf37308d69df2',
                'span_id_hex' => '051581bf3cb55c13',
                'flags' => 0,
                'resource_attributes_json' => '{"service.name":"checkout"}',
                'attributes_json' => '{"http.status_code":500}',
            ],
            [
                'time_unix_nano' => 1714752000000000001,
                'observed_time_unix_nano' => 1714752000000000002,
                'severity_number' => 17,
                'severity_text' => 'ERROR',
                'body_json' => '{"intValue":"42"}',
                'service_name' => 'checkout',
                'scope_name' => 'app',
                'scope_version' => '1.0',
                'trace_id_hex' => null,
                'span_id_hex' => null,
                'flags' => null,
                'resource_attributes_json' => '{"service.name":"checkout"}',
                'attributes_json' => '{}',
            ],
        ];
    }

    public function testWriteCommitProducesFileAtFinalPathReadableByReader(): void
    {
        $writer = new ParquetFileWriter(ParquetSchema::definition(), Compressions::GZIP);

        $finalPath = $this->tempStorageRoot().'/out.parquet';

        $writer->writeAndCommit($finalPath, $this->exampleRows());

        self::assertFileExists($finalPath);
        self::assertFileDoesNotExist($finalPath.'.tmp');

        $reader = new Reader();
        $file = $reader->read($finalPath);
        $rows = iterator_to_array($file->values(), false);

        self::assertCount(2, $rows);
        self::assertSame(1714752000000000000, $rows[0]['time_unix_nano']);
        self::assertSame('INFO', $rows[0]['severity_text']);
        self::assertSame('"hello"', $rows[0]['body_json']);
        self::assertSame('5b8aa5a2d2c872e8321cf37308d69df2', $rows[0]['trace_id_hex']);
        self::assertSame('{"service.name":"checkout"}', $rows[0]['resource_attributes_json']);
        self::assertSame('{"http.status_code":500}', $rows[0]['attributes_json']);
        self::assertSame(1714752000000000001, $rows[1]['time_unix_nano']);
        self::assertSame('ERROR', $rows[1]['severity_text']);
    }

    public function testWriteAndCommitFailsWhenParentDirIsMissing(): void
    {
        $writer = new ParquetFileWriter(ParquetSchema::definition(), Compressions::GZIP);

        $finalPath = $this->tempStorageRoot().'/missing/out.parquet';

        $this->expectException(\Throwable::class);

        $writer->writeAndCommit($finalPath, $this->exampleRows());
    }

    public function testWriteAndCommitDoesNotLeaveTmpOnFailure(): void
    {
        $writer = new ParquetFileWriter(ParquetSchema::definition(), Compressions::GZIP);

        $finalPath = $this->tempStorageRoot().'/missing-dir/out.parquet';

        try {
            $writer->writeAndCommit($finalPath, $this->exampleRows());
            self::fail('expected exception');
        } catch (\Throwable) {
            // expected
        }

        self::assertFileDoesNotExist($finalPath);
        self::assertFileDoesNotExist($finalPath.'.tmp');
    }

    public function testWriteAndCommitOverEmptyRowsStillWritesFile(): void
    {
        $writer = new ParquetFileWriter(ParquetSchema::definition(), Compressions::GZIP);

        $finalPath = $this->tempStorageRoot().'/empty.parquet';

        $writer->writeAndCommit($finalPath, []);

        self::assertFileExists($finalPath);
        self::assertFileDoesNotExist($finalPath.'.tmp');
    }
}
