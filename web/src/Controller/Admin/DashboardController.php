<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Catalog\Entity\Region;
use App\Community\CuratorApplicationException;
use App\Community\CuratorApplicationService;
use App\Community\CuratorApplicationStatus;
use App\Community\Entity\CuratorApplication;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaEscalationService;
use App\Media\MediaTakedownService;
use App\Media\UrgentWithholdBreaker;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationService;
use App\Pagination\Pager;
use App\Pagination\PageSize;
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
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * EasyAdmin dashboard.
 *
 * @see docs/specs/account-and-auth.md §6
 *
 * @api
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    /** CSRF token id for the system-config form (stateless, same-origin). */
    public const string SETTINGS_CSRF_TOKEN_ID = 'ea-system-config';

    /** CSRF token id for the curator-applications decision form (session-backed, same-origin). */
    public const string CURATOR_APPS_CSRF_TOKEN_ID = 'curator-applications';
    public const string WITHHELD_PHOTOS_CSRF_TOKEN_ID = 'withheld-photos';
    public const string ESCALATED_CSRF_TOKEN_ID = 'escalated-photos';

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
        return Assets::new()
            ->addAssetMapperEntry('admin_confirm')
            ->addAssetMapperEntry('form_autosubmit');
    }

    #[\Override]
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard(new TranslatableMessage('admin.menu.dashboard'), 'fa fa-home');
        yield MenuItem::linkTo(UserCrudController::class, new TranslatableMessage('admin.menu.users'), 'fa fa-users')->setAction('index');
        yield MenuItem::linkTo(AdminActionLogCrudController::class, new TranslatableMessage('admin.menu.activity'), 'fa fa-clock-rotate-left')->setAction('index');
        yield MenuItem::linkTo(ResetPasswordRequestCrudController::class, new TranslatableMessage('admin.menu.reset_requests'), 'fa fa-key')->setAction('index');
        yield MenuItem::linkTo(BlogPostCrudController::class, new TranslatableMessage('admin.menu.blog'), 'fa fa-pen-nib')->setAction('index');
        yield MenuItem::section(new TranslatableMessage('admin.menu.playbooks'));
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.playbook_email'), 'fa fa-envelope-circle-check', 'admin_playbook_email_change');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.playbook_photo_flood'), 'fa fa-triangle-exclamation', 'admin_playbook_photo_flood');
        yield MenuItem::section(new TranslatableMessage('admin.menu.system'));
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.system_config'), 'fa fa-sliders', 'admin_system_config');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.curator_applications'), 'fa fa-user-check', 'admin_curator_applications');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.withheld_photos'), 'fa fa-image-slash', 'admin_withheld_photos');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.moderator_areas'), 'fa fa-map-location-dot', 'admin_moderator_areas_overview');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.moderation_activity'), 'fa fa-chart-column', 'admin_moderation_activity');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.escalated'), 'fa fa-shield-halved', 'admin_escalated');
    }

    /**
     * Email-change playbook. Email is read-only in settings.
     *
     * @see docs/specs/account-and-auth.md §8
     */
    #[AdminRoute('/playbook/email-change', 'playbook_email_change')]
    public function emailChangePlaybook(): Response
    {
        return $this->render('admin/playbook_email_change.html.twig');
    }

    /**
     * Incident response for a flood of anonymous photo reports.
     *
     * @see docs/specs/photo-uploads.md §6c
     */
    #[AdminRoute('/playbook/photo-flood', 'playbook_photo_flood')]
    public function photoFloodPlaybook(UrgentWithholdBreaker $breaker, SystemSettings $settings): Response
    {
        return $this->render('admin/playbook_photo_flood.html.twig', [
            'breaker_open' => $breaker->isOpen(),
            'hourly' => $settings->get(SettingsRegistry::MEDIA_URGENT_BREAKER_HOURLY),
            'daily' => $settings->get(SettingsRegistry::MEDIA_URGENT_BREAKER_DAILY),
        ]);
    }

    /**
     * Runtime editorial thresholds.
     *
     * @see docs/specs/system-configuration.md §4
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
        $values = $settings->all();
        /** @var array<string, string> $errors key => message, rendered under the field */
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::SETTINGS_CSRF_TOKEN_ID, (string) $request->request->get('token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token for a system-config change.');
            }

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
                // Empty is an error for everything EXCEPT a setting that
                // declares emptiness a meaning of its own: the support list,
                // where blank means "fall back to the alert list". Hard-coding
                // "required" here made such a setting impossible to save.
                if ('' === $raw && !$def->allowsEmpty) {
                    $errors[$key] = $translator->trans('admin.settings.error_required');
                    $values[$key] = $raw;
                    continue;
                }
                if ($def->isString()) {
                    if (!$def->accepts($raw)) {
                        $errors[$key] = $translator->trans('admin.settings.error_text');
                        $values[$key] = $raw;
                        continue;
                    }
                    $values[$key] = $raw;
                    continue;
                }
                // Reject before cast: (int) '' and (int) 'abc' are 0.
                if (1 !== preg_match('/^-?\d+$/', $raw) || !$def->accepts((int) $raw)) {
                    $errors[$key] = $translator->trans('admin.settings.error_range', [
                        '%min%' => $def->min,
                        '%max%' => $def->max,
                    ]);
                    $values[$key] = $raw;
                    continue;
                }
                $values[$key] = (int) $raw;
            }

            if ([] === $errors) {
                $changed = 0;
                foreach ($values as $key => $value) {
                    $current = $registry->get($key)->isString() ? $settings->getString($key) : $settings->get($key);
                    if ($value === $current) {
                        continue;
                    }
                    $writer->set($key, $value, $actor);
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
     * Curator applications.
     *
     * @see docs/specs/moderation-and-contribution.md §11.4
     */
    #[AdminRoute('/curator-applications', 'curator_applications', options: ['methods' => ['GET', 'POST']])]
    public function curatorApplications(
        Request $request,
        CuratorApplicationService $applications,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        PageSize $pageSize,
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

            if (CuratorApplicationStatus::Pending !== $app->getStatus()) {
                $this->addFlash('danger', $translator->trans('admin.curator.already_decided'));

                return $this->redirectToRoute('admin_curator_applications');
            }

            try {
                if ('approve' === $decision) {
                    $applications->approve($app, $actor, $note);
                } else {
                    $applications->decline($app, $actor, $note);
                }
            } catch (CuratorApplicationException $e) {
                $key = match ($e->reason) {
                    'region_gone' => 'admin.curator.approve_blocked_region_gone',
                    'already_global_curator' => 'admin.curator.approve_blocked_already_global',
                    'applicant_gone' => 'admin.curator.applicant_gone',
                    default => null,
                };
                $this->addFlash('danger', null !== $key ? $translator->trans($key) : $e->getMessage());

                return $this->redirectToRoute('admin_curator_applications');
            }

            return $this->redirectToRoute('admin_curator_applications');
        }

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $applications->totalCount(),
            $pageSize->resolve(CuratorApplicationService::PER_PAGE),
        );

        $rows = [];
        foreach ($applications->recent($pager['page'], $pager['perPage']) as $app) {
            // requested_region_id is no-FK; a deleted region must not look like "whole country".
            $regionName = null;
            $regionGone = false;
            if (null !== $app->getRequestedRegionId()) {
                $region = $em->getRepository(Region::class)->find($app->getRequestedRegionId());
                if (null === $region) {
                    $regionGone = true;
                } else {
                    $regionName = $region->getName();
                }
            }

            $user = $em->getRepository(User::class)->find($app->getUserId());

            $standingRoles = [];
            $standingAreas = [];
            if (null !== $user) {
                if (\in_array('ROLE_CURATOR', $user->getRoles(), true)) {
                    $standingRoles[] = 'curator';
                }
                if (\in_array('ROLE_MODERATOR', $user->getRoles(), true)) {
                    $standingRoles[] = 'moderator';
                }
                foreach ($em->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $user->getId()]) as $area) {
                    if (null !== $area->getRegionId()) {
                        $standingAreas[] = $em->getRepository(Region::class)->find($area->getRegionId())?->getName() ?? sprintf('#%d', $area->getRegionId());
                    } else {
                        $standingAreas[] = (string) $area->getCountryCode();
                    }
                }
            }

            $rows[] = [
                'app' => $app,
                'user' => $user,
                'evidence' => $applications->evidenceFor($app),
                'regionName' => $regionName,
                'regionGone' => $regionGone,
                'standingRoles' => $standingRoles,
                'standingAreas' => $standingAreas,
            ];
        }

        return $this->render('admin/curator_applications.html.twig', [
            'rows' => $rows,
            'csrf_token_id' => self::CURATOR_APPS_CSRF_TOKEN_ID,
            'pager' => $pager,
        ]);
    }

    /**
     * Restore photos withheld by abusive reports. Dismiss, do not decline — decline immunises the photo.
     *
     * @see docs/specs/photo-uploads.md §6c
     */
    #[AdminRoute('/withheld-photos', 'withheld_photos', options: ['methods' => ['GET', 'POST']])]
    public function withheldPhotos(
        Request $request,
        MediaTakedownService $takedowns,
        UrgentWithholdBreaker $breaker,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        PageSize $pageSize,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::WITHHELD_PHOTOS_CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token for a photo restore.');
            }

            /** @var list<string> $selected */
            $selected = $request->request->all('media');
            $note = trim((string) $request->request->get('note', '')) ?: null;
            $restored = 0;
            foreach ($selected as $uuid) {
                if (!Uuid::isValid($uuid)) {
                    continue;
                }
                $upload = $em->find(MediaUpload::class, Uuid::fromString($uuid));
                if (null !== $upload && $takedowns->dismissAsAbuse($upload, $actor, $note)) {
                    ++$restored;
                }
            }

            $this->addFlash('success', $translator->trans('admin.withheld.restored', ['%count%' => $restored]));

            return $this->redirectToRoute('admin_withheld_photos');
        }

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $takedowns->withheldThirdPartyCount(),
            $pageSize->resolve(MediaTakedownService::PER_PAGE),
        );

        return $this->render('admin/withheld_photos.html.twig', [
            'cards' => $takedowns->withheldThirdPartyCards($pager['page'], $pager['perPage']),
            'breaker_open' => $breaker->isOpen(),
            'csrf_token_id' => self::WITHHELD_PHOTOS_CSRF_TOKEN_ID,
            'pager' => $pager,
        ]);
    }

    /**
     * Who covers what. Read-only names — never hydrate Region.geom.
     *
     * @see docs/specs/moderation-and-contribution.md §9
     */
    #[AdminRoute('/moderator-areas', 'moderator_areas_overview', options: ['methods' => ['GET']])]
    public function moderatorAreasOverview(EntityManagerInterface $em): Response
    {
        $regionNames = $em->getConnection()->fetchAllKeyValue('SELECT id, name FROM region');

        $rows = [];
        foreach ($em->getRepository(User::class)->findAll() as $user) {
            $roles = $user->getRoles();
            if (!\in_array('ROLE_CURATOR', $roles, true) && !\in_array('ROLE_ADMIN', $roles, true)) {
                continue;
            }
            $areas = [];
            foreach ($em->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $user->getId()]) as $area) {
                $areas[] = null !== $area->getRegionId()
                    ? ($regionNames[$area->getRegionId()] ?? sprintf('#%d', $area->getRegionId()))
                    : (string) $area->getCountryCode();
            }
            sort($areas);
            $rows[] = ['user' => $user, 'areas' => $areas, 'roles' => $roles];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['user']->getDisplayName(), $b['user']->getDisplayName()));

        return $this->render('admin/moderator_areas_overview.html.twig', ['rows' => $rows]);
    }

    /**
     * Workload per month × region — never per moderator.
     *
     * @see docs/specs/account-and-auth.md §7
     */
    #[AdminRoute('/moderation-activity', 'moderation_activity', options: ['methods' => ['GET']])]
    public function moderationActivity(EntityManagerInterface $em): Response
    {
        $rows = $em->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT to_char(date_trunc('month', s.decided_at), 'YYYY-MM') AS month,
                   COALESCE(r.name, s.country_code, '?') AS region,
                   COUNT(*) FILTER (WHERE s.status = 'approved') AS approved,
                   COUNT(*) FILTER (WHERE s.status = 'rejected') AS rejected
            FROM submission s
            LEFT JOIN region r ON r.id = s.region_id
            WHERE s.decided_at IS NOT NULL
              AND s.decided_at >= date_trunc('month', NOW()) - INTERVAL '11 months'
            GROUP BY 1, 2
            ORDER BY 1 DESC, 2
            SQL);

        return $this->render('admin/moderation_activity.html.twig', ['rows' => $rows]);
    }

    /**
     * Photos and submissions under legal hold. Release lifts the hold; it deletes nothing.
     *
     * @see docs/specs/photo-uploads.md §6d
     */
    #[AdminRoute('/escalated', 'escalated', options: ['methods' => ['GET', 'POST']])]
    public function escalated(Request $request, MediaEscalationService $escalations, ModerationService $moderation, EntityManagerInterface $em, TranslatorInterface $translator, PageSize $pageSize): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::ESCALATED_CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token for an escalation release.');
            }
            $note = trim((string) $request->request->get('note', '')) ?: null;
            $submissionId = (int) $request->request->get('submission', 0);
            if ($submissionId > 0) {
                $moderation->releaseSubmission($submissionId, $actor, $note);
            } else {
                $uuid = (string) $request->request->get('media');
                $upload = Uuid::isValid($uuid) ? $em->find(MediaUpload::class, Uuid::fromString($uuid)) : null;
                if (null === $upload) {
                    throw $this->createNotFoundException('No such photo.');
                }
                $escalations->release($upload, $actor, $note);
            }
            $this->addFlash('success', $translator->trans('admin.escalated.released'));

            return $this->redirectToRoute('admin_escalated');
        }

        $pager = Pager::of($request->query->getInt('page', 1), $escalations->heldCount(), $pageSize->resolve(MediaEscalationService::PER_PAGE));
        $submissionPager = Pager::of($request->query->getInt('spage', 1), $moderation->heldSubmissionCount(), $pageSize->resolve(ModerationService::HELD_PER_PAGE));

        return $this->render('admin/escalated.html.twig', [
            'cards' => $escalations->held($pager['page'], $pager['perPage']),
            'submissions' => $moderation->heldSubmissions($submissionPager['page'], $submissionPager['perPage']),
            'csrf_token_id' => self::ESCALATED_CSRF_TOKEN_ID,
            'pager' => $pager,
            'submission_pager' => $submissionPager,
        ]);
    }
}
