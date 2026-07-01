<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Language switcher endpoint: records the chosen locale in the session (so it
 * applies for the whole visit) and, for logged-in users, persists it to their
 * account, then returns to the page they came from.
 *
 * @api Instantiated by Symfony's router.
 */
final class LocaleController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/i18n/{_locale}', name: 'locale_switch', requirements: ['_locale' => 'en|fr|nl|de'])]
    public function switch(string $_locale, Request $request): Response
    {
        $request->getSession()->set('_locale', $_locale);

        $user = $this->getUser();
        if ($user instanceof User) {
            $user->setLocale($_locale);
            $this->em->flush();
        }

        // Return to the originating page, but only if it is our own host.
        $referer = $request->headers->get('referer');
        if (\is_string($referer) && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('home');
    }
}
