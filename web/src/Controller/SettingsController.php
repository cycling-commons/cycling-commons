<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Entity\User;
use App\Form\SettingsPasswordType;
use App\Form\SettingsType;
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
 * Email change decision: email is rendered read-only with a "contact support" note.
 * Reason: changing email requires re-verification (EmailVerifier token flow) which
 * is a non-trivial addition for this task. The simpler, honest option is chosen here
 * and documented. Email change via support is noted in the template.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
#[IsGranted('ROLE_USER')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
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
            $this->em->flush();
            $this->addFlash('success', 'Profile settings saved.');

            return $this->redirectToRoute('settings');
        }

        // ── Password change form ──
        $passwordForm = $this->createForm(SettingsPasswordType::class);
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            /** @var string $currentPassword */
            $currentPassword = $passwordForm->get('currentPassword')->getData();

            if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('password_error', 'Current password is incorrect.');

                return $this->redirectToRoute('settings');
            }

            /** @var string $newPassword */
            $newPassword = $passwordForm->get('newPassword')->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->em->flush();

            $this->addFlash('success', 'Password changed successfully.');

            return $this->redirectToRoute('settings');
        }

        return $this->render('settings/index.html.twig', [
            'page_title' => 'Cycling Commons — Settings',
            'page_description' => 'Manage your Cycling Commons account settings.',
            'nav_active' => '',
            'cc_user' => $user,
            'profileForm' => $profileForm,
            'passwordForm' => $passwordForm,
        ]);
    }
}
