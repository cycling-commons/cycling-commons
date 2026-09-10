<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\Security\FormGuard;
use App\Security\ProofOfWork;
use App\Support\ContactTopic;
use App\Support\Entity\ContactMessage;
use App\Support\OrganisationIdentity;
use App\Support\SupportIntake;
use App\Support\SupportMailer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The front door: a form anybody can use, with no mail client and no account.
 *
 * **What it replaces.** Thirteen `mailto:` links. A `mailto:` is a dead end for
 * anyone whose browser has no desktop mail client bound to it, which is most
 * people on webmail. The click opens an empty "choose an application" dialog,
 * or does nothing at all, and the visitor leaves. It also recorded nothing, so
 * nobody could say whether a message had ever been answered.
 *
 * **What it satisfies.** Digital Services Act Article 12 requires a provider to
 * designate a single point of contact for *recipients of the service*, to let
 * them choose the means of communication, and not to rely solely on automated
 * tools. Hence a form AND a published address side by side, and a stated
 * response time. The page also carries the legal identity block that
 * art. 3:15d BW requires ({@see OrganisationIdentity}).
 *
 * **No third-party bot check.** No Turnstile, no reCAPTCHA. Four local layers
 * instead: {@see FormGuard} (two honeypots, a signed timer, a hashed-address
 * rate limit) and {@see ProofOfWork}. The order below matters: the cheap
 * checks run before the rate limiter so a flood of obvious bots does not spend
 * a genuine visitor's budget, and the limiter runs before anything is written.
 *
 * @see docs/specs/contact-and-support.md §4
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class ContactController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'contact_form';

    public function __construct(
        private readonly FormGuard $guard,
        private readonly ProofOfWork $proofOfWork,
        private readonly SupportIntake $intake,
        private readonly OrganisationIdentity $organisation,
        private readonly SupportMailer $mailer,
        private readonly RateLimiterFactory $contactFormLimiter,
        #[Autowire('%cc.support.public_email%')]
        private readonly string $publicEmail,
    ) {
    }

    #[Route(LocalizedPath::CONTACT, name: 'contact', methods: ['GET'])]
    public function form(Request $request): Response
    {
        return $this->render('pages/contact.html.twig', $this->context($request));
    }

    #[Route(LocalizedPath::CONTACT, name: 'contact_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            return $this->again($request, 'flash.invalid_token', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Honeypots and the signed timer: free, and they catch the bulk.
        $rejection = $this->guard->reject($request, new \DateTimeImmutable());
        if (null !== $rejection) {
            return $this->again($request, $rejection, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $topic = ContactTopic::fromInput((string) $request->request->get('topic', ''));
        $email = trim((string) $request->request->get('email', ''));
        $name = trim((string) $request->request->get('name', ''));
        $body = trim((string) $request->request->get('message', ''));

        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL) || mb_strlen($email) > ContactMessage::EMAIL_MAX) {
            return $this->again($request, 'support.error.email', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('' === $body) {
            return $this->again($request, 'support.error.message_required', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($body) > ContactMessage::BODY_MAX) {
            return $this->again($request, 'support.error.message_too_long', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($name) > ContactMessage::NAME_MAX) {
            return $this->again($request, 'support.error.name_too_long', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Proof of work last of the cheap checks: it costs the visitor's CPU,
        // so it should not be spent on a submission that fails validation.
        $now = new \DateTimeImmutable();
        $challenge = (string) $request->request->get('pow_challenge', '');
        if (!$this->proofOfWork->verify(
            $challenge,
            (string) $request->request->get('pow_nonce', ''),
            $now,
        )) {
            // Expired is not failed: it is somebody who took their time. This
            // form needs a solved nonce (it needs JavaScript to work at all),
            // so it cannot be waved through the way the bug form's can. It gets
            // its own message instead. The re-rendered page carries a fresh
            // challenge, the script re-solves it, and one more press sends the
            // words that are now still in the box.
            $key = $this->proofOfWork->isExpired($challenge, $now)
                ? 'support.error.challenge_stale'
                : 'support.error.challenge';

            return $this->again($request, $key, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->contactFormLimiter->create($this->guard->key($request))->consume()->isAccepted()) {
            return $this->again($request, 'support.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        // A domain that resolves nowhere means the acknowledgement, and any
        // answer, will bounce. Checked here rather than by mailing and hoping.
        //
        // AFTER the limiter, deliberately. This is the one check that leaves
        // the process: checkdnsrr() has no timeout of its own, and a resolver
        // that answers slowly holds a PHP-FPM worker for the whole wait. Run
        // before the limiter, a stranger could post an address at a domain
        // whose nameserver never answers, as often as they liked, and each
        // attempt would tie up a worker without spending a single token. Here
        // it costs a genuine rider with a typo in the domain one of the day's
        // budget, which is the cheaper of the two mistakes.
        if (!$this->guard->domainResolves($email)) {
            return $this->again($request, 'support.error.email_domain', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = new ContactMessage($topic, $email, $body);
        $message->setName('' !== $name ? $name : null);
        $message->setLocale($request->getLocale());
        $message->setIpHash($this->guard->key($request));
        $message->setPageUrl($this->cleanReferrerPath($request));

        $user = $this->getUser();
        if ($user instanceof User) {
            $message->setUserId($user->getId());
        }

        $this->intake->receiveContact($message);

        // The reference is shown on the page, not only mailed. A sender whose
        // copy bounces still has the one thing they need to chase this.
        return $this->render('pages/contact.html.twig', $this->context(
            $request,
            sent: true,
            reference: $this->mailer->contactReference($message),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request, bool $sent = false, ?string $error = null, ?string $reference = null): array
    {
        $user = $this->getUser();

        // On a refusal, hand back every word they typed.
        //
        // This page used to re-render empty, exactly as the bug form did, and
        // it matters more here: this is where somebody writes a data request or
        // a long question. Losing it punishes the person who wrote the most.
        $back = null !== $error;
        $posted = static fn (string $field, string $fallback = ''): string => $back
            ? (string) $request->request->get($field, $fallback)
            : $fallback;

        return [
            'page_title' => 'meta.contact_title',
            'page_description' => 'meta.contact_description',
            'nav_active' => 'contact',
            'sent' => $sent,
            'error' => $error,
            'reference' => $reference,
            'topics' => ContactTopic::all(),
            // ?topic=privacy from a deep link, so "exercise your rights" on the
            // privacy page lands on the form already set to the right thing.
            'selected_topic' => $back
                ? ContactTopic::fromInput((string) $request->request->get('topic', ''))->value
                : ContactTopic::fromInput((string) $request->query->get('topic', ''))->value,
            'kept' => [
                'name' => $posted('name'),
                'email' => $posted('email'),
                'message' => $posted('message'),
            ],
            'organisation' => $this->organisation,
            // Published NEXT TO the form, never instead of it: DSA Art. 12
            // wants a choice of means, and this page replaced thirteen
            // mailto: links that were the only choice before it.
            'public_email' => $this->publicEmail,
            'prefill_email' => $user instanceof User ? $user->getEmail() : '',
            'prefill_name' => $user instanceof User ? $user->getDisplayName() : '',
            'form_stamp' => $this->guard->stamp(new \DateTimeImmutable()),
            'pow_difficulty' => ProofOfWork::DIFFICULTY,
            'honeypot_a' => FormGuard::HONEYPOT_A,
            'honeypot_b' => FormGuard::HONEYPOT_B,
            'stamp_field' => FormGuard::STAMP,
        ];
    }

    /**
     * Re-render with the error, a fresh stamp and a fresh challenge.
     *
     * Fresh on purpose: the old challenge may have been spent, and the old
     * stamp is now old enough that a second attempt would fail the timer for a
     * reason that has nothing to do with what the visitor got wrong.
     */
    private function again(Request $request, string $error, int $status): Response
    {
        return $this->render(
            'pages/contact.html.twig',
            $this->context($request, sent: false, error: $error),
            new Response('', $status),
        );
    }

    /**
     * The path the visitor came from, if it is one of ours.
     *
     * Path only, never the full referrer: query strings on our own pages can
     * carry search terms, and an off-site referrer is somebody else's URL and
     * none of our business.
     */
    private function cleanReferrerPath(Request $request): ?string
    {
        $referrer = (string) $request->headers->get('referer', '');
        if ('' === $referrer) {
            return null;
        }

        $parts = parse_url($referrer);
        if (false === $parts || !isset($parts['host']) || $parts['host'] !== $request->getHost()) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        return mb_substr($path, 0, 500);
    }
}
