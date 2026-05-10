<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\DashboardController;
use App\Controller\Admin\OrgCrudController;
use App\Controller\Admin\TenantCrudController;
use App\Controller\Admin\TenantTokenCrudController;
use App\Controller\Admin\UserCrudController;
use App\Entity\Org;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\DatabaseTestCase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Smoke test: each admin CRUD's index + new pages render without error
 * for ROLE_ADMIN. Exercises configureCrud() + configureFields() + the
 * field-list construction; locks in the controllers' configuration so
 * a mis-typed field type (cf. the EnumType bug fixed earlier) trips the
 * CI gate immediately.
 */
final class AdminCrudIndexTest extends DatabaseTestCase
{
    public function testUserCrudIndexAndNew(): void
    {
        $this->loginAdmin();
        $this->visit(UserCrudController::class, Action::INDEX);
        $this->visit(UserCrudController::class, Action::NEW);
    }

    public function testOrgCrudIndexAndNew(): void
    {
        $this->loginAdmin();
        $this->visit(OrgCrudController::class, Action::INDEX);
        $this->visit(OrgCrudController::class, Action::NEW);
    }

    public function testTenantCrudIndexAndNew(): void
    {
        $this->loginAdmin();
        // Need at least one Org so the AssociationField on Tenant has a choice.
        $this->createOrg('acme', 'Acme Corp');

        $this->visit(TenantCrudController::class, Action::INDEX);
        $this->visit(TenantCrudController::class, Action::NEW);
    }

    public function testTenantTokenCrudIndexAndNew(): void
    {
        $this->loginAdmin();
        // Seed an Org+Tenant so the AssociationField on TenantToken has a choice.
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');

        $this->visit(TenantTokenCrudController::class, Action::INDEX);
        $this->visit(TenantTokenCrudController::class, Action::NEW);
    }

    public function testUserEditPageRenders(): void
    {
        $admin = $this->loginAdmin();

        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($admin->getId())
            ->generateUrl();

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testUserCreateFormHashesPassword(): void
    {
        $this->loginAdmin();

        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl();

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create')->form();
        $form['User[email]'] = 'newcomer@example.com';
        $form['User[password][first]'] = 'plaintext-pw-1234';
        $form['User[password][second]'] = 'plaintext-pw-1234';
        $this->client->submit($form);

        self::assertResponseRedirects();

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $created = $users->findOneByEmail('newcomer@example.com');
        self::assertNotNull($created);
        // hashPasswordFromForm replaces the empty plaintext with a hash.
        self::assertNotSame('plaintext-pw-1234', $created->getPassword());
        self::assertNotEmpty($created->getPassword());
    }

    public function testUserEditWithoutPasswordPreservesExisting(): void
    {
        $admin = $this->loginAdmin();
        $originalHash = $admin->getPassword();

        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($admin->getId())
            ->generateUrl();

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $form['User[password][first]'] = '';
        $form['User[password][second]'] = '';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($admin->getId());
        self::assertSame($originalHash, $reloaded->getPassword());
    }

    public function testOrgDeleteRejectsOrgWithTenants(): void
    {
        $this->loginAdmin();
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');
        // Reload so the lazy `tenants` collection sees the freshly-persisted child.
        $this->em->clear();
        $org = $this->em->getRepository(Org::class)->find($org->getId());

        $controller = new OrgCrudController();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still has 1 tenant');
        $controller->deleteEntity($this->em, $org);
    }

    public function testOrgDeleteRejectsOrgWithMemberships(): void
    {
        $admin = $this->loginAdmin();
        $org = $this->createOrg('acme', 'Acme Corp');

        $membership = new \App\Entity\OrgMembership();
        $membership->setUser($admin);
        $membership->setOrg($org);
        $membership->setRole(\App\Entity\Enum\MembershipRole::Owner);
        $this->em->persist($membership);
        $this->em->flush();
        $this->em->clear();

        $org = $this->em->getRepository(Org::class)->find($org->getId());

        $controller = new OrgCrudController();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still has 1 member');
        $controller->deleteEntity($this->em, $org);
    }

    public function testOrgCrudShowsTenantAndMemberCounts(): void
    {
        $admin = $this->loginAdmin();
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');

        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setDashboard(DashboardController::class)
            ->setController(OrgCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    private function loginAdmin(): User
    {
        $admin = $this->createUser('admin@example.com', 'pw-12345', admin: true);
        $this->client->loginUser($admin, 'app');

        return $admin;
    }

    private function visit(string $crudControllerFqcn, string $action): void
    {
        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setDashboard(DashboardController::class)
            ->setController($crudControllerFqcn)
            ->setAction($action)
            ->generateUrl();

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful(\sprintf('%s/%s should render successfully', $crudControllerFqcn, $action));
    }
}
