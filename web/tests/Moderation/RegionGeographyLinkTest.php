<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\Links\SafeBrowsing;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Contribution\PlaceTextProposals;
use App\Contribution\PlaceTextRefused;
use App\Entity\User;
use App\Links\WebsiteChecker;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationService;
use App\Moderation\TextLinksUnconfirmedException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A region's geography text cites its sources in place, as its history text
 * does: `[words](https://address)` links the words, the cap counts what a
 * reader sees, and each link is checked and, when not plainly fine,
 * confirmed by the writer and the approving curator
 * (moderation-and-contribution.md §3.1b).
 *
 * Two regions far from any real one, without a Wikipedia article, so the
 * "Where your text comes from" question is not asked. DAMA wraps each test
 * in a rolled-back transaction.
 */
final class RegionGeographyLinkTest extends WebTestCase
{
    private const string LINKED = 'Pgeo A lies on a sand ridge kept by [Staatsbosbeheer](https://www.staatsbosbeheer.nl).';

    public function testTheGeographyStepHasTheLinkEditorWithItsOwnTick(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->twoRegions();
        $client->loginUser($this->user('pgeo-rider1@example.com', []));

        $crawler = $client->request('GET', '/regions/pgeo-a/text?lang=en');
        self::assertResponseIsSuccessful();
        // The geography box: the link editor, hidden until its script runs.
        self::assertCount(1, $crawler->filter('#pt-step-geography #pt-text-linkbar[hidden] #pt-text-link-open'));
        self::assertCount(1, $crawler->filter('#pt-step-geography .pt-hl-wrap > textarea#pt-text[data-links][data-one-line]'));
        self::assertCount(1, $crawler->filter('#pt-step-geography #pt-text-links[hidden] ol#pt-text-links-list'));
        self::assertSame('/regions/links/check', $crawler->filter('#pt-text-links')->attr('data-check-url'));
        self::assertSame('text_links_confirmed', $crawler->filter('#pt-text-links-confirm')->attr('name'), 'its own tick');
        self::assertSame('3000', $crawler->filter('textarea#pt-text')->attr('maxlength'), 'the box holds the addresses too');
        self::assertSame('1200', $crawler->filter('#pt-text-count')->attr('data-max'), 'the count is of what a reader sees');
        self::assertSame('Write the whole text as readers should see it, not only the change. Leave the box empty to keep the current text.', $crawler->filter('p#pt-text-hint')->text());
        // Its guide says how to link, with the example in the box's tints.
        $guide = $crawler->filter('#pt-step-geography aside.pt-howto li');
        self::assertSame('One paragraph, at most 1,200 characters. Link addresses do not count.', $guide->first()->text());
        self::assertCount(1, $crawler->filter('#pt-step-geography .pt-howto-ex'));
        // The history box keeps its own editor and tick.
        self::assertCount(1, $crawler->filter('#pt-step-story #pt-story-linkbar #pt-story-link-open'));
        self::assertSame('links_confirmed', $crawler->filter('#pt-story-links-confirm')->attr('name'));
        self::assertCount(1, $crawler->filter('textarea#pt-story:not([data-one-line])'));
    }

    public function testTheRegionPageShowsTheGeographyLinks(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement('UPDATE region SET context_curated = CAST(:c AS jsonb) WHERE id = :id', [
            'c' => json_encode(['en' => ['text' => self::LINKED, 'derived' => false, 'userId' => null, 'approvedBy' => null, 'submissionId' => null, 'at' => '2026-10-24T00:00:00+00:00']], \JSON_THROW_ON_ERROR),
            'id' => $a->getId(),
        ]);

        $crawler = $client->request('GET', '/regions/pgeo-a');
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('.rg-about:not(.rg-story) .rg-about-text');
        self::assertSame('Pgeo A lies on a sand ridge kept by Staatsbosbeheer.', $text->text());
        $cited = $text->filter('a');
        self::assertSame('Staatsbosbeheer', $cited->text(), 'the words that describe the source are the link');
        self::assertSame('https://www.staatsbosbeheer.nl', $cited->attr('href'));
        self::assertSame('_blank', $cited->attr('target'));
        self::assertSame('noopener noreferrer', $cited->attr('rel'));
    }

    public function testTheCapCountsWhatAReaderSeesAndALinkMustNameItsSource(): void
    {
        static::createClient();
        [$a] = $this->twoRegions();
        $this->linksAllFine();
        $rider = $this->user('pgeo-rider3@example.com', []);
        $region = $this->proposals()->region('pgeo-a');
        self::assertNotNull($region);
        unset($a);

        // 1,200 characters a reader sees, with an address that would push it past the cap.
        $words = str_repeat('x', 1200 - \strlen('Kept by ') - \strlen('Staatsbosbeheer'));
        $long = $words.'Kept by [Staatsbosbeheer](https://www.staatsbosbeheer.nl/'.str_repeat('p', 200).')';
        $sub = $this->proposals()->proposeRegion($rider, $region, 'en', $long, '', null)['submission'];
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus(), 'link addresses do not count');
        self::assertArrayNotHasKey('_links', $sub->getPayload(), 'every link checked out: no tick asked');

