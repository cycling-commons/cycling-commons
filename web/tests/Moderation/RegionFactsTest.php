<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\RegionFacts;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Contribution\PlaceText;
use App\Contribution\PlaceTextProposals;
use App\Contribution\PlaceTextRefused;
use App\Contribution\SubmissionChangeSummary;
use App\Entity\User;
use App\Moderation\ApprovalRevoker;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\RevokeRefused;
use App\Moderation\SubmissionQueue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A region's figures at the top of its page: area, population with the
 * year it was counted, and ride character. Anyone signed in proposes them
 * on the geography form, beside its text or alone; a curator of the region
 * approves them through the one Text queue (moderation-and-contribution.md
 * §3.1g). Also: a history text links https addresses only (§3.1c).
 *
 * Two regions far from any real one. DAMA wraps each test in a rolled-back
 * transaction.
 */
final class RegionFactsTest extends WebTestCase
{
    public function testTheHeroShowsTheMeasuredAreaUntilFiguresAreSet(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();

        $crawler = $client->request('GET', '/regions/pfacts-a');
        self::assertResponseIsSuccessful();
        $land = $crawler->filter('.rhero .rstats-land .s');
        self::assertCount(1, $land, 'only the area, measured from the boundary');
        self::assertSame('Area', $land->filter('span')->text());
        self::assertStringContainsString('2,680', $land->filter('b')->text());
        self::assertCount(0, $crawler->filter('.rhero .rstats:not(.rstats-land) .s span:contains("Area")'), 'the area sits with the land, not the counts');

        $this->setFacts($a, ['area' => ['value' => 2633, 'submissionId' => null, 'at' => '2026-10-23T00:00:00+00:00'], 'population' => ['value' => 494000, 'year' => 2024, 'submissionId' => null, 'at' => '2026-10-23T00:00:00+00:00'], 'ride' => ['value' => 'flat', 'submissionId' => null, 'at' => '2026-10-23T00:00:00+00:00']]);
        $crawler = $client->request('GET', '/regions/pfacts-a');
        self::assertSame(['Area', 'Population (2024)', 'Ride character'], $crawler->filter('.rhero .rstats-land .s span')->each(static fn ($s): string => $s->text()));
        $values = $crawler->filter('.rhero .rstats-land .s b')->each(static fn ($b): string => $b->text());
        self::assertStringContainsString('2,633', $values[0], 'a stated area over the measured one');
        self::assertSame(['494,000', 'Flat'], [$values[1], $values[2]]);

        $crawler = $client->request('GET', '/nl/regios/pfacts-a');
        self::assertSame(['Oppervlakte', 'Inwoners (2024)', 'Terrein'], $crawler->filter('.rhero .rstats-land .s span')->each(static fn ($s): string => $s->text()));
        self::assertSame('Vlak', $crawler->filter('.rhero .rstats-land .s b')->last()->text());
    }

    public function testAnAutomaticTextIsAboutTheRegionAndAsksForAGeographyText(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('UPDATE region SET context = :c WHERE id = :id', ['c' => '{"en": {"title": "Pfacts A", "extract": "Pfacts A is a province with a long history.", "url": "https://en.wikipedia.org/wiki/Pfacts_A"}}', 'id' => $a->getId()]);

        // The Wikipedia summary is not always geography: it is about the
        // region, and a Geography section of its own follows it.
        $crawler = $client->request('GET', '/regions/pfacts-a');
        self::assertSame(['About Pfacts A', 'Geography', 'History, nature and culture'], $crawler->filter('.rbody-main > .rg-about > .kicker')->each(static fn ($k): string => $k->text()));
        $geo = $crawler->filter('.rg-about.rg-geo');
        self::assertSame('Write about the geography of this region', $geo->filter('.rg-about-write a')->text(), 'the same plain link as an empty history text');
        self::assertSame('/regions/pfacts-a/text', $geo->filter('.rg-about-write a')->attr('href'));
        // Under all the texts, quietly: an invitation to make it a page worth riding to.
        $invite = $crawler->filter('.rbody-main > aside.rg-invite');
        self::assertSame('Do you know Pfacts A?', $invite->filter('.rg-invite-h')->text());
        self::assertSame('Write about Pfacts A', $invite->filter('a')->text());
        self::assertCount(0, $invite->filter('.btn'), 'a plain link, no button');

        // A text written here is the geography text, and asks for nothing.
        $db->executeStatement('UPDATE region SET context_curated = :c WHERE id = :id', ['c' => '{"en": {"text": "Pfacts A lies on the sea.", "derived": false, "userId": null, "approvedBy": null, "submissionId": null, "at": "2026-10-23T00:00:00+00:00"}}', 'id' => $a->getId()]);
        $crawler = $client->request('GET', '/regions/pfacts-a');
        self::assertSame(['Geography', 'History, nature and culture'], $crawler->filter('.rbody-main > .rg-about > .kicker')->each(static fn ($k): string => $k->text()));
        self::assertCount(0, $crawler->filter('.rg-about:not(.rg-story) .rg-about-write'));
        self::assertCount(0, $crawler->filter('.rg-invite'));
    }

