<?php

declare(strict_types=1);

namespace App\Tests\Unit\DependencyInjection;

use App\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testEmptyConfigYieldsDefaults(): void
    {
        $processed = $this->processor->processConfiguration($this->configuration, [[]]);

        self::assertFalse($processed['signup']['enabled']);
        self::assertNull($processed['signup']['terms_url']);
        self::assertSame(7, $processed['invitations']['expiry_days']);
        self::assertNull($processed['invitations']['from_address']);
    }

    public function testSignupEnabledOverride(): void
    {
        $processed = $this->processor->processConfiguration($this->configuration, [[
            'signup' => ['enabled' => true, 'terms_url' => 'https://crashler.test/terms'],
        ]]);

        self::assertTrue($processed['signup']['enabled']);
        self::assertSame('https://crashler.test/terms', $processed['signup']['terms_url']);
    }

    public function testInvitationOverrides(): void
    {
        $processed = $this->processor->processConfiguration($this->configuration, [[
            'invitations' => ['expiry_days' => 21, 'from_address' => 'noreply@crashler.test'],
        ]]);

        self::assertSame(21, $processed['invitations']['expiry_days']);
        self::assertSame('noreply@crashler.test', $processed['invitations']['from_address']);
    }

    public function testExpiryDaysMustBeAtLeastOne(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [[
            'invitations' => ['expiry_days' => 0],
        ]]);
    }

    public function testUnknownTopLevelKeyRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/unrecognized option/i');

        $this->processor->processConfiguration($this->configuration, [[
            'tenants' => ['acme' => ['name' => 'Acme', 'token_hashes' => []]],
        ]]);
    }
}
