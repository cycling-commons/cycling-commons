<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Moderation\ModerationService;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Similar places within 250 m" under the wizard's pin, and what the rider
 * leaves ticked being replaced on approval (catalog-data-model.md §5a).
 *
 * Built from the production case of 2026-09-27: a rider corrected an OSM
 * drinking-water point in Medemblik while a provider's record of the same
 * tap stood a few metres away, and a bakery 139 m off shares letter B.
 */
final class SimilarPlacesFlowTest extends WebTestCase
{
    use CoverageSchema;

    private const string TAP = 'node/434341';
    private const float LAT = 52.7702;
    private const float LNG = 5.1084;

    public function testTheWizardListsTheSameKindAndLeavesTheBakeryOut(): void
    {
        $client = static::createClient();
        $this->login($client, 'list');
        $provider = $this->seed();

        $client->request('GET', '/contribute/similar?type=water-food&lat='.self::LAT.'&lng='.self::LNG.'&ref='.self::TAP);
        self::assertResponseIsSuccessful();
        /** @var list<array{tick: string, ticked: bool, metres: int}> $places */
        $places = json_decode((string) $client->getResponse()->getContent(), true)['places'];
        $ticks = array_column($places, 'tick');

        self::assertContains('item:'.$provider, $ticks, "the provider's record of the tap is listed");
        self::assertNotContains(self::TAP, $ticks, 'the point being corrected is not similar to itself');
        self::assertNotContains('node/434342', $ticks, 'a bakery is letter B, not a tap');
        self::assertContains('node/434343', $ticks, 'a free OSM tap 120 m off is listed');
        $byTick = array_column($places, null, 'tick');
        self::assertTrue($byTick['item:'.$provider]['ticked'], 'a few metres away starts ticked');
        self::assertFalse($byTick['node/434343']['ticked'], 'farther than 50 m starts unticked');
    }

    public function testTheTickRidesWithTheSubmissionAndApprovalRetiresTheRecord(): void
    {
        $client = static::createClient();
        $this->login($client, 'flow');
        $provider = $this->seed();

        $crawler = $client->request('GET', '/improve?ref='.self::TAP.'&type=water-food&lat='.self::LAT.'&lng='.self::LNG);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#wz-similar'), 'the similar-places block is on the page');
        $client->submit($crawler->selectButton('Next →')->form([
            'improve[details][potable]' => 'Yes (public supply)',
            'improve[lat]' => (string) self::LAT,
            'improve[lng]' => (string) self::LNG,
            'improve[place]' => 'Medemblik',
            'improve[replaces]' => 'item:'.$provider.',bogus,item:x',
        ]));
        self::assertSelectorTextContains('.receipt .ref', 'SUB-');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $new = $em->getRepository(Item::class)->findOneBy(['sourceRef' => self::TAP]);
        self::assertInstanceOf(Item::class, $new);
        $submission = $em->getRepository(Submission::class)->findOneBy(['itemId' => $new->getId()]);
        self::assertInstanceOf(Submission::class, $submission);
        self::assertSame(['item:'.$provider], $submission->getPayload()['_replaces'], 'cleaned on the way in');

        $curator = (new User())->setEmail('curator-similar@example.com');
        $curator->setPassword('x');
        $em->persist($curator);
        $em->flush();
        static::getContainer()->get(ModerationService::class)->decide((int) $submission->getId(), 'approve', $curator, null);

        $em->clear();
        self::assertSame(ItemState::Retired, $em->find(Item::class, $provider)?->getState());
        self::assertSame(ItemState::Unverified, $em->find(Item::class, $new->getId())?->getState());
    }

    /** The tap as OSM has it, a bakery, a second tap, and a provider's record. @return int the record's id */
    private function seed(): int
    {
        $db = static::getContainer()->get(Connection::class);
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => self::TAP, 'letter' => 'B', 'name' => 'Watertappunt Kwikkel', 'lat' => self::LAT, 'lng' => self::LNG, 'country_code' => 'NL']);
        self::insertCoveragePoi($db, ['ref' => 'node/434342', 'letter' => 'B', 'name' => 'Bakkerij', 'lat' => self::LAT + 0.0009, 'lng' => self::LNG, 'tags' => ['shop' => 'bakery'], 'country_code' => 'NL']);
        self::insertCoveragePoi($db, ['ref' => 'node/434343', 'letter' => 'B', 'name' => null, 'lat' => self::LAT - 0.00108, 'lng' => self::LNG, 'country_code' => 'NL']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $record = (new Item())->answerOsm(null)->setLetter('B')->setName('Watertappunt')
            ->setGeom(\sprintf('{"type":"Point","coordinates":[%F,%F]}', self::LNG, self::LAT + 0.00007))->setCountryCode('NL')
            ->setState(ItemState::Verified)->setSource(ItemSource::Authority)->setSourceRef('similar-test:tap')
            ->setAttributes(['type' => 'Drinking tap']);
        $em->persist($record);
        $em->flush();

        return (int) $record->getId();
    }

    private function login(KernelBrowser $client, string $tag): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail("similar-{$tag}@example.com");
        $user->setDisplayName('Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form(['_username' => "similar-{$tag}@example.com", '_password' => 'securepass12345!']));
        $client->followRedirect();
    }
}
