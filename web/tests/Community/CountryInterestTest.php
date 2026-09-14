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

    /**
     * A boundary we hold, so the area can be offered. The test database loads
     * none, which is right: each test seeds exactly the divisions it asserts.
     */
    private function seedDivision(string $cc, string $iso, string $name, float $areaKm2 = 100000.0): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "INSERT INTO world_division (country_code, iso_code, name, subtype, geom, area_km2, source, created_at, updated_at)
             VALUES (?, ?, ?, 'region', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), ?, 'test', NOW(), NOW())",
            [$cc, $iso, $name, $areaKm2],
        );
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

    /**
     * A country being here does not mean every part of it is.
     *
     * Owner 2026-09-13: "Or region. f.e. USA we have states at the region
     * level." Until then an onboarded country offered one form, the curator
     * application, so a rider whose own state was uncovered could only ask by
     * volunteering to run it. Both forms are offered now.
     */
    public function testOnboardedCountryOffersBothTheApplicationAndTheAreaRequest(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('IT', 'italy-join-test');
        $client->loginUser($this->user('join-it@example.test'), 'main');

        $crawler = $client->request('GET', '/join/IT');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[data-form="curator-application"]')->count(),
            'a country with regions offers the application form');
        self::assertSame(1, $crawler->filter('form[data-form="country-interest"]')->count(),
            'and the area request, for somebody whose part of it is uncovered');
        self::assertSame(1, $crawler->filter('input[name="region_name"]')->count(),
            'the area is free text: the region being asked for has no row yet');
    }

    public function testARiderCanAskForAnAreaOfACountryThatIsAlreadyOnboarded(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('IT', 'italy-area-test');
        $u = $this->user('join-it-area@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/IT');
        $token = $crawler->filter('form[data-form="country-interest"] input[name="_token"]')->attr('value');

        $client->request('POST', '/join/IT', [
            'form' => 'country-interest',
            '_token' => $token,
            'region_name' => 'Alto Adige',
            'note' => 'Nothing here is on the map yet',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/join/IT');
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT * FROM country_interest WHERE user_id = ?', [$u->getId()]);
        self::assertIsArray($row);
        self::assertSame('Alto Adige', $row['region_name'], 'the area is stored, not swallowed');
        self::assertSame('IT', $row['country_code']);
    }

    public function testTwoAreasInOneCountryAreTwoSignalsAndOneAreaTwiceIsOne(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('IT', 'italy-two-test');
        $u = $this->user('join-it-two@example.test');
        $service = self::getContainer()->get(CountryInterestService::class);

        $service->record($u, 'IT', false, '', 'Alto Adige');
        $service->record($u, 'IT', false, '', 'Piemonte');
        $service->record($u, 'IT', false, 'asked again', 'Alto Adige');

        $counts = $service->regionCounts('IT');
        self::assertCount(2, $counts, 'two areas, however many times each was asked for');
        self::assertSame(1, $counts[0]['total'], 'a person asking twice is still one person');
    }

    public function testAskingForTheWholeCountryIsNotCountedAsAnArea(): void
    {
        $client = static::createClient();
        $u = $this->user('join-whole@example.test');
        $service = self::getContainer()->get(CountryInterestService::class);

        $service->record($u, 'ES', false, '', '');

        self::assertSame([], $service->regionCounts('ES'),
            'the country total already carries it; listing it as an area would count it twice');
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
            'form' => 'curator-application',
            '_token' => $token, 'about' => 'I ride these roads every weekend',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/join/IT');
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT * FROM curator_application WHERE user_id = ?', [$u->getId()]);
        self::assertIsArray($row);
        self::assertSame('pending', $row['status']);
    }

    public function testAnApplicationWithoutAboutFailsCleanlyAndWritesNothing(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('IT', 'italy-join-test');
        $u = $this->user('join-it-empty@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/IT');
        $token = $crawler->filter('form[data-form="curator-application"] input[name="_token"]')->attr('value');

        $client->request('POST', '/join/IT', [
            'form' => 'curator-application',
            '_token' => $token,
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseIsSuccessful('a missing "about" is a clean page-level error, never a 500');
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT * FROM country_interest WHERE user_id = ?', [$u->getId()]);
        self::assertFalse($row, 'a failed application is not quietly downgraded to an area request');
    }

    /**
     * The branch a crafted payload can still reach, and why it is harmless.
     *
     * The payload names the form, and the CSRF token cannot narrow that: both
     * ids are stateless (config/packages/csrf.yaml), so one token satisfies
     * either. That is safe only while both branches are things the same
     * signed-in rider may do on this page anyway, which is the state of it
     * since an onboarded country began offering both. The branch that is NOT
     * always allowed is an application for a country with no regions, and this
     * pins that it is refused by the service rather than by the routing above
     * it, because that is the assumption the paragraph above rests on.
     */
    /**
     * Typing an area has to find something.
     *
     * The heading invites a rider to name their region, so a box that knows
     * only country names answers "Ohio" with silence and the invitation is a
     * lie (owner 2026-09-13). The areas ship with the page rather than through
     * a lookup, because the route is shared-cached and a request per keystroke
     * is not.
     */
    public function testTheRegionsPageCarriesTheAreasInsideOnboardedCountries(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('US', 'us-areas-test');
        $this->seedDivision('US', 'US-OH', 'Ohio');

        $html = $client->request('GET', '/regions')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('cc-areas', $html, 'the area list ships with the page');
        self::assertStringContainsString('Ohio', $html, 'and it carries subdivisions, not just countries');
    }

    public function testArrivingWithAnAreaOpensTheFormWithItAlreadyFilledIn(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('US', 'us-prefill-test');
        $client->loginUser($this->user('join-prefill@example.test'), 'main');

        $crawler = $client->request('GET', '/join/US?area=Ohio');

        self::assertResponseIsSuccessful();
        self::assertSame('Ohio', $crawler->filter('input[name="region_name"]')->attr('value'),
            'a rider who typed it once is not asked to type it again');
    }

    /**
     * Somebody willing to run a place the Commons has not got.
     *
     * Owner 2026-09-13: "If there is a curator that is also a promoter we are
     * very willing to add it." The picker offered the country and the regions
     * already on the map, so a rider willing to run Ohio could only ask to run
     * the whole United States, and the strongest argument for adding Ohio
     * could not be made.
     */
    public function testACuratorCanVolunteerForAnAreaThatHasNoRegionRowYet(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('US', 'us-volunteer-test');
        $this->seedDivision('US', 'US-OH', 'Ohio');
        $u = $this->user('join-us-ohio@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/US');
        self::assertGreaterThan(0, $crawler->filter('select[name="region"] option[value="new:Ohio"]')->count(),
            'an area with no region row is offered to volunteer for');

        $token = $crawler->filter('form[data-form="curator-application"] input[name="_token"]')->attr('value');
        $client->request('POST', '/join/US', [
            'form' => 'curator-application',
            '_token' => $token,
            'region' => 'new:Ohio',
            'about' => 'I have ridden every road in the state',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/join/US');
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT * FROM curator_application WHERE user_id = ?', [$u->getId()]);
        self::assertIsArray($row);
        self::assertSame('Ohio', $row['requested_area'], 'the area is stored as a name');
        self::assertNull($row['requested_region_id'], 'and never as an id, because no such row exists');
    }

    /**
     * A curator's scope cannot be typed, only chosen.
     *
     * A scope draws a line on the map, and two lines drawn from whatever
     * somebody typed will sooner or later cross: one applicant asks for a
     * province, another for a town inside it, and two curators hold the same
     * ground with nothing to say who decides (owner 2026-09-13). Every country
     * is seeded at one operating level for that reason, and a scope arriving
     * by name has to respect it, whatever the payload says.
     */
    public function testAScopeNobodyHoldsABoundaryForIsRefused(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('US', 'us-overlap-test');
        $this->seedDivision('US', 'US-OH', 'Ohio');
        $u = $this->user('join-us-overlap@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/US');
        $token = $crawler->filter('form[data-form="curator-application"] input[name="_token"]')->attr('value');

        $client->request('POST', '/join/US', [
            'form' => 'curator-application',
            '_token' => $token,
            'region' => 'new:The bit of Ohio I like',
            'about' => 'I would draw my own line, thanks',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseIsSuccessful('a scope we cannot place is a page-level error, never a 500');
        self::assertFalse(
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()
                ->fetchAssociative('SELECT * FROM curator_application WHERE user_id = ?', [$u->getId()]),
            'and it is not quietly widened to the whole country either',
        );
    }

    public function testTheStoredScopeIsOurSpellingNotTheApplicantsTyping(): void
    {
        $client = static::createClient();
        $this->seedCountryRegion('US', 'us-spelling-test');
        $this->seedDivision('US', 'US-OH', 'Ohio');
        $u = $this->user('join-us-spelling@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/US');
        $token = $crawler->filter('form[data-form="curator-application"] input[name="_token"]')->attr('value');

        $client->request('POST', '/join/US', [
            'form' => 'curator-application',
            '_token' => $token,
            'region' => 'new:OHIO',
            'about' => 'Shouting the name should not make a second Ohio',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT * FROM curator_application WHERE user_id = ?', [$u->getId()]);
        self::assertIsArray($row);
        self::assertSame('Ohio', $row['requested_area'], 'one place, one spelling on the desk');
    }

    /**
     * An onboarded region under its local name is still onboarded.
     *
     * Region rows are named in English and the reference data in the
     * country's own language, so matching by name offered 61 live regions
     * again as "not on the Commons yet" (measured 2026-09-14). The ISO code is
     * what they share, and a curator applying for Bayern while Bavaria is live
     * would be two people holding the same ground.
     */
    public function testAnOnboardedRegionIsNotOfferedAgainUnderItsLocalName(): void
    {
        $client = static::createClient();
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->seedCountryRegion('DE', 'de-local-name-test');
        $this->seedDivision('DE', 'DE-BY', 'Bayern', 70550.0);
        $this->seedDivision('DE', 'DE-HE', 'Hessen', 21115.0);
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('bavaria-local-name-test', 'Bavaria', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 70000, 'DE', 'DE-BY', 4, 'test', NOW(), NOW())",
        );
        $u = $this->user('join-de-bayern@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/DE');
        self::assertSame(1, $crawler->filter('select[name="region"] option[value="new:Hessen"]')->count(),
            'a held division that is not on the map is offered');
        self::assertSame(0, $crawler->filter('select[name="region"] option[value="new:Bayern"]')->count(),
            'Bayern is Bavaria, which is already on the map');

        $token = $crawler->filter('form[data-form="curator-application"] input[name="_token"]')->attr('value');
        $client->request('POST', '/join/DE', [
            'form' => 'curator-application',
            '_token' => $token,
            'region' => 'new:Bayern',
            'about' => 'Posting the local name by hand',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertFalse(
            $db->fetchAssociative('SELECT * FROM curator_application WHERE user_id = ?', [$u->getId()]),
            'and a crafted post naming it is refused, not stored as a second Bavaria',
        );
    }

    public function testAnAreaAlreadyOnTheMapIsNotOfferedTwice(): void
    {
        $client = static::createClient();
        // The seeded region is named "United States", so the country-level row
        // must not also appear under "not on the Commons yet".
        $this->seedCountryRegion('US', 'us-dupe-test');
        $client->loginUser($this->user('join-us-dupe@example.test'), 'main');

        $crawler = $client->request('GET', '/join/US');

        self::assertSame(0, $crawler->filter('select[name="region"] option[value="new:United States"]')->count());
    }

    public function testAnApplicationForACountryWithNoRegionsIsRefused(): void
    {
        $client = static::createClient();
        $u = $this->user('join-craft-app@example.test');
        $client->loginUser($u, 'main');

        $crawler = $client->request('GET', '/join/ES');
        $token = $crawler->filter('form[data-form="country-interest"] input[name="_token"]')->attr('value');

        $client->request('POST', '/join/ES', [
            'form' => 'curator-application',
            '_token' => $token,
            'about' => 'Let me curate a country nobody has onboarded',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertFalse(
            $db->fetchAssociative('SELECT * FROM curator_application WHERE user_id = ?', [$u->getId()]),
            'no application is stored for a country that has no regions to curate',
        );
        self::assertFalse(
            $db->fetchAssociative('SELECT * FROM country_interest WHERE user_id = ?', [$u->getId()]),
            'and it is not quietly turned into an interest row either',
        );
    }
}
