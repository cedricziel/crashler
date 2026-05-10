<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Console\CreateUserCommand;
use App\Repository\UserRepository;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CreateUserCommand::class)]
final class CreateUserCommandTest extends DatabaseTestCase
{
    public function testCreatesAdminUserWithProvidedPassword(): void
    {
        $tester = $this->buildTester();

        $exit = $tester->execute([
            '--email' => 'admin@example.com',
            '--admin' => true,
            '--password' => 'secret-12345',
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('Created user admin@example.com', $tester->getDisplay());
        self::assertStringContainsString('ROLE_ADMIN', $tester->getDisplay());

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $persisted = $users->findOneByEmail('admin@example.com');
        self::assertNotNull($persisted);
        self::assertContains('ROLE_ADMIN', $persisted->getRoles());
        self::assertNotSame('secret-12345', $persisted->getPassword());
    }

    public function testEmailCollisionRejected(): void
    {
        $this->createUser('admin@example.com', 'first', admin: true);

        $tester = $this->buildTester();
        $exit = $tester->execute([
            '--email' => 'admin@example.com',
            '--password' => 'second',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testNonInteractivePasswordlessRunRejected(): void
    {
        $tester = $this->buildTester();
        $tester->setInputs([]);

        $exit = $tester->execute(
            ['--email' => 'admin@example.com'],
            ['interactive' => false],
        );

        self::assertSame(1, $exit);
        self::assertStringContainsString('--password', $tester->getDisplay());
    }

    public function testMissingEmailRejected(): void
    {
        $tester = $this->buildTester();

        $exit = $tester->execute([
            '--password' => 'secret',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--email', $tester->getDisplay());
    }

    public function testNonAdminUserDefaultsToRoleUserOnly(): void
    {
        $tester = $this->buildTester();
        $tester->execute([
            '--email' => 'plain@example.com',
            '--password' => 'secret-12345',
        ]);

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $persisted = $users->findOneByEmail('plain@example.com');
        self::assertNotNull($persisted);
        self::assertSame(['ROLE_USER'], $persisted->getRoles());
    }

    private function buildTester(): CommandTester
    {
        $kernel = static::$kernel;
        if (null === $kernel) {
            $kernel = static::bootKernel();
        }
        $application = new Application($kernel);
        $command = $application->find('crashler:user:create');

        return new CommandTester($command);
    }
}
