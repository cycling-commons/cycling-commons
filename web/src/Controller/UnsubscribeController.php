<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Account\UpdatesSubscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One click, no password, no form.
 *
 * The link in every release email. Three decisions worth stating:
 *
 * **Unprefixed, like the verify link is not.** `/verify/email` was localized
 * because it has a page to come back to and a language to answer in. This one
 * ends the relationship: whichever locale the reader clicks from, the answer is
 * a sentence, and the request carries `Accept-Language` for it.
 *
 * **It answers the same way whether or not it worked.** A bad signature, an
 * account that no longer exists, and an account already unsubscribed all get
 * "you will not get these". Distinguishing them would make the URL an oracle
 * for whether a given account exists.
 *
 * **GET, not POST.** A mail client that pre-fetches links would, on a POST-only
 * unsubscribe, leave the reader still subscribed and confused; on GET it does
 * the one thing the reader wanted anyway. Unsubscribing is not destructive and
 * the flag can be turned straight back on in settings.
 *
 * @see docs/specs/roadmap-and-changelog.md §4
 *
 * @api
 */
final class UnsubscribeController extends AbstractController
{
    #[Route('/unsubscribe', name: 'updates_unsubscribe', methods: ['GET'])]
    public function unsubscribe(Request $request, UpdatesSubscription $updates): Response
    {
        $uuid = (string) $request->query->get('u', '');
        $token = (string) $request->query->get('t', '');

        $updates->unsubscribe($uuid, $token);

        return $this->render('pages/unsubscribed.html.twig', [
            'page_title' => 'meta.unsubscribed_title',
            'page_description' => 'meta.unsubscribed_description',
            'nav_active' => '',
        ]);
    }
}
