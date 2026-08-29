<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Security\FormGuard;
use App\Security\ProofOfWork;
use App\Support\Entity\BugReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A refused bug report keeps every word the reporter typed
 * (docs/specs/contact-and-support.md §5).
 *
 * This form used to re-render empty. Somebody who spent twenty minutes writing
 * a careful report, and then hit a validation error or a challenge that had
 * gone stale while they wrote, lost all of it and was shown a spam warning.
 *
 * That is the worst failure this page can have. It punishes exactly the person
 * taking the most care, on the one form whose entire premise is that something
 * is already broken.
 */
final class BugFormKeepsInputTest extends WebTestCase
{
    private const string ESSAY = 'I opened the map, waited, and the tiles never painted. Reloading did not help.';

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The hidden fields, plus a stamp old enough to pass the form guard.
     *
     * Callers merge their own values ON TOP: `+` keeps the LEFT operand for a
     * duplicate key, so writing `fields($page) + ['pow_nonce' => 'x']` would
     * silently keep the empty hidden nonce and test nothing.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function fields(Crawler $page, array $overrides = []): array
    {
        $fields = [];
        foreach ($page->filter('#cc-guarded-form input[type="hidden"]') as $node) {
            $fields[$node->getAttribute('name')] = $node->getAttribute('value');
        }

        $guard = static::getContainer()->get(FormGuard::class);
        $fields[FormGuard::STAMP] = $guard->stamp(new \DateTimeImmutable('-30 seconds'));
        $fields[FormGuard::HONEYPOT_A] = '';
        $fields[FormGuard::HONEYPOT_B] = '';
        // Required without an account since 2026-08-28.
        $fields['email'] = 'reporter@cyclingcommons.org';

        return array_merge($fields, $overrides);
    }

    // -- keeping what was typed -------------------------------------------

