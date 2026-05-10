<?php

declare(strict_types=1);

namespace App\Tests\Component\DependencyInjection;

use App\DependencyInjection\CrashlerExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(CrashlerExtension::class)]
final class CrashlerExtensionTest extends TestCase
{
    public function testSelfServiceParametersExposedWithDefaults(): void
    {
        $container = $this->buildContainer([]);

        self::assertFalse($container->getParameter('crashler.signup.enabled'));
        self::assertNull($container->getParameter('crashler.signup.terms_url'));
        self::assertSame(7, $container->getParameter('crashler.invitations.expiry_days'));
        self::assertNull($container->getParameter('crashler.invitations.from_address'));
    }

    public function testSelfServiceParametersAcceptOverrides(): void
    {
        $container = $this->buildContainer([
            'signup' => ['enabled' => true, 'terms_url' => 'https://crashler.test/terms'],
            'invitations' => ['expiry_days' => 14, 'from_address' => 'noreply@crashler.test'],
        ]);

        self::assertTrue($container->getParameter('crashler.signup.enabled'));
        self::assertSame('https://crashler.test/terms', $container->getParameter('crashler.signup.terms_url'));
        self::assertSame(14, $container->getParameter('crashler.invitations.expiry_days'));
        self::assertSame('noreply@crashler.test', $container->getParameter('crashler.invitations.from_address'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildContainer(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new CrashlerExtension();
        $extension->load([$config], $container);

        return $container;
    }
}
