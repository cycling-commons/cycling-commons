<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CountryInterestService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * moderation-and-contribution.md.
 */
final class CountryInterestTest extends WebTestCase
{
    private function user(string $email): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName('Interest '.substr(md5($email), 0, 6));
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    /** Onboards a country by giving it a region, same fixture as CuratorApplicationTest. */
    private function seedCountryRegion(string $cc, string $slug): int
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 1000, ?, ?, 2, 'test', NOW(), NOW())",
            [$slug, strtoupper($slug), $cc, $cc],
        );

        return (int) $db->lastInsertId('region_id_seq');
    }

    public function testRecordingTwiceUpdatesRatherThanDuplicating(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(CountryInterestService::class);
        $u = $this->user('interest-once@example.test');

        $svc->record($u, 'ES', false, 'I ride here often');
        $second = $svc->record($u, 'ES', true, 'and I would help');

        $rows = self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchAllAssociative('SELECT * FROM country_interest WHERE user_id = ?', [$u->getId()]);

        self::assertCount(1, $rows, 'one interest per person per country');
        self::assertTrue($second->isWillingToCurate(), 'the second call upgraded the willingness flag');
        self::assertSame('and I would help', $second->getNote());
    }

    public function testCountsSeparateWillingVolunteersFromPlainInterest(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(CountryInterestService::class);
        $svc->record($this->user('c1@example.test'), 'PT', false, '');
        $svc->record($this->user('c2@example.test'), 'PT', true, '');
        $svc->record($this->user('c3@example.test'), 'PT', true, '');

        $pt = null;
        foreach ($svc->counts() as $row) {
            if ('PT' === $row['countryCode']) {
                $pt = $row;
            }
        }

        self::assertNotNull($pt, 'PT appears in the counts');
        self::assertSame(3, $pt['total']);
        self::assertSame(2, $pt['willing'], 'a country with volunteers is a different proposition');
    }

    public function testRejectsAnUnknownCountryCode(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(CountryInterestService::class);
        $this->expectException(\InvalidArgumentException::class);
        $svc->record($this->user('bad-cc@example.test'), 'ZZ', false, '');
    }

    public function testInvisibleCharactersInNoteDoNotEraseExistingNote(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(CountryInterestService::class);
        $u = $this->user('invisible-test@example.test');
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Record with a real note
        $svc->record($u, 'ES', false, 'legitimate note');

        // Re-submit with only zero-width spaces (invisible characters that trim() does not strip)
        $svc->record($u, 'ES', true, "\u{200B}\u{200B}");

        // Verify the stored note is unchanged (not erased)
        $row = $em->getConnection()
            ->fetchAssociative('SELECT note FROM country_interest WHERE user_id = ? AND country_code = ?',
                [$u->getId(), 'ES']);
        self::assertSame('legitimate note', $row['note'],
            'zero-width spaces should not erase a previously stored legitimate note');
    }

    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/join/ES');
        // Decision 1: both signals require a verified account.
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testNotOnboardedCountryOffersTheRequestForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('join-es@example.test'), 'main');

        $crawler = $client->request('GET', '/join/ES');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[data-form="country-interest"]')->count(),
            'a country with no regions offers the request form');
        self::assertSame(0, $crawler->filter('form[data-form="curator-application"]')->count(),
            'and never the application form — there is nothing to curate yet');
    }

    public function testPostingTheRequestRecordsIt(): void
    {
        $client = static::createClient();
        $u = $this->user('join-post@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/ES');
        $token = $crawler->filter('form[data-form="country-interest"] input[name="_token"]')->attr('value');

        $client->request('POST', '/join/ES', [
            '_token' => $token, 'willing' => '1', 'note' => 'I ride here every summer',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/join/ES');
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT * FROM country_interest WHERE user_id = ?', [$u->getId()]);
        self::assertIsArray($row);
        self::assertTrue((bool) $row['willing_to_curate']);
    }

    public function testOnboardedCountryOffersOnlyTheApplicationForm(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('IT', 'italy-join-test');
        $client->loginUser($this->user('join-it@example.test'), 'main');

        $crawler = $client->request('GET', '/join/IT');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[data-form="curator-application"]')->count(),
            'a country with regions offers the application form');
        self::assertSame(0, $crawler->filter('form[data-form="country-interest"]')->count(),
            'and never the interest form — someone can already apply to curate here');
    }

    public function testPostingAValidApplicationRecordsIt(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('IT', 'italy-join-test');
        $u = $this->user('join-it-apply@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/IT');
        $token = $crawler->filter('form[data-form="curator-application"] input[name="_token"]')->attr('value');

        $client->request('POST', '/join/IT', [
            '_token' => $token, 'about' => 'I ride these roads every weekend',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/join/IT');
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT * FROM curator_application WHERE user_id = ?', [$u->getId()]);
        self::assertIsArray($row);
        self::assertSame('pending', $row['status']);
    }

    /**
     * Regression for the crafted-POST vulnerability: the branch used to be
     * chosen by whether `about` was present, so a POST to an onboarded
     * country with `about` omitted fell through to the interest branch and
     * wrote a country_interest row for a country that only ever offers the
     * application form. The branch is now decided by $onboarded alone, so
     * the same payload must fail cleanly (page-level error) instead.
     */
    public function testPostingAnApplicationWithoutAboutFailsCleanlyAndSkipsInterest(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('IT', 'italy-join-test');
        $u = $this->user('join-it-empty@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/IT');
        $token = $crawler->filter('form[data-form="curator-application"] input[name="_token"]')->attr('value');

        $client->request('POST', '/join/IT', [
            '_token' => $token,
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseIsSuccessful('a missing "about" is a clean page-level error, never a 500');
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT * FROM country_interest WHERE user_id = ?', [$u->getId()]);
        self::assertFalse($row, 'no interest row must be created for an onboarded country, regardless of payload');
    }
}