        foreach ([
            'A ridge, see [here](https://www.staatsbosbeheer.nl).' => 'place_text.error.generic_anchor',
            'A ridge kept by [Staatsbosbeheer](http://www.staatsbosbeheer.nl).' => 'place_text.error.http_link',
            'x'.$words.'Kept by [Staatsbosbeheer](https://www.staatsbosbeheer.nl).' => 'place_text.error.too_long',
        ] as $text => $error) {
            try {
                $this->proposals()->proposeRegion($rider, $region, 'en', $text, '', null);
                self::fail('sent back: '.$error);
            } catch (PlaceTextRefused $e) {
                self::assertSame($error, $e->getMessage());
            }
        }
    }

    public function testALinkThatIsNotPlainlyFineNeedsTheWritersAndTheCuratorsTick(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $web = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_contains($url, 'dead')
            ? new MockResponse('', ['error' => 'Could not resolve host: dead.test'])
            : new MockResponse('<title>Archive</title>', ['http_code' => 200]));
        static::getContainer()->set(WebsiteChecker::class, new WebsiteChecker($web, new NullLogger()));
        static::getContainer()->set(SafeBrowsing::class, new SafeBrowsing(new MockHttpClient(new MockResponse('{}')), new NullLogger(), 'test-key'));
        $rider = $this->user('pgeo-rider4@example.com', []);
        $curatorA = $this->user('pgeo-cur-a4@example.com', ['ROLE_CURATOR'], $a);
        $region = $this->proposals()->region('pgeo-a');
        self::assertNotNull($region);

        $text = 'Pgeo A lies behind [the old dike](https://dead.test/dike).';
        try {
            $this->proposals()->proposeRegion($rider, $region, 'en', $text, '', null);
            self::fail('a link that is not plainly fine needs the writer\'s tick');
        } catch (PlaceTextRefused $e) {
            self::assertSame('place_text.error.links_unconfirmed', $e->getMessage());
            self::assertSame(['%urls%' => 'https://dead.test/dike'], $e->params);
        }

        // The form sends it back at the geography step, its own tick asked.
        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/pgeo-a/text?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => $text]));
        self::assertResponseStatusCodeSame(422);
        $crawler = $client->getCrawler();
        self::assertSame('geography', $crawler->filter('input[name=step]')->attr('value'));
        self::assertCount(1, $crawler->filter('#pt-text-links-ok.is-asked:not([hidden])'), 'the geography tick is asked');
        self::assertCount(1, $crawler->filter('#pt-story-links-ok[hidden]'), 'the history tick is not');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => $text, 'text_links_confirmed' => '1']));
        self::assertResponseIsSuccessful();

        $sub = $this->latestText((int) $rider->getId());
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus());
        self::assertSame(['https://dead.test/dike' => 'dead'], $sub->getPayload()['_links']['writer']['problems']);

        // The curator approves only with a tick of their own: not from the drawer, not without it on the form.
        try {
            $this->moderation()->decide((int) $sub->getId(), 'approve', $curatorA, null);
            self::fail('the drawer\'s approve must send the curator to the form');
        } catch (TextLinksUnconfirmedException) {
            self::assertTrue(static::getContainer()->get(EntityManagerInterface::class)->isOpen());
        }
        try {
            $this->proposals()->approve($this->fresh($sub), $curatorA, $text, null, '');
            self::fail('the form\'s approve needs the curator\'s tick');
        } catch (PlaceTextRefused $e) {
            self::assertSame('place_text.error.links_unconfirmed', $e->getMessage());
        }
        // The correction form carries the same editor.
        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#pt-text-linkbar #pt-text-link-open'));
        self::assertCount(1, $crawler->filter('textarea#pt-text[data-links][data-one-line]'));
        self::assertCount(0, $crawler->filter('aside#pt-text-hint li')->reduce(static fn ($li): bool => str_contains($li->text(), 'paragraph on a new line')), 'one paragraph: no paragraph step');

        $this->proposals()->approve($this->fresh($sub), $curatorA, $text, null, '', true);
        $after = $this->fresh($sub);
        self::assertSame(SubmissionStatus::Approved, $after->getStatus());
        self::assertSame($curatorA->getId(), $after->getPayload()['_links']['approved']['by']);
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        /** @var array<string, array<string, mixed>> $curated */
        $curated = json_decode((string) $db->fetchOne('SELECT context_curated FROM region WHERE id = :id', ['id' => $a->getId()]), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($text, $curated['en']['text']);
    }

    /** Every site answers and Safe Browsing names none. */
    private function linksAllFine(): void
    {
        static::getContainer()->set(WebsiteChecker::class, new WebsiteChecker(new MockHttpClient(static fn (): MockResponse => new MockResponse('<title>Archive</title>', ['http_code' => 200])), new NullLogger()));
        static::getContainer()->set(SafeBrowsing::class, new SafeBrowsing(new MockHttpClient(static fn (): MockResponse => new MockResponse('{}')), new NullLogger(), 'test-key'));
    }

    /** @return array{0: Region, 1: Region} */
    private function twoRegions(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = (new Region())->setSlug('pgeo-a')->setName('Pgeo A')->setCountryCode('PN')->setAreaKm2(1.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-141,-41],[-140,-41],[-140,-40],[-141,-40],[-141,-41]]]]}');
        $b = (new Region())->setSlug('pgeo-b')->setName('Pgeo B')->setCountryCode('CK')->setAreaKm2(1.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-121,-41],[-120,-41],[-120,-40],[-121,-40],[-121,-41]]]]}');
        $em->persist($a);
        $em->persist($b);
        $em->flush();

        return [$a, $b];
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
