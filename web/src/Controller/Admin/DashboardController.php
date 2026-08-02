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
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.playbook_photo_flood'), 'fa fa-triangle-exclamation', 'admin_playbook_photo_flood');
        yield MenuItem::section(new TranslatableMessage('admin.menu.system'));
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.system_config'), 'fa fa-sliders', 'admin_system_config');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.curator_applications'), 'fa fa-user-check', 'admin_curator_applications');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.withheld_photos'), 'fa fa-image-slash', 'admin_withheld_photos');
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.escalated'), 'fa fa-shield-halved', 'admin_escalated');
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
     * Incident response for a flood of anonymous photo reports
     * (docs/specs/photo-uploads.md §6c).
     *
     * Admin-only on purpose, and NOT in the public wiki: the moderator
     * rulebook is world-readable, so anything it says about how removal
     * decisions are actually made is also a script for talking a curator into
     * removing something. What belongs in public is the promise we make to
     * somebody exercising a right; what belongs here is how we respond when
     * that route is used as a weapon.
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
                // No setting may be saved empty, whatever its type. Said in its
                // own message rather than folded into the range/format one: an
                // empty box answered with "enter a number between 1 and 1000"
                // reads as a complaint about a number nobody typed.
                if ('' === $raw) {
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
                    // fills the audit log with "25 -> 25". Compared in the
                    // key's own type: casting a text setting to int would make
                    // every address list look like 0 and "unchanged".
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

            try {
                if ('approve' === $decision) {
                    $applications->approve($app, $actor, $note);
                } else {
                    $applications->decline($app, $actor, $note);
                }
            } catch (CuratorApplicationException $e) {
                // Known reasons get a translated, four-locale flash; anything
                // else falls back to the exception's own English text rather
                // than a 500 — a stale-page re-decide is already handled by
                // the Pending check above, so in practice this is the region-
                // gone and already-global-curator guards from approve().
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

        $rows = [];
        foreach ($applications->pending() as $app) {
            // requested_region_id carries no FK, so a region deleted after
            // submission does not null the column out — it dangles. That
            // must render as its own state, not fall through to "whole
            // country" (which approve() would then also have to guard, see
            // CuratorApplicationService::approve()).
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

            // Existing standing (§9 follow-up): approving someone who already
            // holds ROLE_CURATOR/ROLE_MODERATOR, or is already scoped
            // somewhere, is worth a glance before deciding — approve() itself
            // only hard-blocks the narrowing case (global curator, zero
            // moderator_area rows), everything else is just shown.
            // Slugs, not raw ROLE_* constants, so the template can trans()
            // each one directly (admin.curator.role_curator / _moderator)
            // instead of string-surgering a role constant at render time.
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
        ]);
    }

    /**
     * Put back photos that abusive reports took down
     * (docs/specs/photo-uploads.md §6c).
     *
     * The recovery half of the circuit breaker. The breaker bounds how many
     * photos a flood can withhold; it cannot un-withhold them, and clearing an
     * incident one card at a time on the moderation desk is exactly the cost
     * an attacker is buying — while the noise buries the genuine report the
     * whole route exists for.
     *
     * Admin rather than the curator desk on purpose: this is an operational
     * response to an attack on the site, not a judgement on any one claim.
     * That is also why it dismisses rather than declines — a decline closes
     * that category for that photo forever, so mass-declining a flood would
     * quietly immunise every attacked photo against the next genuine report.
     */
    #[AdminRoute('/withheld-photos', 'withheld_photos', options: ['methods' => ['GET', 'POST']])]
    public function withheldPhotos(
        Request $request,
        MediaTakedownService $takedowns,
        UrgentWithholdBreaker $breaker,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
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
                // Anything already decided by a curator in the meantime is
                // skipped, not overridden: the desk and this page can be open
                // at once, and a real decision outranks a bulk sweep.
                if (null !== $upload && $takedowns->dismissAsAbuse($upload, $actor, $note)) {
                    ++$restored;
                }
            }

            $this->addFlash('success', $translator->trans('admin.withheld.restored', ['%count%' => $restored]));

            return $this->redirectToRoute('admin_withheld_photos');
        }

        return $this->render('admin/withheld_photos.html.twig', [
            'cards' => $takedowns->withheldThirdPartyCards(),
            'breaker_open' => $breaker->isOpen(),
            'csrf_token_id' => self::WITHHELD_PHOTOS_CSRF_TOKEN_ID,
        ]);
    }

    /**
     * Photos under legal hold (docs/specs/photo-uploads.md §6d).
     *
     * The only surface in the application where escalated material can be
     * reached: it is gone from the map, the photo page and the moderation desk
     * by the time it appears here. Releasing lifts the hold and hands the row
     * back to normal moderation — it deletes nothing, because when the
     * material is the kind that had to be reported, the authority it was
     * reported to decides when it may go.
     */
    #[AdminRoute('/escalated', 'escalated', options: ['methods' => ['GET', 'POST']])]
    public function escalated(Request $request, MediaEscalationService $escalations, ModerationService $moderation, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::ESCALATED_CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token for an escalation release.');
            }
            $note = trim((string) $request->request->get('note', '')) ?: null;
            // One form, two kinds of held thing: a photo carries a uuid, a
            // submission its id. Both release the same way.
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

        return $this->render('admin/escalated.html.twig', [
            'cards' => $escalations->held(),
            'submissions' => $moderation->heldSubmissions(),
            'csrf_token_id' => self::ESCALATED_CSRF_TOKEN_ID,
        ]);
    }
}
