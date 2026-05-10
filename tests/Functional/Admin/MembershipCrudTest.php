<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\DashboardController;
use App\Controller\Admin\OrgMembershipCrudController;
use App\Controller\Admin\TenantMembershipCrudController;
use App\Entity\Enum\MembershipRole;
use App\Entity\OrgMembership;
use App\Entity\TenantMembership;
use App\Tests\Support\DatabaseTestCase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Locks in the fix for the bug where the membership "new" form 500ed because
 * ChoiceField with both setChoices() and a 'class' form-option targeted
 * ChoiceType (which doesn't accept 'class'). The fix switches to Symfony's
 * EnumType so the backed-enum ↔ string conversion is handled natively.
 */
#[CoversNothing]
final class MembershipCrudTest extends DatabaseTestCase
{
    public function testOrgMembershipNewFormRenders(): void
    {
        $this->loginAdmin();

        $url = $this->adminUrl(OrgMembershipCrudController::class, Action::NEW);
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        // The role <select> must exist and offer the three enum cases.
        $options = $crawler->filter('select#OrgMembership_role option')->each(
            static fn ($node) => $node->attr('value'),
        );
        // The first <option> may be an empty placeholder; assert the
        // three enum values are present.
        self::assertContains('owner', $options);
        self::assertContains('admin', $options);
        self::assertContains('member', $options);
    }

    public function testOrgMembershipPersistsViaForm(): void
    {
        $admin = $this->loginAdmin();
        $org = $this->createOrg('acme', 'Acme Corp');

        $url = $this->adminUrl(OrgMembershipCrudController::class, Action::NEW);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create')->form();
        $form['OrgMembership[user]'] = (string) $admin->getId();
        $form['OrgMembership[org]'] = (string) $org->getId();
        $form['OrgMembership[role]'] = 'admin';
        $this->client->submit($form);

        // Either redirects to the index or back to detail; both are 30x.
        self::assertResponseRedirects();

        $this->em->clear();
        $persisted = $this->em->getRepository(OrgMembership::class)->findOneBy([
            'user' => $admin->getId(),
            'org' => $org->getId(),
        ]);
        self::assertNotNull($persisted);
        self::assertSame(MembershipRole::Admin, $persisted->getRole());
    }

    public function testTenantMembershipNewFormRenders(): void
    {
        $this->loginAdmin();

        $url = $this->adminUrl(TenantMembershipCrudController::class, Action::NEW);
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select#TenantMembership_role option')->each(
            static fn ($node) => $node->attr('value'),
        );
        self::assertContains('owner', $options);
        self::assertContains('admin', $options);
        self::assertContains('member', $options);
    }

    public function testTenantMembershipPersistsViaForm(): void
    {
        $admin = $this->loginAdmin();
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');

        $url = $this->adminUrl(TenantMembershipCrudController::class, Action::NEW);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create')->form();
        $form['TenantMembership[user]'] = (string) $admin->getId();
        $form['TenantMembership[tenant]'] = (string) $tenant->getId();
        $form['TenantMembership[role]'] = 'owner';
        $this->client->submit($form);

        self::assertResponseRedirects();

        $this->em->clear();
        $persisted = $this->em->getRepository(TenantMembership::class)->findOneBy([
            'user' => $admin->getId(),
            'tenant' => $tenant->getId(),
        ]);
        self::assertNotNull($persisted);
        self::assertSame(MembershipRole::Owner, $persisted->getRole());
    }

    private function loginAdmin(): \App\Entity\User
    {
        $admin = $this->createUser('admin@example.com', 'pw-12345', admin: true);
        $this->client->loginUser($admin, 'app');

        return $admin;
    }

    private function adminUrl(string $crudControllerFqcn, string $action): string
    {
        /** @var AdminUrlGenerator $gen */
        $gen = static::getContainer()->get(AdminUrlGenerator::class);

        return $gen
            ->setDashboard(DashboardController::class)
            ->setController($crudControllerFqcn)
            ->setAction($action)
            ->generateUrl();
    }
}