    public function testARefusedReportComesBackWithEveryWordStillInIt(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report-bug');

        // Refused for the title, which is the ordinary way to hit this.
        $back = $client->request('POST', '/report-bug', $this->fields($page, [
            'title' => '',
            'body' => self::ESSAY,
            'steps' => 'Open /map. Wait. Reload.',
            'severity' => 'critical',
            'area' => 'map',
            'email' => 'slow.writer@cyclingcommons.org',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em()->getRepository(BugReport::class)->findAll());

        self::assertSame(self::ESSAY, $back->filter('#b-body')->text());
        self::assertSame('Open /map. Wait. Reload.', $back->filter('#b-steps')->text());
        self::assertSame('slow.writer@cyclingcommons.org', $back->filter('#b-email')->attr('value'));
        self::assertSame('critical', $back->filter('.sevrow input[checked]')->attr('value'));
        self::assertSame('map', $back->filter('#b-area option[selected]')->attr('value'));
    }

    public function testTheTitleComesBackToo(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report-bug');

        // Refused for the body this time, so the title is the field at risk.
        $back = $client->request('POST', '/report-bug', $this->fields($page, [
            'title' => 'Grey map on first load',
            'body' => '',
            'severity' => 'major',
            'area' => 'map',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Grey map on first load', $back->filter('#b-title')->attr('value'));
    }

    /** Nothing is carried into a FIRST view of the form. */
    public function testAFreshFormIsEmpty(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report-bug');

        self::assertSame('', $page->filter('#b-title')->attr('value'));
        self::assertSame('', $page->filter('#b-body')->text());
    }

    // -- the stale challenge ----------------------------------------------

    /**
     * An expired challenge must not cost somebody their report.
     *
     * It is not a failed test: it is somebody who took half an hour. Refusing
     * them threw away the most useful report of the day and blamed the
     * reporter for a spam check. It is downgraded to "unsolved" instead, which
     * is a door anybody can already walk through by sending no nonce at all.
     */
    public function testAnExpiredChallengeStillFilesTheReport(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report-bug');

        $pow = static::getContainer()->get(ProofOfWork::class);
        // Issued long enough ago that it has died, and solved correctly.
        $stale = $pow->issue(new \DateTimeImmutable('-2 hours'));

        $client->request('POST', '/report-bug', $this->fields($page, [
            'title' => 'The map will not load',
            'body' => self::ESSAY,
            'severity' => 'major',
            'area' => 'map',
            'pow_challenge' => $stale,
            'pow_nonce' => $this->solve($stale),
        ]));

        self::assertResponseIsSuccessful();
        $rows = $this->em()->getRepository(BugReport::class)->findAll();
        self::assertCount(1, $rows, 'the report is filed, not refused');
    }

    /** A WRONG nonce is still a refusal: that is a failed attempt, not an absent one. */
    public function testAWrongNonceIsStillRefused(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report-bug');

        $client->request('POST', '/report-bug', $this->fields($page, [
            'title' => 'The map will not load',
            'body' => self::ESSAY,
            'severity' => 'major',
            'area' => 'map',
            'pow_nonce' => 'nowhere-near-a-solution',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em()->getRepository(BugReport::class)->findAll());
    }

    /** No nonce at all is still fine: that is the point of this form. */
    public function testNoNonceAtAllStillFilesTheReport(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report-bug');

        $client->request('POST', '/report-bug', $this->fields($page, [
            'title' => 'Filed with scripting off',
            'body' => self::ESSAY,
            'severity' => 'major',
            'area' => 'map',
            'pow_nonce' => '',
        ]));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->em()->getRepository(BugReport::class)->findAll());
    }

    // -- the signed-in reporter -------------------------------------------

    /**
     * A signed-in reporter cannot choose a different address.
     *
     * The form does not offer the field, so anything arriving in it was put
     * there by hand, and honouring it would let a report make our server mail
     * an address the reporter chose for somebody else.
     */
    public function testAPostedAddressIsIgnoredForASignedInReporter(): void
    {
        $client = $this->client();
        $rider = (new User())->setEmail('account@cyclingcommons.org');
        $rider->setPassword('x');
        $rider->setDisplayName('Signed In Rider');
        $this->em()->persist($rider);
        $this->em()->flush();
        $client->loginUser($rider);

        $page = $client->request('GET', '/report-bug');
        self::assertCount(0, $page->filter('#b-email'), 'no editable address field');

        $client->request('POST', '/report-bug', $this->fields($page, [
            'title' => 'The map will not load',
            'body' => self::ESSAY,
            'severity' => 'major',
            'area' => 'map',
            'email' => 'somebody.else@cyclingcommons.org',
        ]));

        self::assertResponseIsSuccessful();
        $rows = $this->em()->getRepository(BugReport::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('account@cyclingcommons.org', $rows[0]->getReporterEmail());
    }

    // -- the pasted page ---------------------------------------------------

    /** Somebody who came here directly can still say which page it was. */
    public function testAPastedPageIsKept(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report-bug');
        self::assertCount(1, $page->filter('#b-url'), 'the field is offered when we do not know');

        $client->request('POST', '/report-bug', $this->fields($page, [
            'title' => 'Grey tiles',
            'body' => self::ESSAY,
            'severity' => 'major',
            'area' => 'map',
            'page_url' => 'http://localhost/regions/aargau',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('/regions/aargau', $this->em()->getRepository(BugReport::class)->findAll()[0]->getPageUrl());
    }

    /** A URL somewhere else is dropped, not stored. */
    public function testAnOffSitePageIsDropped(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report-bug');

        $client->request('POST', '/report-bug', $this->fields($page, [
            'title' => 'Grey tiles',
            'body' => self::ESSAY,
            'severity' => 'major',
            'area' => 'map',
            'page_url' => 'https://example.test/somewhere',
        ]));

        self::assertResponseIsSuccessful();
        self::assertNull($this->em()->getRepository(BugReport::class)->findAll()[0]->getPageUrl());
    }

    private function solve(string $challenge): string
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
                    ++$bits;
                }
            }
            if ($bits >= ProofOfWork::DIFFICULTY) {
                return (string) $nonce;
            }
        }
    }
}
