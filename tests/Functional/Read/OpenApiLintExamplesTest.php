<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Console\OpenApiLintExamplesCommand;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Asserts the rendered OpenAPI document carries an example on every
 * in-scope read-API query parameter.
 */
#[CoversClass(OpenApiLintExamplesCommand::class)]
final class OpenApiLintExamplesTest extends KernelTestCase
{
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

    public function testLintPassesOnCurrentSpec(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('app:openapi:lint-examples');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode, 'lint should pass when every in-scope parameter carries an example. Output: '.$tester->getDisplay());
    }
}
