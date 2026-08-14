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
        /* Say why. A signed-in visitor who types /login (or follows a stale
           bookmark) landed on the homepage with no explanation, which reads as
           a broken link rather than an answered request.

           FULLY, not merely `getUser()` (owner-reported 2026-08-14). A rider
           returning on a remember-me cookie holds a user object but is only
           IS_AUTHENTICATED_REMEMBERED, and every page that asks for FULLY -
           the curator application at /join/{cc} is the one they hit - sends
           them here to upgrade. `getUser()` treated that as "you are already
           signed in", flashed exactly that, and dropped them on the homepage:
           told they needed nothing, while the page they asked for was still
           refusing them, and with no way through. The remembered rider now
           gets the form, which is what they were sent here for; the target
           path the firewall stored survives, so logging in lands them where
           they were going. */
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
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
