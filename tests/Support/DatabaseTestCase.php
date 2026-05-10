<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Org;
use App\Entity\OrgMembership;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\TenantToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base class for functional tests that need a real database. Drops and
 * recreates the schema once per test method so each test starts with a
 * clean slate.
 */
abstract class DatabaseTestCase extends WebTestCase
{
    protected ?KernelBrowser $client = null;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->client = null;
        parent::tearDown();
    }

    protected function createUser(string $email, string $password, bool $admin = false): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($admin ? ['ROLE_ADMIN'] : []);
        $user->setPassword($hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createOrg(string $slug, string $name): Org
    {
        $org = new Org();
        $org->setSlug($slug);
        $org->setName($name);
        $this->em->persist($org);
        $this->em->flush();

        return $org;
    }

    protected function createTenant(Org $org, string $slug, string $name): Tenant
    {
        $tenant = new Tenant();
        $tenant->setOrg($org);
        $tenant->setSlug($slug);
        $tenant->setName($name);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    protected function createTenantToken(Tenant $tenant, string $name, string $hash, ?\DateTimeImmutable $expiresAt = null): TenantToken
    {
        $token = new TenantToken();
        $token->setTenant($tenant);
        $token->setName($name);
        $token->setHash($hash);
        $token->setExpiresAt($expiresAt);
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }
}
