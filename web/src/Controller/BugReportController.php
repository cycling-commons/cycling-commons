<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Pagination\Pager;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\Security\FormGuard;
use App\Security\ProofOfWork;
use App\Support\BugArea;
use App\Support\BugSeverity;
use App\Support\Entity\BugReport;
use App\Support\GitHubIssues;
use App\Support\ScreenshotRejected;
use App\Support\ScreenshotStore;
use App\Support\SupportIntake;
use App\Support\SupportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Something is broken", from anybody, from any page.
 *
 * **Three ways in, one intake.** The floating button on every page posts here
 * as JSON without leaving the page; `/report-bug` is the same form as a plain
 * page for anybody with JavaScript off, on a locked-down browser, or following
 * a link somebody sent them; and both pre-fill the area and the page URL from
 * wherever the reporter was.
 *
 * **It works with JavaScript off.** That is the whole reason the plain page
 * exists beside the floating panel, and for a while it was not true: the proof
 * of work was mandatory, nothing solved it without scripting, and the form
 * refused exactly the visitor it was built for. The challenge is optional here
 * now, and an unsolved submission gets a much tighter daily budget than a
 * solved one. See {@see validateAndStore()}.
 *
 * **No account needed, deliberately.** The rider whose sign-up is broken cannot
 * sign in to report that sign-up is broken. An account also is not evidence of
 * good faith, and the four anti-spam layers ({@see FormGuard},
 * {@see ProofOfWork}) do not care whether there is one. A signed-in reporter
 * gets three things instead: the form pre-filled, the report attached to their
 * account so they can follow it, and an answer when it is resolved.
 *
 * **The context is captured, not typed.** Page URL, browser, viewport and build
 * are the three or four facts that make a report actionable and the ones nobody
 * ever thinks to include. The panel shows the reporter exactly what it is about
 * to send before they send it.
 *
 * @see docs/specs/contact-and-support.md §5
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class BugReportController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'bug_report';
    private const int PER_PAGE = 25;

    /** Known issues listed beside the bug form, newest first. */
    private const int RECENT_ISSUES = 6;

    public function __construct(
        private readonly FormGuard $guard,
        private readonly ProofOfWork $proofOfWork,
        private readonly SupportIntake $intake,
        private readonly SupportRepository $repository,
        private readonly ScreenshotStore $screenshots,
        private readonly RateLimiterFactory $bugReportLimiter,
        private readonly RateLimiterFactory $bugReportNoJsLimiter,
        private readonly GitHubIssues $github,
    ) {
    }

    #[Route(LocalizedPath::REPORT_BUG, name: 'bug_report', methods: ['GET'])]
    public function form(Request $request): Response
    {
        return $this->render('pages/report_bug.html.twig', $this->context($request));
    }

    /**
     * One handler for the page form and the floating panel.
     *
     * The panel sends `Accept: application/json` and gets JSON back; the page
     * form gets a rendered page. Sharing the handler is what stops the two
     * drifting apart, which is the usual way a no-JavaScript fallback quietly
     * stops accepting a field the main path requires.
     */
    #[Route(LocalizedPath::REPORT_BUG, name: 'bug_report_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $wantsJson = str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        $error = $this->validateAndStore($request);
        if (null !== $error) {
            return $wantsJson
                ? $this->json(['ok' => false, 'error' => $error[0]], $error[1])
                : $this->render(
                    'pages/report_bug.html.twig',
                    $this->context($request, error: $error[0]),
                    new Response('', $error[1]),
                );
        }

        return $wantsJson
            ? $this->json(['ok' => true])
            : $this->render('pages/report_bug.html.twig', $this->context($request, sent: true));
    }

    /**
     * The public known-issues list.
     *
     * Its own page, and linked from the bug form, because the cheapest bug
     * report to handle is the one nobody had to file. Only what a curator has
     * marked public appears; a report is never public because its author wrote
     * it.
     */
    #[Route(LocalizedPath::KNOWN_ISSUES, name: 'known_issues', methods: ['GET'])]
    public function knownIssues(Request $request): Response
    {
        // A link, not a script toggle: this route is shared-cached, and a
        // filter a shared cache cannot see in the URL serves one visitor's
        // choice to the next (docs/specs/page-caching.md §3.2).
        $fixed = 'fixed' === $request->query->getString('show');

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $this->repository->countPublicIssues($fixed),
            self::PER_PAGE,
        );

        return $this->render('pages/known_issues.html.twig', [
            'github_repo' => $this->github->repo(),
            'page_title' => 'meta.known_issues_title',
            'page_description' => 'meta.known_issues_description',
            'nav_active' => '',
            'issues' => $this->repository->publicIssues($pager['perPage'], $pager['offset'], $fixed),
            'pager' => $pager,
            'fixed' => $fixed,
            // Both counts, both tabs, on either tab: a tab that cannot say how
            // much is behind it is a tab nobody opens.
            'open_count' => $this->repository->countPublicIssues(),
            'fixed_count' => $this->repository->countPublicIssues(true),
        ]);
    }

    /**
     * Validate everything, then write. Returns [translation key, status] on
     * failure and null on success.
     *
     * @return array{0: string, 1: int}|null
     */
    private function validateAndStore(Request $request): ?array
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            return ['flash.invalid_token', Response::HTTP_UNPROCESSABLE_ENTITY];
        }

        $rejection = $this->guard->reject($request, new \DateTimeImmutable());
        if (null !== $rejection) {
            return [$rejection, Response::HTTP_UNPROCESSABLE_ENTITY];
        }

        $title = trim((string) $request->request->get('title', ''));
        $body = trim((string) $request->request->get('body', ''));
        $steps = trim((string) $request->request->get('steps', ''));
        $email = trim((string) $request->request->get('email', ''));

        if ('' === $title || mb_strlen($title) > BugReport::TITLE_MAX) {
            return ['support.bug.error.title', Response::HTTP_UNPROCESSABLE_ENTITY];
        }
        if ('' === $body) {
            return ['support.bug.error.body_required', Response::HTTP_UNPROCESSABLE_ENTITY];
        }
        if (mb_strlen($body) > BugReport::BODY_MAX) {
            return ['support.bug.error.body_too_long', Response::HTTP_UNPROCESSABLE_ENTITY];
        }
        if (mb_strlen($steps) > BugReport::STEPS_MAX) {
            return ['support.bug.error.steps_too_long', Response::HTTP_UNPROCESSABLE_ENTITY];
        }

        $user = $this->getUser();

        if ($user instanceof User) {
            // Signed in: the ACCOUNT address, and whatever was posted is
            // discarded. The form does not offer the field, so anything
            // arriving in it was put there by hand, and honouring it would let
            // a report make our server mail an address the reporter chose for
            // somebody else. Changing it is a settings decision.
            //
            // Their answer arrives in /messages either way; the mail is the
            // notification of it (SupportIntake::announceBugOutcome).
            $email = $user->getEmail();
        } else {
            // REQUIRED without an account (owner, 2026-08-28). It was optional,
            // and the thank-you page then had to hedge: "if you gave us an
            // address, we will tell you what happened". That sentence existed
            // only because the form declined to ask. A report nobody can be
            // answered about is a dead end for the person who filed it, and the
            // one thing they wanted was to hear back.
            //
            // A wrong address is refused for the same reason it always was:
            // the reporter would sit waiting for an answer that bounced.
            if ('' === $email) {
                return ['support.bug.error.email_required', Response::HTTP_UNPROCESSABLE_ENTITY];
            }
            if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                return ['support.error.email_domain', Response::HTTP_UNPROCESSABLE_ENTITY];
            }
        }

        // The proof of work is OPTIONAL here, and only here.
        //
        // This form exists so that somebody whose browser runs no JavaScript
        // can still tell us the site is broken, and for a long time it did not
        // serve that person at all: nothing computed a nonce, so the server
        // refused the post and the page's only reason to exist was false
        // (owner 2026-08-27: "if it does use js then there is no point").
        //
        // A bug reporter whose premise is "something here is broken" must not
        // fall over when the broken thing is our own JavaScript.
        //
        // Skipping it is not free. A submission that DID solve the challenge
        // gets the ordinary daily budget; one that did not gets a much smaller
        // one, so the cheap door is also the narrow door. A wrong nonce is
        // still a refusal: that is a failed attempt, not an absent one, and
        // treating it as absent would let anyone downgrade themselves on
        // purpose while looking like a solver.
        $now = new \DateTimeImmutable();
        $challenge = (string) $request->request->get('pow_challenge', '');
        $nonce = trim((string) $request->request->get('pow_nonce', ''));
        $solved = '' !== $nonce;
        if ($solved && !$this->proofOfWork->verify($challenge, $nonce, $now)) {
            // An EXPIRED challenge is not a failed test, it is somebody who
            // spent half an hour writing carefully. Refusing them was the
            // worst possible trade: the most useful report of the day, thrown
            // away, with a message blaming the reporter for a spam check.
            //
            // Downgrading it to "unsolved" gives away nothing, because an
            // unsolved submission is a door anybody can already walk through
            // by sending no nonce at all. It just lands on the narrow budget.
            //
            // A WRONG nonce is still a refusal: that is a failed attempt, not
            // an absent one.
            if (!$this->proofOfWork->isExpired($challenge, $now)) {
                return ['support.error.challenge', Response::HTTP_UNPROCESSABLE_ENTITY];
            }
            $solved = false;
        }

        $limiter = $solved ? $this->bugReportLimiter : $this->bugReportNoJsLimiter;
        if (!$limiter->create($this->guard->key($request))->consume()->isAccepted()) {
            return ['support.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS];
        }

        // Does the reporter's domain resolve at all? A wrong address is refused
        // for the reason it always was: they would sit waiting for an answer
        // that bounced. But this check is the one that leaves the process, and
        // checkdnsrr() has no timeout, so it runs AFTER the limiter: a stranger
        // posting addresses at a domain whose nameserver never answers spends
        // a token per stalled worker instead of stalling for free. A signed-in
        // reporter's address came from their account and is not re-checked.
        if (!$user instanceof User && !$this->guard->domainResolves($email)) {
            return ['support.error.email_domain', Response::HTTP_UNPROCESSABLE_ENTITY];
        }

        $report = new BugReport($title, $body);
        $report->setSteps('' !== $steps ? $steps : null);
        $report->setSeverity(BugSeverity::fromInput((string) $request->request->get('severity', '')));
        $report->setArea(BugArea::fromInput((string) $request->request->get('area', '')));
        $report->setReporterEmail('' !== $email ? $email : null);
        $report->setLocale($request->getLocale());
        $report->setIpHash($this->guard->key($request));
        $report->setPageUrl($this->cleanPageUrl($request, (string) $request->request->get('page_url', '')));
        $report->setBrowser($this->trimTo((string) $request->request->get('browser', ''), BugReport::BROWSER_MAX));
        $report->setViewport($this->trimTo((string) $request->request->get('viewport', ''), 40));
        $report->setAppVersion($this->trimTo((string) $request->request->get('app_version', ''), 60));

        if ($user instanceof User) {
            $report->setUserId($user->getId());
        }

        $shotError = $this->attachScreenshots($request, $report);
        if (null !== $shotError) {
            return [$shotError, Response::HTTP_UNPROCESSABLE_ENTITY];
        }

        $this->intake->receiveBug($report);

        return null;
    }

    /**
     * Read up to {@see BugReport::MAX_SCREENSHOTS} pasted images.
     *
     * Refuses the whole report on a bad image rather than dropping it quietly.
     * A reporter who pasted a screenshot and had it silently discarded believes
     * they sent one, and the curator reading the report cannot tell that
     * anything is missing.
     */
    private function attachScreenshots(Request $request, BugReport $report): ?string
    {
        // Deliberately NOT type-hinted as list<string>: this is whatever the
        // client posted. The is_string() guard below is a real check on real
        // input, not a formality: `screenshots[][x]=1` arrives as an array.
        $raw = array_slice($request->request->all('screenshots'), 0, BugReport::MAX_SCREENSHOTS);

        foreach ($raw as $dataUrl) {
            if (!\is_string($dataUrl) || '' === $dataUrl) {
                continue;
            }
            try {
                $report->addScreenshot($this->screenshots->acceptDataUrl($dataUrl));
            } catch (ScreenshotRejected $e) {
                return $e->translationKey();
            }
        }

        // The plain page form uploads files rather than pasting data URLs. It
        // takes the same number the panel does, so the two forms accept the
        // same thing and a reporter is not told different limits by different
        // doors into the same feature.
        foreach ($request->files->all('screenshot') as $file) {
            if (!$file instanceof UploadedFile || $report->getScreenshots()->count() >= BugReport::MAX_SCREENSHOTS) {
                continue;
            }
            $path = $file->getPathname();
            if (!is_readable($path) || filesize($path) > ScreenshotStore::MAX_UPLOAD_BYTES) {
                return 'support.bug.error.shot_too_large';
            }
            try {
                $report->addScreenshot($this->screenshots->accept((string) file_get_contents($path)));
            } catch (ScreenshotRejected $e) {
                return $e->translationKey();
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request, bool $sent = false, ?string $error = null): array
    {
        $user = $this->getUser();
        // ?on=/map/... from the floating button's no-JavaScript link.
        $on = (string) $request->query->get('on', '');

        // On a refusal, hand back every word they typed.
        //
        // This form used to re-render empty. Somebody who spent twenty minutes
        // describing a bug, and hit a validation error or a stale challenge,
        // lost all of it and was shown a spam warning. That is the worst
        // failure this page can have: it punishes exactly the person taking
        // the most care, on the one form whose whole premise is that something
        // is already broken (owner, 2026-08-28: "and then my form was empty").
        //
        // Screenshots are the one thing that cannot come back: a browser will
        // not let a server refill a file input. The page says so rather than
        // letting somebody send a report believing the image went with it.
        $back = null !== $error;
        $posted = static fn (string $field, string $fallback = ''): string => $back
            ? (string) $request->request->get($field, $fallback)
            : $fallback;

        return [
            'page_title' => 'meta.bug_report_title',
            'page_description' => 'meta.bug_report_description',
            'nav_active' => '',
            'sent' => $sent,
            'error' => $error,
            'recent_issues' => $this->repository->recentPublicIssues(self::RECENT_ISSUES),
            'severities' => BugSeverity::all(),
            'areas' => BugArea::all(),
            'selected_severity' => $posted('severity', BugSeverity::Minor->value),
            'selected_area' => $posted('area', BugArea::guessFromPath('' !== $on ? $on : '/')->value),
            'prefill_url' => $back
                ? $this->cleanPageUrl($request, (string) $request->request->get('page_url', ''))
                : $this->cleanPageUrl($request, $on),
            'kept' => [
                'title' => $posted('title'),
                'body' => $posted('body'),
                'steps' => $posted('steps'),
                'email' => $posted('email'),
            ],
            'lost_screenshots' => $back && [] !== $request->files->all('screenshot'),
            'prefill_email' => $user instanceof User ? $user->getEmail() : '',
            'form_stamp' => $this->guard->stamp(new \DateTimeImmutable()),
            'pow_difficulty' => ProofOfWork::DIFFICULTY,
            'honeypot_a' => FormGuard::HONEYPOT_A,
            'honeypot_b' => FormGuard::HONEYPOT_B,
            'stamp_field' => FormGuard::STAMP,
            'max_screenshots' => BugReport::MAX_SCREENSHOTS,
            // Both forms: the bytes for the picker's own check, and a whole
            // number of MB for the sentence a person reads.
            'max_shot_bytes' => ScreenshotStore::MAX_UPLOAD_BYTES,
            'max_shot_mb' => intdiv(ScreenshotStore::MAX_UPLOAD_BYTES, 1024 * 1024),
        ];
    }

    /**
     * Keep the path, drop everything else.
     *
     * The reporter's browser sends `location.href`, which on this site can
     * carry a search term, a bounding box, or a one-time token in the query
     * string. Only same-host URLs are kept, and only their path: enough to know
     * which page broke, never enough to be a second copy of somebody's search
     * history sitting in a support table.
     */
    private function cleanPageUrl(Request $request, string $candidate): ?string
    {
        if ('' === $candidate) {
            return null;
        }

        // A bare path is what the ?on= link sends.
        if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
            return $this->trimTo(explode('?', $candidate)[0], BugReport::URL_MAX);
        }

        $parts = parse_url($candidate);
        if (false === $parts || !isset($parts['host']) || $parts['host'] !== $request->getHost()) {
            return null;
        }

        return $this->trimTo($parts['path'] ?? '/', BugReport::URL_MAX);
    }

    private function trimTo(string $value, int $max): ?string
    {
        $value = trim($value);

        return '' === $value ? null : mb_substr($value, 0, $max);
    }
}
