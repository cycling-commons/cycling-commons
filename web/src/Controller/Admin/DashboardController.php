<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\AdminDashboardStats;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * EasyAdmin dashboard for site administrators.
 *
 * Access is gated at both the security layer (access_control: ^/admin → ROLE_ADMIN
 * in security.yaml) and the controller level (#[IsGranted]).
 *
 * @api Instantiated by EasyAdmin's router; never referenced from application code.
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly AdminDashboardStats $stats)
    {
    }

    #[\Override]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'stats' => $this->stats->collect(),
        ]);
    }

    #[\Override]
    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Cycling Commons Admin');
    }

    #[\Override]
    public function configureAssets(): Assets
    {
        return Assets::new()->addAssetMapperEntry('admin_confirm');
    }

    #[\Override]
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard(new TranslatableMessage('admin.menu.dashboard'), 'fa fa-home');
        yield MenuItem::linkTo(UserCrudController::class, new TranslatableMessage('admin.menu.users'), 'fa fa-users')->setAction('index');
        yield MenuItem::linkTo(AdminActionLogCrudController::class, new TranslatableMessage('admin.menu.activity'), 'fa fa-clock-rotate-left')->setAction('index');
        yield MenuItem::linkTo(ResetPasswordRequestCrudController::class, new TranslatableMessage('admin.menu.reset_requests'), 'fa fa-key')->setAction('index');
    }
}
