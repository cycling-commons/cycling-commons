<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Moving a pin is a contribution, and it has to survive being made.
 *
 * It used to count only towards "did anything change" and was then discarded:
 * the submission was filed at the item's OLD point, the diff never mentioned
 * the move, and approving it moved nothing. The rider did the work, the wizard
 * accepted it, and the system dropped it silently — which is the worst of the
 * three possible outcomes (owner-reported 2026-08-12).
 */
final class MovedPinTest extends WebTestCase
{
    private const OLD_LAT = 50.426;
    private const OLD_LNG = 6.027;
    private const NEW_LAT = 50.4312;
    private const NEW_LNG = 6.0355;

    private function login(KernelBrowser $client): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('moved-pin@example.com');
        $user->setDisplayName('Pin mover');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }

    private function seedItem(): Item
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('P')->setName('Viewpoint')
            ->setGeom(\sprintf('{"type":"Point","coordinates":[%s,%s]}', self::OLD_LNG, self::OLD_LAT))
            ->setCountryCode('BE')->setState(ItemState::Unverified)->setSource(ItemSource::Osm)
            ->setSourceRef('node/moved-pin-'.bin2hex(random_bytes(4)))
            ->setAttributes([]);
        $em->persist($item);
        $em->flush();

        return $item;
    }

    public function testAMovedPinIsRecordedAsAChange(): void
    {
        $client = static::createClient();
        $this->login($client);
        $item = $this->seedItem();

        $crawler = $client->request('GET', '/improve?item='.$item->getId().'&type=P');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Next →')->form([
            'improve[lat]' => (string) self::NEW_LAT,
            'improve[lng]' => (string) self::NEW_LNG,
        ]);
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Submission|null $submission */
        $submission = $em->getRepository(Submission::class)->findOneBy(['itemId' => $item->getId()]);
        self::assertNotNull($submission, 'moving a pin alone is a contribution');

        $changes = $submission->getChanges();
        self::assertArrayHasKey(Item::LOCATION_FIELD, $changes, 'the move is in the diff a curator reads');
        self::assertStringContainsString('50.42600', (string) $changes[Item::LOCATION_FIELD]['was']);
        self::assertStringContainsString('50.43120', (string) $changes[Item::LOCATION_FIELD]['now']);

        // And the submission sits where the RIDER put it: the desk pins
        // submissions on a map, and a curator judging a move must see the
        // proposed spot rather than the one being corrected.
        $geom = json_decode((string) $em->getConnection()->fetchOne(
            'SELECT ST_AsGeoJSON(geom) FROM submission WHERE id = ?', [$submission->getId()]
        ), true);
        self::assertEqualsWithDelta(self::NEW_LAT, $geom['coordinates'][1], 1e-6);
        self::assertEqualsWithDelta(self::NEW_LNG, $geom['coordinates'][0], 1e-6);
    }

    public function testAnEditThatMovesNothingIsStillRefused(): void
    {
        // The move must not become a way to file an empty contribution: a
        // submit with the pin exactly where it was, and nothing else touched,
        // is still nothing to review.
        $client = static::createClient();
        $this->login($client);
        $item = $this->seedItem();

        $crawler = $client->request('GET', '/improve?item='.$item->getId().'&type=P');
        $form = $crawler->selectButton('Next →')->form([
            'improve[lat]' => (string) self::OLD_LAT,
            'improve[lng]' => (string) self::OLD_LNG,
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Submission::class)->count(['itemId' => $item->getId()]));
    }
}
