<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Console\ImportYamlTenantsCommand;
use App\Entity\Tenant as TenantEntity;
use App\Entity\TenantToken;
use App\Repository\OrgRepository;
use App\Repository\TenantRepository;
use App\Repository\TenantTokenRepository;
use App\Tests\Support\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ImportYamlTenantsCommand::class)]
final class ImportYamlTenantsCommandTest extends DatabaseTestCase
{
    public function testDryRunDoesNotPersist(): void
    {
        $this->createOrg('cedricziel', 'Cedric Ziel');

        $tester = $this->buildTester([
            'default' => ['name' => 'Default tenant', 'token_hashes' => [str_repeat('a', 64)]],
            'cedric-crashler' => ['name' => 'cedric-crashler', 'token_hashes' => [str_repeat('b', 64)]],
        ]);

        $exit = $tester->execute(['--org' => 'cedricziel']);
        self::assertSame(0, $exit, $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry-run', $display);
        self::assertStringContainsString('Would import 2 tenant(s)', $display);

        // Nothing persisted.
        self::assertCount(0, $this->em->getRepository(TenantEntity::class)->findAll());
        self::assertCount(0, $this->em->getRepository(TenantToken::class)->findAll());
    }

    public function testApplyPersistsTenantsAndTokens(): void
    {
        $org = $this->createOrg('cedricziel', 'Cedric Ziel');

        $tester = $this->buildTester([
            'default' => ['name' => 'Default tenant', 'token_hashes' => [str_repeat('a', 64), str_repeat('b', 64)]],
            'cedric-crashler' => ['name' => 'cedric-crashler', 'token_hashes' => [str_repeat('c', 64)]],
        ]);

        $exit = $tester->execute(['--org' => 'cedricziel', '--apply' => true]);
        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('Imported 2 tenant(s) and 3 token(s)', $tester->getDisplay());

        $this->em->clear();
        /** @var TenantRepository $tenants */
        $tenants = static::getContainer()->get(TenantRepository::class);
        $default = $tenants->findOneBySlug('default');
        $cc = $tenants->findOneBySlug('cedric-crashler');
        self::assertNotNull($default);
        self::assertSame('cedricziel', $default->getOrg()?->getSlug());
        self::assertNotNull($cc);

        /** @var TenantTokenRepository $tokens */
        $tokens = static::getContainer()->get(TenantTokenRepository::class);
        self::assertCount(2, $tokens->findBy(['tenant' => $default->getId()]));
        self::assertCount(1, $tokens->findBy(['tenant' => $cc->getId()]));
    }

    public function testIdempotentSecondRunSkipsExistingTenants(): void
    {
        $org = $this->createOrg('cedricziel', 'Cedric Ziel');

        // Pre-existing DB tenant with the same slug — import should skip it.
        $existing = new TenantEntity();
        $existing->setOrg($org);
        $existing->setSlug('default');
        $existing->setName('Pre-existing default');
        $this->em->persist($existing);
        $this->em->flush();

        $tester = $this->buildTester([
            'default' => ['name' => 'YAML default', 'token_hashes' => [str_repeat('a', 64)]],
            'cedric-crashler' => ['name' => 'cedric-crashler', 'token_hashes' => [str_repeat('b', 64)]],
        ]);

        $exit = $tester->execute(['--org' => 'cedricziel', '--apply' => true]);
        self::assertSame(0, $exit);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Imported 1 tenant(s)', $display);
        self::assertStringContainsString('skipped 1 tenant(s)', $display);

        // Pre-existing tenant retained its original name; only `cedric-crashler` was imported.
        $this->em->clear();
        /** @var TenantRepository $repo */
        $repo = static::getContainer()->get(TenantRepository::class);
        self::assertSame('Pre-existing default', $repo->findOneBySlug('default')?->getName());
        self::assertNotNull($repo->findOneBySlug('cedric-crashler'));
    }

    public function testHashCollisionAcrossYamlTenantsIsSkipped(): void
    {
        $this->createOrg('cedricziel', 'Cedric Ziel');

        $sharedHash = str_repeat('e', 64);
        $tester = $this->buildTester([
            'tenant-a' => ['name' => 'A', 'token_hashes' => [$sharedHash]],
            'tenant-b' => ['name' => 'B', 'token_hashes' => [$sharedHash]],
        ]);

        $exit = $tester->execute(['--org' => 'cedricziel', '--apply' => true]);
        self::assertSame(0, $exit, $tester->getDisplay());

        // Both tenants get rows; only one of them owns the shared hash.
        $this->em->clear();
        /** @var TenantTokenRepository $tokens */
        $tokens = static::getContainer()->get(TenantTokenRepository::class);
        self::assertCount(1, $tokens->findAll(), 'shared hash imported exactly once');
    }

    public function testMissingOrgRejected(): void
    {
        $tester = $this->buildTester([]);
        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Missing --org', $tester->getDisplay());
    }

    public function testNonexistentOrgRejected(): void
    {
        $tester = $this->buildTester([
            'foo' => ['name' => 'Foo', 'token_hashes' => [str_repeat('f', 64)]],
        ]);

        $exit = $tester->execute(['--org' => 'doesnotexist']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testNoYamlTenantsIsSuccessNoop(): void
    {
        $this->createOrg('cedricziel', 'Cedric Ziel');

        $tester = $this->buildTester([]);
        $exit = $tester->execute(['--org' => 'cedricziel', '--apply' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('nothing to import', $tester->getDisplay());
    }

    /**
     * @param array<string, array{name: string, token_hashes: list<string>}> $yamlTenants
     */
    private function buildTester(array $yamlTenants): CommandTester
    {
        $command = new ImportYamlTenantsCommand(
            $yamlTenants,
            static::getContainer()->get(EntityManagerInterface::class),
            static::getContainer()->get(OrgRepository::class),
            static::getContainer()->get(TenantRepository::class),
            static::getContainer()->get(TenantTokenRepository::class),
        );

        return new CommandTester($command);
    }
}
