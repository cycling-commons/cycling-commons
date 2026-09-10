<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Routing\LocalePrefix;
use App\Security\FormGuard;
use App\Security\ProofOfWork;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The single-use values a guarded public form needs, fetched when somebody
 * starts filling it in rather than minted into the page.
 *
 * Four forms use this: the floating bug panel, which renders on every page, and
 * the three full-page forms at `/contact`, `/report/{type}/{id}` and
 * `/report-bug`. All of them used to carry a challenge in their markup, and
 * that is what stopped those pages being cached: a challenge is **single use**
 * ({@see ProofOfWork::verify()} records it as spent), so one page held in a
 * shared cache would hand the same challenge to every reader and only the first
 * person to send anything would be accepted. Everybody else would solve a
 * puzzle that had already been used (docs/specs/page-caching.md §3.1).
 *
 * It is wasteful even uncached. The bug button is on every page and the contact
 * and report forms are linked from every footer and every drawer, so every
 * crawler hit minted a challenge that nobody would ever spend.
 *
 * ## What comes back, and what each caller uses
 *
 * `challenge` and `difficulty` are what every caller needs. `stamp` and `token`
 * are there for the bug panel, which posts as JSON and has no rendered form to
 * carry them; the three full-page forms keep their own in their markup, because
 * a form has to be postable without JavaScript even where the proof of work is
 * not.
 *
 * Nothing here is a secret and nothing identifies the caller: a signed
 * timestamp, a signed challenge, and a CSRF token. It is bounded anyway,
 * because it is public, unauthenticated, and hands out signed tokens.
 *
 * @see docs/specs/page-caching.md §3.1
 * @see docs/specs/contact-and-support.md §3
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class FormChallengeController extends AbstractController
{
    /**
     * The bug panel's token id.
     *
     * Only the panel's token is issued here, because it is the only caller with
     * no rendered form of its own. The rest keep theirs in their markup.
     */
    private const string BUG_CSRF_TOKEN_ID = 'bug_report';

    public function __construct(
        private readonly FormGuard $guard,
        private readonly ProofOfWork $proofOfWork,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly RateLimiterFactory $powChallengeLimiter,
    ) {
    }

    #[Route('/form-challenge', name: 'form_challenge', methods: ['GET'])]
    public function challenge(Request $request): Response
    {
        if (!$this->powChallengeLimiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume()->isAccepted()) {
            return $this->json(['ok' => false, 'error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $now = new \DateTimeImmutable();
        $response = $this->json([
            'ok' => true,
            'challenge' => $this->proofOfWork->issue($now),
            'difficulty' => ProofOfWork::DIFFICULTY,
            'stamp' => $this->guard->stamp($now),
            'token' => $this->csrf->getToken(self::BUG_CSRF_TOKEN_ID)->getValue(),
        ]);
        // Single use, so it must never be stored by anything, anywhere.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
