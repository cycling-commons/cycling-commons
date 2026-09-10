<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\EventSubscriber\MarkStripMailSubscriber;
use App\EventSubscriber\MarkStripResponseSubscriber;
use App\Translation\MarkerCodec;
use App\Translation\TranslateMode;
use App\Translation\TranslationCaches;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The two nets that stop a translate-mode mark leaving the server anywhere
 * except inside an HTML page (translations.md §4.1, "Two nets").
 *
 * The "MarkStripResponseSubscriber" tests below construct
 * {@see MarkStripResponseSubscriber} directly against a real
 * {@see TranslateMode} whose session decision is pre-set on a pushed
 * {@see Request}, so no kernel boot is needed for most of them. Only
 * {@see MarkStripTest::testBootJsIsStrippedOfBothMarkFormsEndToEnd} goes
 * through a real request, because it is the one thing a unit test cannot
 * stand in for: proof that a real, non-map GET route with the mode on
 * produces a body carrying the JSON-escaped form, and that this subscriber,
 * wired into the real kernel, actually catches it.
 *
 * `TranslateMode` is GET-only (owner correction 2026-08-31): `decide()`
 * returns false for any non-GET request, which is why the mail net cannot be
 * proven end-to-end through a curator POST any more (see the "defence in
 * depth" note on the "MarkStripMailSubscriber" tests below) and why the JSON
 * end-to-end test uses `/nl/boot.js` rather than a `/map/...` route
 * (`TranslateMode` excludes every route named `map*`).
 */
final class MarkStripTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

    // ---- MarkStripResponseSubscriber: unit tests ----------------------

    public function testModeOffLeavesANonHtmlBodyWithMarksUntouched(): void
    {
        // The mode being off is the FIRST thing `onResponse()` checks, before
        // any header or body work: cost on every ordinary response is one
        // attribute read on the main request, nothing else.
        $subscriber = new MarkStripResponseSubscriber($this->mode(false));
        $marked = MarkerCodec::wrap(7, false, 'hi');
        $response = new Response('{"a":"'.$marked.'"}', 200, ['Content-Type' => 'application/json']);

        $subscriber->onResponse($this->responseEvent($response));

        self::assertSame('{"a":"'.$marked.'"}', $response->getContent());
    }

    public function testSubRequestResponseIsNeverStripped(): void
    {
        $subscriber = new MarkStripResponseSubscriber($this->mode(true));
        $marked = MarkerCodec::wrap(7, false, 'hi');
        $response = new Response('{"a":"'.$marked.'"}', 200, ['Content-Type' => 'application/json']);

        $subscriber->onResponse($this->responseEvent($response, HttpKernelInterface::SUB_REQUEST));

        self::assertSame('{"a":"'.$marked.'"}', $response->getContent());
    }

    /**
     * The whole point of the net: a `text/html` body is left alone, marks and
     * all, because that is the one place they are meant to be read by
     * `assets/js/translate-mode.js` (translations.md §4.1).
     */
    public function testHtmlResponseIsNeverTouchedEvenWithMarksPresent(): void
    {
        $subscriber = new MarkStripResponseSubscriber($this->mode(true));
        $marked = MarkerCodec::wrap(7, false, 'hi');
        $response = new Response('<p>'.$marked.'</p>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $subscriber->onResponse($this->responseEvent($response));

        self::assertSame('<p>'.$marked.'</p>', $response->getContent());
    }

    public function testNonHtmlResponseWithRawMarksIsStripped(): void
    {
        $subscriber = new MarkStripResponseSubscriber($this->mode(true));
        $marked = MarkerCodec::wrap(7, false, 'hi');
        $response = new Response('{"a":"'.$marked.'","b":"plain"}', 200, ['Content-Type' => 'application/json']);

        $subscriber->onResponse($this->responseEvent($response));

        $content = (string) $response->getContent();
        self::assertStringNotContainsString(MarkerCodec::START, $content);
        self::assertSame('{"a":"hi","b":"plain"}', $content);
    }

    /**
     * `json_encode()` without `JSON_UNESCAPED_UNICODE` (what `JsonResponse`
     * and `boot.js.twig` both use) turns every mark into the literal ASCII
     * text \u2061\u200b...\u2062, not a raw U+2061 byte. A net matching only
     * {@see MarkerCodec::PATTERN} would report success here and strip
     * nothing.
     */
    public function testNonHtmlResponseWithJsonEscapedMarksIsStripped(): void
    {
        $subscriber = new MarkStripResponseSubscriber($this->mode(true));
        $marked = MarkerCodec::wrap(812, false, 'hi');
        $body = json_encode(['a' => $marked], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
        self::assertIsString($body);
        self::assertStringNotContainsString(MarkerCodec::START, $body, 'the escape hides the raw byte; that is the whole point of this test');
        $response = new Response($body, 200, ['Content-Type' => 'application/json']);

        $subscriber->onResponse($this->responseEvent($response));

        $content = (string) $response->getContent();
        self::assertStringNotContainsString('\u2061', $content, 'no literal backslash-u escape text should survive either');
        self::assertSame('{"a":"hi"}', $content);
    }

    public function testNonHtmlResponseWithNoMarksIsUnchanged(): void
    {
        $subscriber = new MarkStripResponseSubscriber($this->mode(true));
        $response = new Response('{"a":"plain"}', 200, ['Content-Type' => 'application/json']);
        $response->setEtag(md5('{"a":"plain"}'));
        $originalEtag = $response->getEtag();

        $subscriber->onResponse($this->responseEvent($response));

        self::assertSame('{"a":"plain"}', $response->getContent());
        self::assertSame($originalEtag, $response->getEtag());
    }

    /**
     * A catalogue string may legitimately contain a zero-width character on
     * its own; the net strips the {@see MarkerCodec::PATTERN}, never a bare
     * `ZERO`/`ONE` character, so that string must survive untouched.
     */
    public function testLoneZeroWidthCharacterInNonHtmlBodySurvives(): void
    {
        $subscriber = new MarkStripResponseSubscriber($this->mode(true));
        $body = '{"a":"a'.MarkerCodec::ZERO.'b"}';
        $response = new Response($body, 200, ['Content-Type' => 'application/json']);

        $subscriber->onResponse($this->responseEvent($response));

        self::assertSame($body, $response->getContent());
    }

    /**
     * Rewriting a body invalidates any `ETag` computed from the old one:
     * leaving it alone would let a client (or a shared cache keyed on it)
     * treat a marked and a stripped body as identical.
     */
    public function testETagIsRecomputedAfterStripping(): void
    {
        $subscriber = new MarkStripResponseSubscriber($this->mode(true));
        $marked = MarkerCodec::wrap(7, false, 'hi');
        $original = '{"a":"'.$marked.'"}';
        $response = new Response($original, 200, ['Content-Type' => 'application/json']);
        $response->setEtag(md5($original));

        $subscriber->onResponse($this->responseEvent($response));

        $stripped = (string) $response->getContent();
        self::assertNotSame($original, $stripped);
        self::assertSame('"'.md5($stripped).'"', $response->getEtag());
        self::assertNotSame('"'.md5($original).'"', $response->getEtag());
    }

    /**
     * `StreamedResponse::getContent()` always answers `false`: nothing to
     * scan, and nothing this subscriber may call `setContent()` on (it has no
     * body to set one on in the first place).
     */
    public function testStreamedResponseIsSkipped(): void
    {
        $subscriber = new MarkStripResponseSubscriber($this->mode(true));
        $response = new StreamedResponse(static function (): void {
            echo 'irrelevant';
        }, 200, ['Content-Type' => 'application/octet-stream']);

        $subscriber->onResponse($this->responseEvent($response));

        self::assertFalse($response->getContent());
    }

    /**
     * `BinaryFileResponse::getContent()` also always answers `false`, and
     * `setContent()` on one THROWS for any non-null value: a subscriber that
     * reached that call on a file download would break the download outright,
     * not merely fail to strip it.
     */
    public function testBinaryFileResponseIsSkipped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'markstrip-test-');
        self::assertIsString($path);
        file_put_contents($path, 'binary content, not a mark in sight');

        try {
            $subscriber = new MarkStripResponseSubscriber($this->mode(true));
            $response = new BinaryFileResponse($path);

            $subscriber->onResponse($this->responseEvent($response));

            self::assertFalse($response->getContent());
        } finally {
            unlink($path);
        }
    }

    public function testResponseSubscriberRunsLateAfterEverythingThatWritesContent(): void
    {
        self::assertSame(
            [KernelEvents::RESPONSE => ['onResponse', -1024]],
            MarkStripResponseSubscriber::getSubscribedEvents(),
        );
    }

    private function mode(bool $active): TranslateMode
    {
        $stack = new RequestStack();
        $request = Request::create('/whatever');
        $request->attributes->set(TranslateMode::ATTR, $active);
        $stack->push($request);

        // Security is a constructor dependency of TranslateMode, unused by
        // isActive() (only decide(), called during kernel.request, reads it),
        // so an unwired instance is enough here: the mode's active/inactive
        // state is pre-set on the request above, exactly as
        // TranslateMode::onRequest() would have left it.
        return new TranslateMode(new Security(new Container()), $stack);
    }

    private function responseEvent(Response $response, int $requestType = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent(new DummyMarkStripKernel(), Request::create('/whatever'), $requestType, $response);
    }

    // ---- MarkStripMailSubscriber: unit tests ---------------------------

    /**
     * Net 2 is defence in depth, not a currently reachable path: with
     * `TranslateMode` GET-only, no request that sends mail (a curator's
     * moderation decision, a rider's own actions) can have the mode on while
     * it renders the templated body, so today's codebase has no way to put a
     * mark in a mail. The subscriber, and this unit test of it, exist so a
     * FUTURE code path (a queued digest rendered outside a request, a
     * different render context) cannot reopen the hole.
     *
     * Testing this against a real curator HTTP round trip would be vacuous:
     * with the mode off during the POST that sends the mail, the mail is
     * clean whether or not this subscriber exists at all, which proves
     * nothing about the subscriber.
     */
    public function testHtmlAndTextMailBodiesWithRawMarksAreStripped(): void
    {
        $subscriber = new MarkStripMailSubscriber();
        $marked = MarkerCodec::wrap(9, false, 'Map');
        $email = (new Email())
            ->from('curator@example.test')
            ->to('rider@example.test')
            ->subject('Needs info')
            ->html('<p>Which '.$marked.'?</p>')
            ->text('Which '.$marked.'?');

        $subscriber->onMessage($this->messageEvent($email));

        self::assertSame('<p>Which Map?</p>', $email->getHtmlBody());
        self::assertSame('Which Map?', $email->getTextBody());
    }

    /**
     * Same escaped-form trap as the response net: a future template that
     * embeds a JSON blob in an email body (structured data, a tracking
     * payload) would carry the mark as literal \u2061...\u2062 text, not raw bytes.
     */
    public function testHtmlMailBodyWithJsonEscapedMarksIsStripped(): void
    {
        $subscriber = new MarkStripMailSubscriber();
        $marked = MarkerCodec::wrap(9, false, 'Map');
        $jsonFragment = json_encode(['label' => $marked], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
        self::assertIsString($jsonFragment);
        $email = (new Email())
            ->from('curator@example.test')
            ->to('rider@example.test')
            ->subject('Needs info')
            ->html('<script type="application/json">'.$jsonFragment.'</script>');

        $subscriber->onMessage($this->messageEvent($email));

        $html = (string) $email->getHtmlBody();
        self::assertStringNotContainsString('\u2061', $html);
        self::assertStringContainsString('{"label":"Map"}', $html);
    }

    public function testMailWithNoHtmlBodyIsHandledWithoutError(): void
    {
        $subscriber = new MarkStripMailSubscriber();
        $marked = MarkerCodec::wrap(9, false, 'Map');
        $email = (new Email())
            ->from('curator@example.test')
            ->to('rider@example.test')
            ->subject('Needs info')
            ->text('Which '.$marked.'?');

        $subscriber->onMessage($this->messageEvent($email));

        self::assertNull($email->getHtmlBody());
        self::assertSame('Which Map?', $email->getTextBody());
    }

    /**
     * A bare `RawMessage` (or anything that is not an `Email`) has no HTML or
     * text body to strip; the subscriber must leave it alone rather than
     * fail.
     */
    public function testNonEmailMessageIsIgnored(): void
    {
        $subscriber = new MarkStripMailSubscriber();
        $raw = new RawMessage('From: a@example.test'."\r\n\r\n".'raw body, not an Email instance');
        $event = new MessageEvent($raw, new Envelope(new Address('a@example.test'), [new Address('b@example.test')]), 'main');

        $subscriber->onMessage($event);

        self::assertSame($raw, $event->getMessage());
    }

    public function testMailSubscriberRunsAfterTheBodyIsRenderedAndBeforeSigningAndLogging(): void
    {
        // MessageListener renders the templated body at the implicit default
        // priority (0); DkimSignedMessageListener / SmimeSignedMessageListener
        // sign at -128; MessageLoggerListener and EnvelopeListener log/finalise
        // at -255 (vendor/symfony/mailer/EventListener/*.php, verified
        // 2026-08-31). -100 sits strictly between 0 and -128, so this net sees
        // the rendered body before anything signs or logs it.
        self::assertSame(
            [MessageEvent::class => ['onMessage', -100]],
            MarkStripMailSubscriber::getSubscribedEvents(),
        );
    }

    private function messageEvent(Email $email): MessageEvent
    {
        return new MessageEvent(
            $email,
            new Envelope(new Address('curator@example.test'), [new Address('rider@example.test')]),
            'main',
        );
    }

    // ---- End to end: the JSON-escaped form on a real, non-map GET ------

    /**
     * `/nl/map/catalog.json` would render with the mode OFF (`TranslateMode`
     * excludes every route named `map*`) and prove nothing about this net.
     * `/nl/boot.js` (route `boot_script`) is a real GET, is not map-prefixed,
     * and pipes translated strings through `|json_encode` with the same four
     * HEX flags `JsonResponse` uses, so it is the one route on this site that
     * genuinely exercises the escaped-form branch end to end.
     */
    public function testBootJsIsStrippedOfBothMarkFormsEndToEnd(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('net-json@example.com', 'hunter2secure!'));
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // boot.js.twig runs 'js.skip'|trans, among others; give it a live row
        // to mark, or the test would pass for want of anything to strip. The
        // wording that actually ends up on the page is whatever
        // messages.nl.yaml already says for this key ("Naar de inhoud"), not
        // the English seeded here: the entry only decides markability.
        $this->findOrCreateEntry($em, 'js.skip', 'Skip to content');
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();

        $crawler = $client->request('GET', '/nl/translate');
        $client->submit($crawler->filter('form.tr-mode-toggle')->form(['on' => '1']));
        self::assertResponseRedirects();

        $client->request('GET', '/nl/boot.js');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString(MarkerCodec::START, $body, 'no raw mark byte');
        self::assertStringNotContainsString('\u2061', $body, 'no literal JSON-escaped mark either, which is the point of this test');
        self::assertStringContainsString('Naar de inhoud', $body, 'the translated text itself must survive the strip');
    }

    /** @param list<string> $roles */
    private function createUser(string $email, string $plain, array $roles = []): User
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}

/** @internal */
final class DummyMarkStripKernel implements HttpKernelInterface
{
    #[\Override]
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        throw new \LogicException('not used');
    }
}
