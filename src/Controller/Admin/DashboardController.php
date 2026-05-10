<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Crashler')
            ->setFaviconPath('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 128 128%22><text y=%221.2em%22 font-size=%2296%22>⚫️</text></svg>')
        ;
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Identity');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-user');
        yield MenuItem::section('Tenancy');
        yield MenuItem::linkTo(OrgCrudController::class, 'Orgs', 'fa fa-building');
        yield MenuItem::linkTo(TenantCrudController::class, 'Tenants', 'fa fa-database');
        yield MenuItem::linkTo(TenantTokenCrudController::class, 'Tokens', 'fa fa-key');
        yield MenuItem::section('Memberships');
        yield MenuItem::linkTo(OrgMembershipCrudController::class, 'Org members', 'fa fa-users');
        yield MenuItem::linkTo(TenantMembershipCrudController::class, 'Tenant members', 'fa fa-users-cog');
        yield MenuItem::section();
        yield MenuItem::linkToLogout('Sign out', 'fa fa-sign-out-alt');
    }
}
