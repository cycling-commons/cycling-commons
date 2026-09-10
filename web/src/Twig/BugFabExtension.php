<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Security\FormGuard;
use App\Security\ProofOfWork;
use App\Support\BugArea;
use App\Support\BugSeverity;
use App\Support\Entity\BugReport;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Everything `partials/_bug_fab.html.twig` needs, on every page.
 *
 * The floating button lives in the base layout, so its CSRF token, signed form
 * stamp and proof-of-work challenge have to come from somewhere that every page
 * reaches without every controller knowing about it. A Twig function is that
 * somewhere.
 *
 * **It is lazy.** Twig calls this only where the partial is actually rendered,
 * so a page that suppresses the button (the map) never pays for a challenge.
 * That matters: {@see ProofOfWork::issue()} makes 12 random bytes and an HMAC
 * per call, and the button appears on every page of the site.
 *
 * @see docs/specs/contact-and-support.md §5
 *
 * @api
 */
final class BugFabExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('bug_fab_context', $this->context(...)),
        ];
    }

    /**
     * Everything the panel needs that is the same for every visitor.
     *
     * Deliberately nothing single-use: the challenge, the stamp and the CSRF
     * token moved to {@see BugReportController::challenge()} so this partial,
     * which renders on every page, can sit inside a cached page
     * (page-caching.md §3.1).
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $path = $this->requests->getCurrentRequest()?->getPathInfo() ?? '/';

        return [
            'stamp_field' => FormGuard::STAMP,
            'honeypot_a' => FormGuard::HONEYPOT_A,
            'honeypot_b' => FormGuard::HONEYPOT_B,
            'severities' => BugSeverity::all(),
            'areas' => BugArea::all(),
            // A guess from the path, so the report arrives tagged with
            // something better than "unsure" even when nobody touches the
            // dropdown. The reporter can always change it.
            'guessed_area' => BugArea::guessFromPath($path)->value,
            'max_screenshots' => BugReport::MAX_SCREENSHOTS,
        ];
    }
}
