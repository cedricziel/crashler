<?php

declare(strict_types=1);

namespace App\Tests\Component\Read;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class ReadConfigBootTest extends KernelTestCase
{
    public function testReadConfigParametersAreExposedWithDocumentedDefaults(): void
    {
        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        self::assertSame(30, $container->getParameter('crashler.read.max_time_window_days'));
        self::assertSame(1000, $container->getParameter('crashler.read.max_page_size'));
        self::assertSame(24, $container->getParameter('crashler.read.span_lookup_window_hours'));
        self::assertSame(10, $container->getParameter('crashler.read.execution_timeout_seconds'));
        self::assertNotEmpty($container->getParameter('crashler.read.cursor_secret'));
    }

    public function testCursorSecretSourcedFromAppSecret(): void
    {
        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        $cursorSecret = $container->getParameter('crashler.read.cursor_secret');
        $appSecret = $container->getParameter('kernel.secret');

        self::assertSame($appSecret, $cursorSecret, 'cursor_secret should mirror APP_SECRET');
    }

    public function testEnvOverrideForMaxTimeWindowDays(): void
    {
        $_ENV['CRASHLER_READ_MAX_TIME_WINDOW_DAYS'] = '7';
        try {
            self::ensureKernelShutdown();
            $kernel = self::bootKernel();
            self::assertSame(7, $kernel->getContainer()->getParameter('crashler.read.max_time_window_days'));
        } finally {
            unset($_ENV['CRASHLER_READ_MAX_TIME_WINDOW_DAYS']);
            self::ensureKernelShutdown();
        }
    }
}
