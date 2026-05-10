<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\DashboardController;
use App\Controller\Admin\TenantTokenCrudController;
use App\Tests\Support\DatabaseTestCase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Locks in the EasyAdmin TenantToken EDIT page: the plaintext is never
 * editable, only a redacted `hashPrefix` is shown. The full hash must
 * not appear anywhere in the rendered form.
 */
final class TenantTokenIssuanceTest extends DatabaseTestCase
{
    public function testEditPageRendersWithRedactedHashField(): void
    {
        $this->loginAdmin();
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');

        $token = $this->createTenantToken($tenant, 'manual', str_repeat('a', 64));

        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setDashboard(DashboardController::class)
            ->setController(TenantTokenCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($token->getId())
            ->generateUrl();

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        // EDIT page shows redacted hash (prefix + ellipsis), never the full hash.
        self::assertStringContainsString('aaaaaaaa', $body);
        self::assertStringNotContainsString(str_repeat('a', 64), $body);
    }

    private function loginAdmin(): \App\Entity\User
    {
        $admin = $this->createUser('admin@example.com', 'pw-12345', admin: true);
        $this->client->loginUser($admin, 'app');

        return $admin;
    }
}
