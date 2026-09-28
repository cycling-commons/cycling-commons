<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Controller;

use App\EventSubscriber\StatelessLoginRedirectSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * @see docs/specs/account-and-auth.md §5
 *
 * @api
 */
final class SecurityController extends AbstractController
{
    #[Route([
        'en' => '/login',
        'fr' => '/fr/login',
        'nl' => '/nl/login',
        'de' => '/de/login',
        'es' => '/es/login',
    ], name: 'login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        // FULLY, not getUser(): remember-me must still see the form to upgrade.
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            $this->addFlash('notice', 'flash.already_signed_in');

            return $this->redirectToRoute('home');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            // The page to return to, carried in the link rather than a session
            // (StatelessLoginRedirectSubscriber). A local path only.
            'target_path' => StatelessLoginRedirectSubscriber::localPath($request->query->getString('_target_path')),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'page_title' => 'meta.login_title',
            'page_description' => 'meta.login_description',
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This method can be blank — it will be intercepted by the logout key on your firewall.');
    }
}
