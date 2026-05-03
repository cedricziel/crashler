<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\UlidFilenameGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UlidFilenameGenerator::class)]
final class UlidFilenameGeneratorTest extends TestCase
{
    public function testGeneratesCrockfordBase32StringOf26Chars(): void
    {
        $generator = new UlidFilenameGenerator();

        $value = $generator->generate();

        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value);
    }

    public function testTwoConsecutiveCallsAreLexicographicallyMonotonic(): void
    {
        $generator = new UlidFilenameGenerator();

        $a = $generator->generate();
        $b = $generator->generate();

        self::assertLessThan($b, $a);
    }

    public function testManyCallsAllProduceDistinctValues(): void
    {
        $generator = new UlidFilenameGenerator();

        $values = [];
        for ($i = 0; $i < 64; ++$i) {
            $values[] = $generator->generate();
        }

        self::assertCount(64, array_unique($values));
    }
}
