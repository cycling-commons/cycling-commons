<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Account\RowsPerPage;
use App\Entity\User;
use App\Form\SettingsPasswordType;
use App\Form\SettingsType;
use App\Routing\LocalePrefix;
use App\Service\BaseLocationService;
use App\Service\UserDeletionService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Account settings. Email is read-only here.
 *
 * @see docs/specs/account-and-auth.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserDeletionService $deletionService,
        private readonly BaseLocationService $baseLocations,
        private readonly Connection $db,
    ) {
    }

    #[Route('/settings', name: 'settings')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $profileForm = $this->createForm(SettingsType::class, $user);
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $lat = $profileForm->get('baseLat')->getData();
            $lng = $profileForm->get('baseLng')->getData();
            $radius = $profileForm->get('baseRadiusKm')->getData();
            if ($profileForm->get('baseClear')->getData()) {
                $this->baseLocations->clear($user);
            } elseif (is_numeric($lat) && is_numeric($lng)) {
                $this->baseLocations->apply(
                    $user,
                    (float) $lat,
                    (float) $lng,
                    $profileForm->get('basePlace')->getData() ?: null,
                    is_numeric($radius) ? (int) $radius : $user->getBaseRadiusKm(),
                );
            } elseif ($user->hasBaseLocation() && is_numeric($radius) && (int) $radius !== $user->getBaseRadiusKm()) {
                $this->baseLocations->apply(
                    $user,
                    (float) $user->getBaseLat(),
                    (float) $user->getBaseLng(),
                    $user->getBasePlace(),
                    (int) $radius,
                );
            }
            $this->em->flush();

            if (null !== $user->getLocale()) {
                $request->getSession()->set('_locale', $user->getLocale());
            } else {
                $request->getSession()->remove('_locale');
            }

            $this->addFlash('success', 'flash.profile_saved');

            return $this->redirectToRoute('settings');
        }

        $passwordForm = $this->createForm(SettingsPasswordType::class);
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            /** @var string $currentPassword */
            $currentPassword = $passwordForm->get('currentPassword')->getData();

            if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('password_error', 'flash.current_password_incorrect');

                return $this->redirectToRoute('settings', ['tab' => 'security']);
            }

            /** @var string $newPassword */
            $newPassword = $passwordForm->get('newPassword')->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->em->flush();

            $this->addFlash('success', 'flash.password_changed');

            return $this->redirectToRoute('settings', ['tab' => 'security']);
        }

        $activeTab = 'security' === $request->query->get('tab')
            || ($passwordForm->isSubmitted() && !$passwordForm->isValid())
            ? 'security' : 'profile';

        $baseRegionSlugs = [];
        if ([] !== $user->getBaseRegionIds()) {
            $rows = $this->db->fetchAllKeyValue(
                'SELECT id, slug FROM region WHERE id IN (:ids)',
                ['ids' => $user->getBaseRegionIds()],
                ['ids' => ArrayParameterType::INTEGER],
            );
            foreach ($user->getBaseRegionIds() as $rid) {
                if (isset($rows[$rid])) {
                    $baseRegionSlugs[] = (string) $rows[$rid];
                }
            }
        }

        return $this->render('settings/index.html.twig', [
            'page_title' => 'meta.settings_title',
            'page_description' => 'meta.settings_description',
            'nav_active' => '',
            'cc_user' => $user,
            'profileForm' => $profileForm,
            'passwordForm' => $passwordForm,
            'active_tab' => $activeTab,
            'base_region_slugs' => $baseRegionSlugs,
            'has_approved_photos' => $user->isPublicProfile() && $this->hasApprovedPhotos($user),
        ]);
    }

    /**
     * Pager page-length; `back` is allowlisted (open-redirect).
     *
     * @see docs/specs/account-and-auth.md §9.4
     */
    #[Route('/settings/rows-per-page', name: 'settings_rows_per_page', methods: ['POST'])]
    public function rowsPerPage(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('rows_per_page', $request->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $this->redirectToRoute('settings');
        }

        $choice = RowsPerPage::tryFrom((string) $request->request->get('rows'));
        if (null !== $choice) {
            $user->setRowsPerPage($choice);
            $em->flush();
        }

        $back = (string) $request->request->get('back', '');
        // docs/specs/account-and-auth.md §9.4 — same allowlist as LocaleController (open-redirect).
        $safe = 1 === preg_match('#\A/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*\z#', $back);

        return $this->redirect($safe ? $back : $this->generateUrl('settings'));
    }

    /**
     * @see docs/specs/account-and-auth.md §10
     */
    #[Route('/settings/delete-request', name: 'settings_delete_request', methods: ['POST'])]
    public function deleteRequest(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('delete_request', $request->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $this->redirectToRoute('settings', ['tab' => 'security']);
        }

        // Password re-check before issuing a deletion code (shared-session).
        $password = (string) $request->request->get('current_password', '');
        if ('' === $password || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->addFlash('error', 'flash.current_password_incorrect');

            return $this->redirectToRoute('settings', ['tab' => 'security']);
        }

        $this->deletionService->requestDeletion($user);
        $this->addFlash('success', 'flash.deletion_code_sent');

        return $this->redirectToRoute('settings', ['tab' => 'security']);
    }

    /**
     * @see docs/specs/account-and-auth.md §10
     */
    #[Route('/settings/delete-confirm', name: 'settings_delete_confirm', methods: ['POST'])]
    public function deleteConfirm(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('delete_confirm', $request->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $this->redirectToRoute('settings', ['tab' => 'security']);
        }

        $code = (string) $request->request->get('deletion_code', '');

        // docs/specs/photo-uploads.md §6 — credit choice must be on the entity before purge.
        $user->setKeepMediaCredit($request->request->getBoolean('keep_media_credit'));
        $this->em->flush();

        if (!$this->deletionService->confirmDeletion($user, $code)) {
            $this->addFlash('error', 'flash.deletion_code_invalid');

            return $this->redirectToRoute('settings', ['tab' => 'security']);
        }

        $request->getSession()->invalidate();

        $this->addFlash('success', 'flash.account_deleted');

        return $this->redirectToRoute('home');
    }

    /** @see docs/specs/photo-uploads.md §6 */
    private function hasApprovedPhotos(User $user): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1 FROM media_upload WHERE user_id = ? AND status = 'approved' LIMIT 1",
            [(int) $user->getId()],
        );
    }
}
