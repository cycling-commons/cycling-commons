<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

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
 * Account settings page — display name, public-profile toggle, and password change.
 *
 * Email is read-only here: changing it needs re-verification, so users are
 * pointed to support instead.
 *
 * @see docs/specs/account-and-auth.md §8
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
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

        // ── Profile settings form (display name + public profile) ──
        $profileForm = $this->createForm(SettingsType::class, $user);
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            // Base location (map-and-search.md §4.5): unmapped fields, handled
            // here before flush so the derived region/country set can never drift
            // from the stored point. The pin-drop path lives on the map page's
            // "Set my area"; this form only takes a Photon town pick + radius.
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
                // Radius-only change (the hidden lat/lng/place fields are never
                // pre-filled from the entity — only JS fills them on a fresh
                // Photon pick): re-derive from the STORED coarse point, not from
                // empty form input.
                $this->baseLocations->apply(
                    $user,
                    (float) $user->getBaseLat(),
                    (float) $user->getBaseLng(),
                    $user->getBasePlace(),
                    (int) $radius,
                );
            }
            // Garbage/absent coords with no radius change fall through here:
            // the base location is left untouched, the rest of the form still saves.

            $this->em->flush();

            // Apply the (possibly changed) language choice immediately; clearing it
            // falls back to the browser/site default on the next request.
            if (null !== $user->getLocale()) {
                $request->getSession()->set('_locale', $user->getLocale());
            } else {
                $request->getSession()->remove('_locale');
            }

            $this->addFlash('success', 'flash.profile_saved');

            return $this->redirectToRoute('settings');
        }

        // ── Password change form ──
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

        // Two-tab settings (account-and-auth.md §8): Security is active when asked for
        // via ?tab=security or when the password form just failed validation
        // (a 422 re-render must show the tab holding the errors).
        $activeTab = 'security' === $request->query->get('tab')
            || ($passwordForm->isSubmitted() && !$passwordForm->isValid())
            ? 'security' : 'profile';

        // Current derived area (map-and-search.md §4.5): slugs for the
        // template, which renders each through the existing region.<slug>.label
        // keys — the same convention the map's scope selector uses.
        $baseRegionSlugs = [];
        if ([] !== $user->getBaseRegionIds()) {
            // Keep the DERIVED order (containing region first, then by
            // distance — map-and-search.md §4.5), not alphabetical: the
            // first label the rider reads should be where they actually live.
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
        ]);
    }

    /**
     * Step 1 of account deletion: validate CSRF, send a one-time code by email.
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

        $this->deletionService->requestDeletion($user);
        $this->addFlash('success', 'flash.deletion_code_sent');

        return $this->redirectToRoute('settings', ['tab' => 'security']);
    }

    /**
     * Step 2 of account deletion: validate CSRF + code, delete the account, invalidate session.
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

        if (!$this->deletionService->confirmDeletion($user, $code)) {
            $this->addFlash('error', 'flash.deletion_code_invalid');

            return $this->redirectToRoute('settings', ['tab' => 'security']);
        }

        $request->getSession()->invalidate();

        $this->addFlash('success', 'flash.account_deleted');

        return $this->redirectToRoute('home');
    }
}
