<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CountryInterestService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * 2026-07-29-country-requests-and-curator-signup-design.md §5.1.
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
}
