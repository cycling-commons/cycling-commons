<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Catalog\Entity\Region;
use App\Community\CuratorApplicationService;
use App\Community\CuratorApplicationStatus;
use App\Community\Entity\CuratorApplication;
use App\Entity\User;
use App\Service\AdminDashboardStats;
use App\Settings\SettingsRegistry;
use App\Settings\SystemSettings;
use App\Settings\SystemSettingsWriter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

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
    /** CSRF token id for the system-config form (stateless, same-origin). */
    public const string SETTINGS_CSRF_TOKEN_ID = 'ea-system-config';

    /** CSRF token id for the curator-applications decision form (stateless, same-origin). */
    public const string CURATOR_APPS_CSRF_TOKEN_ID = 'curator-applications';

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
        // Operator playbooks: verification scripts for manual support requests.
        // A dedicated section so future playbooks slot in beside this one.
        yield MenuItem::section(new TranslatableMessage('admin.menu.playbooks'));
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.playbook_email'), 'fa fa-envelope-circle-check', 'admin_playbook_email_change');
        yield MenuItem::section(new TranslatableMessage('admin.menu.system'));
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.system_config'), 'fa fa-sliders', 'admin_system_config');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.curator_applications'), 'fa fa-user-check', 'admin_curator_applications');
    }

    /**
     * Operator playbook for manual email-change requests. Email is read-only
     * in settings (account-and-auth.md §8), so every change request lands in
     * the support mailbox; this page keeps the verification script in front
     * of the admin executing it. Canonical text: account-and-auth.md §8
     * "Support playbook" — keep the two in sync.
     */
    #[AdminRoute('/playbook/email-change', 'playbook_email_change')]
    public function emailChangePlaybook(): Response
    {
        return $this->render('admin/playbook_email_change.html.twig');
    }

    /**
     * The editorial thresholds, editable at runtime (system-configuration.md §4).
     *
     * Not an EasyAdmin CRUD over a settings entity on purpose: these are six
     * typed, grouped, range-checked fields with help text explaining what each
     * one does to the site — not rows somebody browses, sorts and deletes. The
     * page is a plain form, like the email-change playbook above.
     *
     * GET renders, POST either saves the whole form or resets one key. Both
     * branches redirect (POST/redirect/GET) so a refresh never re-submits.
     */
    #[AdminRoute('/system-config', 'system_config', options: ['methods' => ['GET', 'POST']])]
    public function systemConfig(
        Request $request,
        SettingsRegistry $registry,
        SystemSettings $settings,
        SystemSettingsWriter $writer,
        TranslatorInterface $translator,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();
        /** @var array<string, int|string> $values effective values, or the raw input when it failed validation */
        $values = $settings->all();
        /** @var array<string, string> $errors key => message, rendered under the field */
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::SETTINGS_CSRF_TOKEN_ID, (string) $request->request->get('token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token for a system-config change.');
            }

            // "Reset to default" — one key, its own submit button, so it does
            // not have to care whether the other fields are currently valid.
            $reset = (string) $request->request->get('reset', '');
            if ('' !== $reset) {
                if (!$registry->has($reset)) {
                    throw $this->createNotFoundException(sprintf('"%s" is not a configurable setting.', $reset));
                }
                $writer->reset($reset, $actor);
                $this->addFlash('success', $translator->trans('admin.flash.settings_reset'));

                return $this->redirectToRoute('admin_system_config');
            }

            /** @var array<string, mixed> $posted */
            $posted = $request->request->all('settings');
            foreach ($registry->all() as $key => $def) {
                $raw = trim((string) ($posted[$key] ?? ''));
                // Reject the string before casting: (int) '' is 0 and (int) 'abc'
                // is 0, and 0 is a legal-looking number for none of these keys.
                if (1 !== preg_match('/^-?\d+$/', $raw) || !$def->accepts((int) $raw)) {
                    $errors[$key] = $translator->trans('admin.settings.error_range', [
                        '%min%' => $def->min,
                        '%max%' => $def->max,
                    ]);
                    $values[$key] = $raw; // keep what they typed rather than silently reverting it
                    continue;
                }
                $values[$key] = (int) $raw;
            }

            if ([] === $errors) {
                $changed = 0;
                foreach ($values as $key => $value) {
                    // Only genuine changes are written, so re-saving an
                    // untouched form neither pins defaults into the table nor
                    // fills the audit log with "25 -> 25".
                    if ((int) $value === $settings->get($key)) {
                        continue;
                    }
                    $writer->set($key, (int) $value, $actor);
                    ++$changed;
                }
                $this->addFlash(
                    'success',
                    $translator->trans($changed > 0 ? 'admin.flash.settings_saved' : 'admin.flash.settings_unchanged')
                );

                return $this->redirectToRoute('admin_system_config');
            }

            $this->addFlash('danger', $translator->trans('admin.flash.settings_invalid'));
        }

        $overridden = [];
        foreach ($registry->all() as $key => $_def) {
            $overridden[$key] = $settings->isOverridden($key);
        }

        return $this->render('admin/system_config.html.twig', [
            'grouped' => $registry->grouped(),
            'values' => $values,
            'errors' => $errors,
            'overridden' => $overridden,
        ]);
    }

    /**
     * Curator applications (2026-07-29-country-requests-and-curator-signup-design.md §9).
     *
     * A purpose-built page rather than an EasyAdmin CRUD: the reviewer needs a
     * person, their track record, their OSM standing and their words side by
     * side to make one judgement — not rows to sort and delete.
     */
    #[AdminRoute('/curator-applications', 'curator_applications', options: ['methods' => ['GET', 'POST']])]
    public function curatorApplications(
        Request $request,
        CuratorApplicationService $applications,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CURATOR_APPS_CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token for a curator decision.');
            }
            $app = $em->find(CuratorApplication::class, (int) $request->request->get('application'));
            if (null === $app) {
                throw $this->createNotFoundException('No such application.');
            }
            $decision = (string) $request->request->get('decision');
            if (!\in_array($decision, ['approve', 'decline'], true)) {
                throw new BadRequestHttpException('Decision must be "approve" or "decline".');
            }
            $note = trim((string) $request->request->get('note', '')) ?: null;

            // Guard against re-deciding here too, not just inside the
            // service: a stale page (two admin tabs, a double-submit) must
            // flash and no-op rather than reprocess an already-Approved or
            // -Declined application. The service throws the same guard
            // regardless of caller, so this is a friendlier front door on it,
            // not the only line of defence.
            if (CuratorApplicationStatus::Pending !== $app->getStatus()) {
                $this->addFlash('danger', $translator->trans('admin.curator.already_decided'));

                return $this->redirectToRoute('admin_curator_applications');
            }

            if ('approve' === $decision) {
                $applications->approve($app, $actor, $note);
            } else {
                $applications->decline($app, $actor, $note);
            }

            return $this->redirectToRoute('admin_curator_applications');
        }

        $rows = [];
        foreach ($applications->pending() as $app) {
            $regionName = null;
            if (null !== $app->getRequestedRegionId()) {
                $regionName = $em->getRepository(Region::class)->find($app->getRequestedRegionId())?->getName();
            }
            $rows[] = [
                'app' => $app,
                'user' => $em->getRepository(User::class)->find($app->getUserId()),
                'evidence' => $applications->evidenceFor($app),
                'regionName' => $regionName,
            ];
        }

        return $this->render('admin/curator_applications.html.twig', [
            'rows' => $rows,
            'csrf_token_id' => self::CURATOR_APPS_CSRF_TOKEN_ID,
        ]);
    }
}