    public function testARidersFiguresWaitForACuratorThenShowAndARevokeTakesThemBack(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a, $b] = $this->twoRegions();
        $rider = $this->user('pfacts-rider1@example.com', []);
        $curatorA = $this->user('pfacts-cur-a1@example.com', ['ROLE_CURATOR'], $a);
        $curatorB = $this->user('pfacts-cur-b1@example.com', ['ROLE_CURATOR'], $b);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/pfacts-a/edit?lang=en');
        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('#pt-tab-figures')->attr('aria-selected'), 'the editor opens at its figures');
        self::assertSame('1 Figures', $crawler->filter('#pt-step-figures .pt-step-h')->text());
        // Each step's lead stands above the first field, shown for the open step by the script.
        self::assertSame(['figures', 'geography', 'story'], $crawler->filter('.pt-step-lead[hidden]')->each(static fn ($p): string => (string) $p->attr('data-step-for')));
        self::assertSame('geography story', $crawler->filter('.pt-lang-field')->attr('data-step-for'), 'the language is for the texts only');
        // Beside each step, how to fill it in, and the wiki guide.
        self::assertSame([
            'https://wiki.cyclingcommons.org/region-texts/#the-regions-figures',
            'https://wiki.cyclingcommons.org/region-texts/#the-geography-text',
            'https://wiki.cyclingcommons.org/region-texts/#the-history-nature-and-culture-text',
        ], $crawler->filter('.pt-guide-more a')->each(static fn ($a): string => (string) $a->attr('href')));
        self::assertSame('Original: the base text every translation starts from', $crawler->filter('#pt-based-on option[value=""]')->text());
        self::assertSame('Measured from the region\'s boundary: 2,680 km².', $crawler->filter('#pt-fact-area-hint')->text());
        self::assertSame('', $crawler->filter('#pt-fact-area')->attr('value'), 'no stated area yet');
        self::assertSame(['Flat', 'Hilly', 'Mountainous'], $crawler->filter('.pt-fact-ride label')->each(static fn ($l): string => $l->text()));
        self::assertNull($crawler->filter('textarea#pt-text')->attr('required'), 'a send may change only the figures');
        self::assertNull($crawler->filter('#pt-source-own')->attr('required'), 'the source is asked of a text only');
        self::assertStringNotContainsString('size', $crawler->filter('#pt-step-geography .pt-step-p')->text(), 'the area is a figure, not part of the text');

        $client->submit($crawler->filter('#place-text-form')->form([
            'lang' => 'en', 'text' => '', 'note' => 'From the province.',
            'facts[area]' => '2,633', 'facts[population]' => '494 000', 'facts[year]' => '2024', 'facts[ride]' => 'flat',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.csent h2', 'Sent for review');
        self::assertSame(1, $client->getCrawler()->filter('.pt-sent-list li')->count(), 'one part sent');
        self::assertSelectorTextContains('.pt-sent-list li', 'Figures');
        self::assertSelectorTextContains('.pt-sent-list li', 'waiting for a curator');

        $sub = $this->latestText((int) $rider->getId());
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus());
        self::assertSame($a->getId(), $sub->getRegionId());
        self::assertSame(PlaceText::FACTS, $sub->getPayload()['target']);
        self::assertEquals(['area' => 2633, 'population' => ['value' => 494000, 'year' => 2024], 'ride' => 'flat'], $sub->getPayload()['facts'], 'JSONB keeps its own key order');
        self::assertSame('From the province.', $sub->getPayload()['details']['note']);
        self::assertNull(PlaceText::fromPayload($sub->getPayload()), 'no text in it');
        $rows = static::getContainer()->get(SubmissionChangeSummary::class)->rows($sub);
        self::assertSame([
            ['label' => 'Area', 'was' => null, 'now' => '2,633 km²'],
            ['label' => 'Population', 'was' => null, 'now' => '494,000 (2024)'],
            ['label' => 'Ride character', 'was' => null, 'now' => 'Flat'],
        ], $rows);
        self::assertContains((int) $sub->getId(), $this->queueIds($curatorA));
        self::assertNotContains((int) $sub->getId(), $this->queueIds($curatorB));
        self::assertNull(RegionFacts::current($this->stored($a))['population'], 'nothing on the page before the decision');

        // The drawer approves it as sent: no form, no Wikipedia question.
        $this->moderation()->decide((int) $sub->getId(), 'approve', $curatorA, null);
        $now = RegionFacts::current($this->stored($a));
        self::assertSame(['area' => 2633, 'population' => 494000, 'year' => 2024, 'ride' => 'flat', 'populationQid' => null], $now);
        self::assertSame($sub->getId(), $this->stored($a)['area']['submissionId']);
        self::assertArrayNotHasKey('_credit', $this->fresh($sub)->getPayload(), 'no credit to decide');

        // A revoke puts back what stood: nothing.
        static::getContainer()->get(ApprovalRevoker::class)->revoke((int) $sub->getId(), $this->reload($curatorA), 'Wrong province.');
        self::assertSame(['area' => null, 'population' => null, 'year' => null, 'ride' => null, 'populationQid' => null], RegionFacts::current($this->stored($a)));
    }

    public function testAFetchedPopulationShowsUntilAPersonSetsOne(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $rider = $this->user('pfacts-rider5@example.com', []);
        $curator = $this->user('pfacts-cur-a5@example.com', ['ROLE_CURATOR'], $a);
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('UPDATE region SET facts_harvest = :h WHERE id = :id', ['h' => '{"population": {"value": 491792, "year": 2017, "qid": "Q772"}}', 'id' => $a->getId()]);

        $crawler = $client->request('GET', '/regions/pfacts-a');
        self::assertSame(['Area', 'Population (2017)'], $crawler->filter('.rhero .rstats-land .s span')->each(static fn ($s): string => $s->text()));
        self::assertSame('491,792', $crawler->filter('.rhero .rstats-land .s b')->last()->text());

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/pfacts-a/edit');
        self::assertSame('491792', $crawler->filter('#pt-fact-population')->attr('value'), 'the fetched count, as it stands');
        self::assertSame('2017', $crawler->filter('#pt-fact-year')->attr('value'));
        self::assertSame('Source: Wikidata Q772', $crawler->filter('.pt-fact-src')->text());
        self::assertSame('https://www.wikidata.org/wiki/Q772', $crawler->filter('.pt-fact-src a')->attr('href'));
        self::assertSame([], $this->proposals()->factChanges($this->region('pfacts-a'), ['population' => '491,792', 'year' => '2017']), 'left as it is, it changes nothing');

        // A person's newer count shows over the fetched one, and a revoke brings the fetched one back.
        $region = $this->region('pfacts-a');
        $sub = $this->proposals()->proposeFacts($rider, $region, 'en', $this->proposals()->factChanges($region, ['population' => '502051', 'year' => '2024']), '')['submission'];
        self::assertEquals(['fact:population' => ['was' => ['value' => 491792, 'year' => 2017], 'now' => ['value' => 502051, 'year' => 2024]]], $sub->getChanges());
        $this->moderation()->decide((int) $sub->getId(), 'approve', $curator, null);
        $crawler = $client->request('GET', '/regions/pfacts-a');
        self::assertSame('Population (2024)', $crawler->filter('.rhero .rstats-land .s span')->last()->text());
        $crawler = $client->request('GET', '/regions/pfacts-a/edit');
        self::assertCount(0, $crawler->filter('.pt-fact-src'), 'a person\'s count names no source');

        static::getContainer()->get(ApprovalRevoker::class)->revoke((int) $sub->getId(), $this->reload($curator), 'Wrong year.');
        self::assertNull($this->stored($a), 'nothing a person set');
        $crawler = $client->request('GET', '/regions/pfacts-a');
        self::assertSame('Population (2017)', $crawler->filter('.rhero .rstats-land .s span')->last()->text());
    }

    public function testARevokeIsRefusedOnceAFigureWasSetSince(): void
    {
        static::createClient();
        [$a] = $this->twoRegions();
        $rider = $this->user('pfacts-rider2@example.com', []);
        $curator = $this->user('pfacts-cur-a2@example.com', ['ROLE_CURATOR'], $a);
        $region = $this->region('pfacts-a');

        $first = $this->proposals()->proposeFacts($rider, $region, 'en', $this->proposals()->factChanges($region, ['ride' => 'hilly']), '')['submission'];
        $this->moderation()->decide((int) $first->getId(), 'approve', $curator, null);
        // The curator's own figures apply at once, over the rider's.
        $region = $this->region('pfacts-a');
        $own = $this->proposals()->proposeFacts($curator, $region, 'en', $this->proposals()->factChanges($region, ['ride' => 'mountainous']), '');
        self::assertTrue($own['applied']);
        self::assertSame('mountainous', RegionFacts::current($this->stored($a))['ride']);

        $this->expectException(RevokeRefused::class);
        static::getContainer()->get(ApprovalRevoker::class)->revoke((int) $first->getId(), $this->reload($curator), 'Mistake.');
    }

    public function testTheFormChecksTheFigures(): void
    {
        static::createClient();
        $this->twoRegions();
        $region = $this->region('pfacts-a');
        $p = $this->proposals();

        self::assertSame([], $p->factChanges($region, ['area' => '2680', 'population' => '', 'year' => '', 'ride' => '']), 'the measured area, as it is, changes nothing');
        self::assertSame(['area' => ['was' => null, 'now' => 2633]], $p->factChanges($region, ['area' => '2.633']));
        foreach ([
            [['area' => '2680.5'], 'place_text.error.facts_area'],
            [['area' => '0'], 'place_text.error.facts_area'],
            [['population' => '494000'], 'place_text.error.facts_pair'],
            [['year' => '2024'], 'place_text.error.facts_pair'],
            [['population' => 'many', 'year' => '2024'], 'place_text.error.facts_population'],
            [['population' => '494000', 'year' => '1850'], 'place_text.error.facts_year'],
            [['ride' => 'steep'], 'place_text.error.facts_ride'],
        ] as [$raw, $error]) {
            try {
                $p->factChanges($region, $raw);
                self::fail('refused: '.json_encode($raw));
            } catch (PlaceTextRefused $e) {
                self::assertSame($error, $e->getMessage(), json_encode($raw) ?: '');
            }
        }
    }

    public function testATextAndFiguresSentTogetherAreTwoProposalsAndASecondSendAddsToTheFirst(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->twoRegions();
        $rider = $this->user('pfacts-rider3@example.com', []);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/pfacts-b/text?lang=en');
        self::assertSame('true', $crawler->filter('#pt-tab-geography')->attr('aria-selected'), 'the geography pencil opens its step');
        $client->submit($crawler->filter('#place-text-form')->form([
            'lang' => 'en', 'text' => 'Low land by the sea.', 'facts[ride]' => 'flat',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame(['Figures', 'Geography'], $client->getCrawler()->filter('.pt-sent-list li b')->each(static fn ($b): string => $b->text()), 'the text and the figures each have a reference');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $mine = $em->getRepository(Submission::class)->findBy(['userId' => $rider->getId(), 'type' => SubmissionType::Text], ['id' => 'ASC']);
        self::assertSame([PlaceText::FACTS, PlaceText::REGION], array_map(static fn (Submission $s): mixed => $s->getPayload()['target'], $mine));

        // Nothing to send: no text and no figure changed.
        $crawler = $client->request('GET', '/regions/pfacts-b/text?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.cerr', 'Change a figure or one of the texts before you send.');

        // A refused part files nothing, and the page opens at its step.
        $crawler = $client->request('GET', '/regions/pfacts-b/edit?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'facts[population]' => '900', 'facts[year]' => '2023', 'story' => 'The [old ferry](http://ferry.test/) still runs.']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $client->getCrawler()->filter('#pt-tab-story')->attr('aria-selected'));
        self::assertSelectorTextContains('.cerr', 'Link only https addresses');
        $em->clear();
        self::assertCount(2, $em->getRepository(Submission::class)->findBy(['userId' => $rider->getId(), 'type' => SubmissionType::Text]), 'not even the figures were filed');

        // A second send while the first waits adds to it.
        $crawler = $client->request('GET', '/regions/pfacts-b/text?lang=nl');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'nl', 'text' => '', 'facts[population]' => '1200', 'facts[year]' => '2023']));
        self::assertResponseIsSuccessful();
        $em->clear();
        $facts = $em->getRepository(Submission::class)->findBy(['userId' => $rider->getId(), 'type' => SubmissionType::Text], ['id' => 'ASC']);
        self::assertCount(2, $facts, 'amended, not filed again');
        self::assertEquals(['population' => ['value' => 1200, 'year' => 2023], 'ride' => 'flat'], $facts[0]->getPayload()['facts'], 'filed in step order: the figures first');
    }

    public function testAHistoryTextLinksHttpsAddressesOnly(): void
    {
        static::createClient();
        $this->twoRegions();
        $rider = $this->user('pfacts-rider4@example.com', []);

        try {
            $this->proposals()->proposeStory($rider, $this->region('pfacts-a'), 'en', 'The [Hunebedcentrum](http://www.hunebedcentrum.nl/) tells their story.', '');
            self::fail('an http link is refused');
        } catch (PlaceTextRefused $e) {
            self::assertSame('place_text.error.http_link', $e->getMessage());
            self::assertSame(['%urls%' => 'http://www.hunebedcentrum.nl/'], $e->params);
        }
    }

    /** @return array{0: Region, 1: Region} */
    private function twoRegions(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = (new Region())->setSlug('pfacts-a')->setName('Pfacts A')->setCountryCode('PN')->setAreaKm2(2680.4)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-141,-41],[-140,-41],[-140,-40],[-141,-40],[-141,-41]]]]}');
        $b = (new Region())->setSlug('pfacts-b')->setName('Pfacts B')->setCountryCode('CK')->setAreaKm2(1.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-121,-41],[-120,-41],[-120,-40],[-121,-40],[-121,-41]]]]}');
        $em->persist($a);
        $em->persist($b);
        $em->flush();

        return [$a, $b];
    }

    /** @param array<string, mixed> $facts */
    private function setFacts(Region $region, array $facts): void
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('UPDATE region SET facts = :f WHERE id = :id', ['f' => json_encode($facts, \JSON_THROW_ON_ERROR), 'id' => $region->getId()]);
    }

    /** @return array<string, mixed>|null */
    private function stored(Region $region): ?array
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        return PlaceText::decode($db->fetchOne('SELECT facts FROM region WHERE id = :id', ['id' => $region->getId()]));
    }

    /** @return array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} */
    private function region(string $slug): array
    {
        $region = $this->proposals()->region($slug);
        self::assertNotNull($region);

        return $region;
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles, ?Region $area = null): User
    {
        $c = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        if (null !== $area) {
            $em->persist(new ModeratorArea((int) $user->getId(), (int) $area->getId(), null));
            $em->flush();
        }

        return $user;
    }

    /** The user again, managed: the helpers above clear the entity manager. */
    private function reload(User $user): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $found = $em->find(User::class, (int) $user->getId());
        self::assertInstanceOf(User::class, $found);

        return $found;
    }

    private function fresh(Submission $submission): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $sub = $em->find(Submission::class, (int) $submission->getId());
        self::assertInstanceOf(Submission::class, $sub);

        return $sub;
    }

    private function latestText(int $userId): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $sub = $em->getRepository(Submission::class)->findOneBy(['userId' => $userId, 'type' => SubmissionType::Text], ['id' => 'DESC']);
        self::assertInstanceOf(Submission::class, $sub, 'a Text submission was filed');

        return $sub;
    }

    /** @return list<int> */
    private function queueIds(User $curator): array
    {
        $c = static::getContainer();
        /** @var ModerationScopeProvider $scopes */
        $scopes = $c->get(ModerationScopeProvider::class);
        /** @var SubmissionQueue $queue */
        $queue = $c->get(SubmissionQueue::class);

        return array_map(static fn (array $r): int => $r['id'], $queue->pendingForMap($scopes->scopeFor($curator)));
    }

    private function moderation(): ModerationService
    {
        /** @var ModerationService $m */
        $m = static::getContainer()->get(ModerationService::class);

        return $m;
    }

    private function proposals(): PlaceTextProposals
    {
        /** @var PlaceTextProposals $p */
        $p = static::getContainer()->get(PlaceTextProposals::class);

        return $p;
    }
}
