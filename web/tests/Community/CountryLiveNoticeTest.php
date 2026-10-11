<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CountryInterestService;
use App\Content\ReleaseNotes;
use App\Controller\Admin\DashboardController;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A rider who asked for a country hears when it goes live, once, and the
 * changelog lists the country until the next release does.
 *
 * @see docs/specs/moderation-and-contribution.md §11.1
 * @see docs/specs/roadmap-and-changelog.md §6b
 */
final class CountryLiveNoticeTest extends WebTestCase
{
    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function admin(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail('live-desk@example.test');
        $u->setDisplayName('Live Desk');
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $u->setRoles(['ROLE_ADMIN']);
        $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function rider(string $email, ?string $locale = null): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName($email);
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $u->setLocale($locale);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    /** A country row; `$liveAt` is SQL, null for a country that is only seeded. */
    private function country(string $cc, ?string $liveAt): void
    {
        $this->db()->executeStatement('DELETE FROM country WHERE code = ?', [$cc]);
        $this->db()->executeStatement(
            "INSERT INTO country (code, name, subtype, status, live_at) VALUES (?, ?, 'country', ?, ".($liveAt ?? 'NULL').')',
            [$cc, $cc, null === $liveAt ? 'seeded' : 'live'],
        );
    }

    private function goLive(string $cc): void
    {
        // The test runs in one transaction, so NOW() is when it began, before
        // the requests were written; an hour on puts going live after them.
        $this->db()->executeStatement(
            "UPDATE country SET status = 'live', live_at = NOW() + INTERVAL '1 hour' WHERE code = ?",
            [$cc],
        );
    }

    private function messages(int $userId): int
    {
        return (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM user_message WHERE user_id = ? AND kind = 'country_live'",
            [$userId],
        );
    }

    public function testEachRiderWhoAskedHearsOnceInTheirOwnLanguage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin(), 'main');
        $interests = self::getContainer()->get(CountryInterestService::class);

        $this->country('DK', null);
        $en = $this->rider('dk-en@example.test');
        $nl = $this->rider('dk-nl@example.test', 'nl');
        $interests->record($en, 'DK', false, 'Home of great riders');
        $interests->record($en, 'DK', false, '', 'Jutland');
        $interests->record($nl, 'DK', true, '');

        // Not live yet: nothing to tell, and no button.
        self::assertSame([], array_intersect_key($interests->notifiable(), ['DK' => 0]));
        $crawler = $client->request('GET', '/admin/country-requests');
        self::assertCount(0, $crawler->filter('button[name="notify"][value="DK"]'));

        $this->goLive('DK');
        self::assertSame(2, $interests->notifiable()['DK'] ?? 0, 'two riders, three requests');

        $crawler = $client->request('GET', '/admin/country-requests');
        $button = $crawler->filter('button[name="notify"][value="DK"]');
        self::assertCount(1, $button);
        $client->submit($button->form());
        self::assertResponseRedirects();

        self::assertSame(1, $this->messages((int) $en->getId()), 'one message for a country and an area in it');
        self::assertSame(1, $this->messages((int) $nl->getId()));

        $bodies = [];
        foreach (self::getMailerMessages() as $mail) {
            if ($mail instanceof TemplatedEmail) {
                $bodies[(string) $mail->getTo()[0]->getAddress()] = (string) $mail->getTextBody();
            }
        }
        self::assertStringContainsString('Denmark is now on the Cycling Commons map', $bodies['dk-en@example.test'] ?? '');
        self::assertStringContainsString('Denemarken staat nu op de kaart', $bodies['dk-nl@example.test'] ?? '');

        self::assertSame(3, (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM country_interest WHERE country_code = 'DK' AND notified_at IS NOT NULL",
        ), 'every row is stamped');

        // Pressing again tells nobody twice, and the button is gone.
        self::assertSame(0, $interests->notifyLive('DK'));
        self::assertSame(1, $this->messages((int) $en->getId()));
        $crawler = $client->request('GET', '/admin/country-requests');
        self::assertCount(0, $crawler->filter('button[name="notify"][value="DK"]'));
        self::assertStringContainsString('Told it is live', $crawler->html());
    }

    public function testARequestMadeAfterTheCountryWentLiveIsNotAnswered(): void
    {
        static::createClient();
        $interests = self::getContainer()->get(CountryInterestService::class);

        // Live yesterday; this rider asks for more inside it today.
        $this->country('DK', "NOW() - INTERVAL '1 day'");
        $rider = $this->rider('dk-later@example.test');
        $interests->record($rider, 'DK', false, '', 'Bornholm');

        self::assertArrayNotHasKey('DK', $interests->notifiable());
        self::assertSame(0, $interests->notifyLive('DK'));
        self::assertSame(0, $this->messages((int) $rider->getId()));
    }

    public function testADeletedAccountsRequestIsSkipped(): void
    {
        static::createClient();
        $interests = self::getContainer()->get(CountryInterestService::class);

        $this->country('DK', null);
        $interests->record($this->rider('dk-gone@example.test'), 'DK', false, '');
        $this->db()->executeStatement("UPDATE country_interest SET user_id = NULL WHERE country_code = 'DK'");
        $this->goLive('DK');

        self::assertSame(0, $interests->notifyLive('DK'));
    }

    public function testTheNotifyPostNeedsTheToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin(), 'main');

        $client->request('POST', '/admin/country-requests', ['notify' => 'DK', '_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('country-requests', DashboardController::COUNTRY_REQUESTS_CSRF_TOKEN_ID);
    }

    public function testTheChangelogListsACountryLiveSinceTheLastRelease(): void
    {
        $client = static::createClient();
        // The day after the newest release, so the test holds on any release date.
        $this->country('DK', "TIMESTAMPTZ '".ReleaseNotes::latestRelease()['date']." 12:00:00+00' + INTERVAL '1 day'");
        // Live before the last release: that release's own notes cover it.
        $this->country('SE', "TIMESTAMPTZ '2026-01-01 12:00:00+00'");

        $crawler = $client->request('GET', '/whats-new');

        self::assertResponseIsSuccessful();
        $next = $crawler->filter('#next');
        self::assertCount(1, $next);
        self::assertStringContainsString('Denmark is on the map.', $next->text());
        self::assertStringNotContainsString('Sweden', $next->text());

        $crawler = $client->request('GET', '/nl/wat-is-er-nieuw');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Denemarken staat op de kaart.', $crawler->filter('#next')->text());
    }
}
