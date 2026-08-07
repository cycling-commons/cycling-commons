<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * @api Instantiated by Symfony's router and firewall; never referenced from code.
 *      `@api` tells Psalm this is a live entry point, not dead code.
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
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Say why. A signed-in visitor who types /login (or follows a stale
        // bookmark) landed on the homepage with no explanation, which reads as
        // a broken link rather than an answered request.
        if ($this->getUser()) {
            $this->addFlash('notice', 'flash.already_signed_in');

            return $this->redirectToRoute('home');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'page_title' => 'meta.login_title',
            'page_description' => 'meta.login_description',
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        // Intercepted by the security firewall; this body is never executed.
        throw new \LogicException('This method can be blank — it will be intercepted by the logout key on your firewall.');
    }
}
