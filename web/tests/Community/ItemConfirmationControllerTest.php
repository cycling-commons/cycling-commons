<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ItemConfirmationControllerTest extends WebTestCase
{
    private function rider(EntityManagerInterface $em, string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName('R');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function item(EntityManagerInterface $em, string $letter, ItemState $state = ItemState::Verified): Item
    {
        $item = (new Item())->setLetter($letter)->setName('Fountain')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState($state)->setSource(ItemSource::Osm)->setSourceRef('node/'.bin2hex(random_bytes(4)))->setAttributes([]);
        $em->persist($item);
        $em->flush();

        return $item;
    }

    /** @return array<string,mixed> */
    private function snapshot(KernelBrowser $client, int $itemId): array
    {
        $client->request('GET', '/items/'.$itemId.'/confirmations');

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    public function testAnonymousSeesPublicTalliesButNoToken(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $water = $this->item($em, 'C');

        $snap = $this->snapshot($client, $water->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(['potable' => 0, 'not_potable' => 0], $snap['stances']);
        self::assertNull($snap['mine']);
        self::assertSame('potability', $snap['stanceKind']);
        self::assertArrayNotHasKey('token', $snap, 'anonymous viewers get no CSRF token');
    }

    public function testLoggedInRiderCanConfirmPotableAndTallyUpdates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $water = $this->item($em, 'C');
        $u = $this->rider($em, 'potable@test.test');
        $client->loginUser($u);

        $token = $this->snapshot($client, $water->getId())['token'];
        $client->request('POST', '/items/'.$water->getId().'/confirm', ['stance' => 'potable', '_token' => $token]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame(1, $data['stances']['potable']);
        self::assertSame('potable', $data['mine']);
    }

    public function testSwitchingStanceDoesNotDoubleCount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $water = $this->item($em, 'C');
        $u = $this->rider($em, 'switch@test.test');
        $client->loginUser($u);
        $id = $water->getId();

        $client->request('POST', '/items/'.$id.'/confirm', ['stance' => 'potable', '_token' => $this->snapshot($client, $id)['token']]);
        $client->request('POST', '/items/'.$id.'/confirm', ['stance' => 'not_potable', '_token' => $this->snapshot($client, $id)['token']]);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(0, $data['stances']['potable']);
        self::assertSame(1, $data['stances']['not_potable']);
    }

    public function testAnonymousConfirmIs401(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $water = $this->item($em, 'C');

        $client->request('POST', '/items/'.$water->getId().'/confirm', ['stance' => 'potable', '_token' => 'x']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testBadCsrfIs403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $water = $this->item($em, 'C');
        $client->loginUser($this->rider($em, 'csrf@test.test'));

        $client->request('POST', '/items/'.$water->getId().'/confirm', ['stance' => 'potable', '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPotableOnANonWaterUtilityIs422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $services = $this->item($em, 'D');
        $u = $this->rider($em, 'svc@test.test');
        $client->loginUser($u);

        $client->request('POST', '/items/'.$services->getId().'/confirm', ['stance' => 'potable', '_token' => $this->snapshot($client, $services->getId())['token']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUtilityOffersExistenceConfirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $services = $this->item($em, 'D');
        $u = $this->rider($em, 'exists@test.test');
        $client->loginUser($u);

        $client->request('POST', '/items/'.$services->getId().'/confirm', ['stance' => 'exists', '_token' => $this->snapshot($client, $services->getId())['token']]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $data['stances']['exists']);
        self::assertSame('existence', $data['stanceKind']);
    }

    public function testAVotableItemIsConfirmableToo(): void
    {
        /* This used to assert the opposite — a climb was votable and therefore
           NOT confirmable. The rule changed on 2026-08-12: "could it vanish"
           was the wrong test, and a confirmation is a rider saying *I was there
           and this is right*, which a climb can be wrong about like anything
           else. Voting ranks a region's best; confirming vouches for the entry.
           They are different questions and one never excluded the other. */
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $climb = $this->item($em, 'B');

        $client->request('GET', '/items/'.$climb->getId().'/confirmations');
        self::assertResponseIsSuccessful();
    }

    public function testARouteHasNoConfirmationEndpoint(): void
    {
        // K · routes are not items and never had one — the endpoint is keyed on
        // the item table, so this stays a 404 for a reason that has not changed.
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $surface = $this->item($em, 'A');   // segment-located: not a place you stand at

        $client->request('GET', '/items/'.$surface->getId().'/confirmations');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnservedItemIs404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submitted = $this->item($em, 'C', ItemState::Submitted);

        $client->request('GET', '/items/'.$submitted->getId().'/confirmations');
        self::assertResponseStatusCodeSame(404);
    }
}
