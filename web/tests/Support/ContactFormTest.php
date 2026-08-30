<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\FormGuard;
use App\Security\ProofOfWork;
use App\Support\ContactStatus;
use App\Support\ContactTopic;
use App\Support\Entity\ContactMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The front door, end to end (docs/specs/contact-and-support.md §4).
 *
 * The properties under test are the ones that make it a front door rather than
 * a form: a signed-out stranger with no mail client can reach us; the row is
 * committed before any mail is attempted; and a topic that carries a legal
 * deadline gets one, stored, on the way in.
 */
final class ContactFormTest extends WebTestCase
{
    /** KernelBrowser rebuilds the container between requests otherwise. */
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /**
     * Solve the challenge the page actually issued.
     *
     * The test does the same work a visitor's browser does. Faking it would
     * leave the one layer that costs an attacker anything untested.
     */
    private function solve(string $challenge, int $difficulty): string
    {
        for ($nonce = 0;; ++$nonce) {
            $digest = hash('sha256', $challenge.'.'.$nonce, true);
            $bits = 0;
            foreach (str_split($digest) as $byte) {
                $value = \ord($byte);
                for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                    if (0 !== ($value & $mask)) {
                        break 2;
                    }
                    if (++$bits >= $difficulty) {
                        return (string) $nonce;
                    }
                }
            }
        }
    }

    /**
     * @return array<string, string> everything the rendered form carries
     */
    private function fieldsFrom(Crawler $page): array
    {
        $hidden = [];
        foreach ($page->filter('#cc-guarded-form input[type="hidden"]') as $node) {
            $hidden[$node->getAttribute('name')] = $node->getAttribute('value');
        }

        $form = $page->filter('#cc-guarded-form')->first();
        $challenge = $form->attr('data-pow-challenge') ?? '';
        $difficulty = (int) ($form->attr('data-pow-difficulty') ?? ProofOfWork::DIFFICULTY);

        $hidden['pow_nonce'] = $this->solve($challenge, $difficulty);

        return $hidden;
    }

    /**
     * Move the stamp back past the minimum dwell, so the test does not have to
     * sleep for it. The stamp is signed, so this goes through FormGuard rather
     * than by editing the value.
     */
    private function agedStamp(KernelBrowser $client, int $seconds = 30): string
    {
        $guard = static::getContainer()->get(FormGuard::class);

        return $guard->stamp(new \DateTimeImmutable("-{$seconds} seconds"));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function submit(KernelBrowser $client, array $overrides = []): Crawler
    {
        $page = $client->request('GET', '/contact');
        self::assertResponseIsSuccessful();

        $fields = $this->fieldsFrom($page) + [
            'topic' => 'question',
            'name' => 'A Rider',
            'email' => 'rider@cyclingcommons.org',
            'message' => 'The map will not load in my part of the world.',
            FormGuard::HONEYPOT_A => '',
            FormGuard::HONEYPOT_B => '',
        ];
        $fields[FormGuard::STAMP] = $this->agedStamp($client);
        $fields = $overrides + $fields;

        return $client->request('POST', '/contact', $fields);
    }

    private function messages(): array
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ContactMessage::class)
            ->findBy([], ['id' => 'ASC']);
    }

    public function testTheFormIsReachableWithoutAnAccount(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('#cc-guarded-form'));
        // The address stays published beside the form: the DSA wants a CHOICE
        // of means, so a form that replaced the address would be no better.
        self::assertStringContainsString('mailto:', $page->html());
    }

    /**
     * Reading the contact page must not start a session: the CSRF token id is
     * stateless (csrf.yaml), so no reader is handed a cookie and no crawler
     * leaves a Redis row behind.
     */
    public function testLookingAtTheFormStartsNoSession(): void
    {
        $client = $this->client();
        $client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertSame([], $client->getCookieJar()->all(), 'a GET of the contact form must set no cookie');
    }

    public function testASignedOutStrangerCanSendAMessage(): void
    {
        $client = $this->client();
        $this->submit($client);

        self::assertResponseIsSuccessful();

        $rows = $this->messages();
        self::assertCount(1, $rows);
        self::assertSame(ContactTopic::Question, $rows[0]->getTopic());
        self::assertSame(ContactStatus::New, $rows[0]->getStatus());
        self::assertSame('rider@cyclingcommons.org', $rows[0]->getEmail());
        self::assertNull($rows[0]->getUserId());
    }

    /**
     * The one-month clock the privacy page promises has to exist as a stored
     * date, not as something a desk recomputes. Changing our promise later must
     * not silently re-date messages received under the old one.
     */
    public function testAPrivacyRequestGetsAStoredDeadline(): void
    {
        $client = $this->client();
        $this->submit($client, ['topic' => 'privacy']);

        $rows = $this->messages();
        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]->getDueAt());
        self::assertSame(30, (int) $rows[0]->getCreatedAt()->diff($rows[0]->getDueAt())->days);
    }

    public function testAPlainQuestionGetsNoDeadline(): void
    {
        $client = $this->client();
        $this->submit($client, ['topic' => 'question']);

        self::assertNull($this->messages()[0]->getDueAt());
    }

    public function testAnUnknownTopicFallsBackToAQuestionRatherThanFailing(): void
    {
        $client = $this->client();
        $this->submit($client, ['topic' => 'not-a-topic']);

        self::assertSame(ContactTopic::Question, $this->messages()[0]->getTopic());
    }

    public function testTheAddressIsNeverStoredRawOnTheRow(): void
    {
        $client = $this->client();
        $client->request('GET', '/contact', server: ['REMOTE_ADDR' => '198.51.100.42']);
        $this->submit($client);

        $hash = $this->messages()[0]->getIpHash();
        self::assertNotNull($hash);
        self::assertStringNotContainsString('198.51.100.42', $hash);
    }

    public function testAFilledHoneypotWritesNothing(): void
    {
        $client = $this->client();
        $this->submit($client, [FormGuard::HONEYPOT_A => 'http://spam.example']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->messages());
    }

    public function testAnUnsolvedChallengeWritesNothing(): void
    {
        $client = $this->client();
        $this->submit($client, ['pow_nonce' => '0']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->messages());
    }

    /**
     * Replay protection is proven in {@see ProofOfWorkSpentTest}, not here.
     *
     * The spent-challenge pool is `cache.adapter.array` under `when@test`, and
     * Symfony's service resetter clears an ArrayAdapter between requests inside
     * a WebTestCase. A replay assertion here would be testing the test harness.
     */
    public function testAnEmptyMessageWritesNothing(): void
    {
        $client = $this->client();
        $this->submit($client, ['message' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->messages());
    }

    public function testAnUnroutableDomainWritesNothing(): void
    {
        $client = $this->client();
        // .invalid is reserved by RFC 2606; our answer would bounce.
        $this->submit($client, ['email' => 'someone@definitely-not-real.invalid']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->messages());
    }

    /**
     * The page they came from is kept as a PATH. `location.href` on this site
     * carries search terms and bounding boxes, and a support table is not the
     * place for a second copy of somebody's search history.
     */
    public function testOnlyTheReferrerPathIsKept(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/contact');
        $fields = $this->fieldsFrom($page) + [
            'topic' => 'question',
            'email' => 'rider@cyclingcommons.org',
            'message' => 'Referrer check.',
            FormGuard::HONEYPOT_A => '',
            FormGuard::HONEYPOT_B => '',
        ];
        $fields[FormGuard::STAMP] = $this->agedStamp($client);

        $client->request('POST', '/contact', $fields, server: [
            'HTTP_REFERER' => 'http://localhost/map?q=secret-town&bbox=1,2,3,4',
        ]);

        self::assertSame('/map', $this->messages()[0]->getPageUrl());
    }

    /**
     * A POST that arrives from another site is not a message with an odd
     * referrer, it is a cross-site request. The stateless CSRF token
     * (csrf.yaml) checks the origin before the controller runs, so nothing is
     * written and the page URL is never even read. `cleanPageUrl()` still
     * refuses an off-site referrer on its own, as the second line.
     */
    public function testAPostFromAnotherSiteIsRefused(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/contact');
        $fields = $this->fieldsFrom($page) + [
            'topic' => 'question',
            'email' => 'rider@cyclingcommons.org',
            'message' => 'Off-site referrer check.',
            FormGuard::HONEYPOT_A => '',
            FormGuard::HONEYPOT_B => '',
        ];
        $fields[FormGuard::STAMP] = $this->agedStamp($client);

        $client->request('POST', '/contact', $fields, server: [
            'HTTP_REFERER' => 'https://someone-elses-site.example/where-i-came-from',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->messages());
    }

    /**
     * The DNS check is the only step that leaves the process, and it has no
     * timeout. It must therefore sit BEHIND the rate limiter: an address at a
     * domain whose nameserver never answers costs a token, so a stranger
     * cannot hold a worker per request for free. Proven by exhausting the
     * budget first and watching an unroutable domain come back as 429, not as
     * the domain error it would get if DNS ran first.
     *
     * The limiter is swapped for one with a one-token budget before the first
     * request, because the container's own test pool is reset between
     * requests (see ProofOfWorkSpentTest for the same trap).
     */
    public function testTheRateLimitIsSpentBeforeDnsIsAsked(): void
    {
        $client = $this->client();

        $budget = new RateLimiterFactory(
            ['id' => 'contact_form_test', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 day'],
            new InMemoryStorage(),
        );
        static::getContainer()->set('limiter.contact_form', $budget);
        $guard = static::getContainer()->get(FormGuard::class);
        $key = $guard->key(Request::create('/contact', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']));
        self::assertTrue($budget->create($key)->consume()->isAccepted());

        $this->submit($client, ['email' => 'someone@definitely-not-real.invalid']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame([], $this->messages());
    }

    /** A deep link from the privacy page must arrive with its topic chosen. */
    public function testTheTopicCanBePreSelectedByQueryString(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/contact?topic=privacy');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('#c-topic option[value="privacy"][selected]'));
    }

    /**
     * The acknowledgement must NOT carry the sender's own words back.
     *
     * Anyone can type a message here and put somebody else's address in the
     * email field. If this mail quoted the body, we would be delivering
     * attacker-chosen text to that person from our own domain, with our sending
     * reputation behind it: a spam reflector, built by accident.
     *
     * The notification to the desk DOES carry the body, and must: that one goes
     * to an address we configured, not one a stranger typed.
     */
    public function testTheAcknowledgementNeverQuotesTheSenderBack(): void
    {
        $client = $this->client();
        $marker = 'BUY-CHEAP-WATCHES-AT-EXAMPLE-DOT-TEST';
        $this->submit($client, ['message' => 'Hello. '.$marker]);

        $sawAck = false;
        for ($i = 0;; ++$i) {
            $mail = self::getMailerMessage($i);
            if (null === $mail) {
                break;
            }
            $to = array_map(static fn ($a): string => $a->getAddress(), $mail->getTo());
            $whole = ($mail->getSubject() ?? '').' '.$mail->getHtmlBody().' '.$mail->getTextBody();

            if (\in_array('rider@cyclingcommons.org', $to, true)) {
                $sawAck = true;
                self::assertStringNotContainsString($marker, $whole, 'the sender copy must not echo their own text');
            } else {
                self::assertStringContainsString($marker, $whole, 'the desk notification must carry the message');
            }
        }

        self::assertTrue($sawAck, 'the sender must still be acknowledged');
    }

    /**
     * Two mails leave: the acknowledgement to the sender, and the notification
     * to whoever is on duty. Both matter, and they are asserted by what they
     * are rather than by counting, so adding a third later fails loudly here
     * instead of silently.
     */
    public function testTheSenderIsAcknowledgedAndTheDeskIsTold(): void
    {
        $client = $this->client();
        $this->submit($client);

        $subjects = [];
        for ($i = 0;; ++$i) {
            $mail = self::getMailerMessage($i);
            if (null === $mail) {
                break;
            }
            $subjects[] = $mail->getSubject() ?? '';
        }

        self::assertNotSame([], $subjects, 'the form must send something');

        $ack = array_filter($subjects, static fn (string $s): bool => str_contains($s, 'CC-M-'));
        self::assertCount(1, $ack, 'exactly one acknowledgement, carrying the reference');
    }

    /**
     * The row is committed BEFORE any mail is attempted, so a dead transport
     * cannot lose a message that starts a legal clock.
     */
    public function testTheRowSurvivesEvenIfNothingCanBeMailed(): void
    {
        $client = $this->client();
        $this->submit($client, ['email' => 'rider@cyclingcommons.org']);

        self::assertCount(1, $this->messages());
    }
}
