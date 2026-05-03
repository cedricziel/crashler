<?php

declare(strict_types=1);

namespace App\Tests\Unit\DependencyInjection;

use App\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    private Processor $processor;
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    public function testProcessesValidConfigWithTwoTenants(): void
    {
        $config = [
            'tenants' => [
                'acme' => [
                    'name' => 'Acme Corp',
                    'token_hashes' => [str_repeat('a', 64), str_repeat('b', 64)],
                ],
                'widget-co' => [
                    'name' => 'Widget Co',
                    'token_hashes' => [str_repeat('c', 64)],
                ],
            ],
        ];

        $processed = $this->processor->processConfiguration($this->configuration, [$config]);

        self::assertSame('Acme Corp', $processed['tenants']['acme']['name']);
        self::assertSame([str_repeat('a', 64), str_repeat('b', 64)], $processed['tenants']['acme']['token_hashes']);
        self::assertSame('Widget Co', $processed['tenants']['widget-co']['name']);
    }

    public function testEmptyTenantsIsAccepted(): void
    {
        $processed = $this->processor->processConfiguration($this->configuration, [['tenants' => []]]);

        self::assertSame([], $processed['tenants']);
    }

    public function testTenantsKeyIsOptional(): void
    {
        $processed = $this->processor->processConfiguration($this->configuration, [[]]);

        self::assertSame([], $processed['tenants']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidSlugProvider(): iterable
    {
        yield 'uppercase' => ['Acme'];
        yield 'leading digit' => ['9acme'];
        yield 'too short' => ['ab'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'trailing hyphen' => ['acme-'];
        yield 'underscore' => ['acme_corp'];
        yield 'dot' => ['acme.corp'];
        yield 'leading hyphen' => ['-acme'];
        yield 'double hyphen at end' => ['acme--'];
        yield 'spaces' => ['acme corp'];
    }

    #[DataProvider('invalidSlugProvider')]
    public function testInvalidSlugRejected(string $slug): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/slug/i');

        $this->processor->processConfiguration($this->configuration, [
            ['tenants' => [
                $slug => ['name' => 'X', 'token_hashes' => [str_repeat('a', 64)]],
            ]],
        ]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validSlugProvider(): iterable
    {
        yield 'minimum length' => ['abc'];
        yield 'maximum length' => [str_repeat('a', 32)];
        yield 'with hyphen' => ['acme-corp'];
        yield 'with digits after first char' => ['acme1'];
    }

    #[DataProvider('validSlugProvider')]
    public function testValidSlugAccepted(string $slug): void
    {
        $processed = $this->processor->processConfiguration($this->configuration, [
            ['tenants' => [
                $slug => ['name' => 'X', 'token_hashes' => [str_repeat('a', 64)]],
            ]],
        ]);

        self::assertArrayHasKey($slug, $processed['tenants']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidHashProvider(): iterable
    {
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'uppercase' => [str_repeat('A', 64)];
        yield 'non-hex char' => [str_repeat('z', 64)];
        yield 'empty' => [''];
        yield 'mixed valid + invalid char' => [str_repeat('a', 63).'z'];
    }

    #[DataProvider('invalidHashProvider')]
    public function testInvalidHashRejected(string $hash): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/hash|hex/i');

        $this->processor->processConfiguration($this->configuration, [
            ['tenants' => [
                'acme' => ['name' => 'X', 'token_hashes' => [$hash]],
            ]],
        ]);
    }

    public function testRejectsCrossTenantDuplicateHash(): void
    {
        $hash = str_repeat('a', 64);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/duplicate.*token|acme.*widget|widget.*acme/i');

        $this->processor->processConfiguration($this->configuration, [
            ['tenants' => [
                'acme' => ['name' => 'A', 'token_hashes' => [$hash]],
                'widget' => ['name' => 'W', 'token_hashes' => [$hash]],
            ]],
        ]);
    }

    public function testTenantWithoutTokenHashesIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [
            ['tenants' => [
                'acme' => ['name' => 'A'],
            ]],
        ]);
    }

    public function testTenantWithEmptyTokenHashesIsAccepted(): void
    {
        // Empty token list = the tenant exists in config but no token authorizes it.
        // Useful for "freezing" a tenant during incident response.
        $processed = $this->processor->processConfiguration($this->configuration, [
            ['tenants' => [
                'acme' => ['name' => 'A', 'token_hashes' => []],
            ]],
        ]);

        self::assertSame([], $processed['tenants']['acme']['token_hashes']);
    }
}
