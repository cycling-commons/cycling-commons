<?php

// SPDX-License-Identifier: AGPL-3.0-only

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

/**
 * The one sentence of the drawer that is personal (data-provider-hierarchy.md
 * §6.7.3): "You confirmed this on ...". It is its own fragment, served
 * private and no-store, because a shared cache holding one rider's sentence
 * and serving it to another is the worst failure this feature could have.
 * Anonymous visitors hold no confirmations, so the answer is empty before
 * the database is asked anything.
 */
final class ItemPersonalNoteTest extends WebTestCase
{
    public function testAnAnonymousRequestIsEmptyWithoutALookupAndNeverCacheable(): void
    {
        $client = static::createClient();

        // An id that exists nowhere: a lookup would answer 404, an empty
        // payload proves nothing was looked up.
        $client->request('GET', '/items/999999999/mine');

        self::assertResponseIsSuccessful();
        self::assertSame(['confirmed_at' => null], $this->payload($client));
        $this->assertPrivateNoStore($client);
    }

    public function testASignedInRiderGetsTheirOwnDateAndNobodyElses(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $this->item($em);
        $me = $this->rider($em, 'mine-me@example.test');
        $other = $this->rider($em, 'mine-other@example.test');
        $db = $em->getConnection();
        $db->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, :user, 'exists', 'drawer', '2026-05-12 09:00:00', '2026-05-12 09:00:00')",
            ['item' => $item->getId(), 'user' => $me->getId()],
        );
        $db->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, :user, 'exists', 'drawer', '2026-06-01 09:00:00', '2026-06-01 09:00:00')",
            ['item' => $item->getId(), 'user' => $other->getId()],
        );

        $client->loginUser($me);
        $client->request('GET', '/items/'.$item->getId().'/mine');

        self::assertResponseIsSuccessful();
        self::assertSame(['confirmed_at' => '2026-05-12'], $this->payload($client), 'my date, not the newest date');
        $this->assertPrivateNoStore($client);
    }

    public function testASignedInRiderWithNoConfirmationGetsNull(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $this->item($em);
        $client->loginUser($this->rider($em, 'mine-none@example.test'));

        $client->request('GET', '/items/'.$item->getId().'/mine');

        self::assertResponseIsSuccessful();
        self::assertSame(['confirmed_at' => null], $this->payload($client));
    }

    public function testAFormAnswerIsNotStandingThere(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $this->item($em);
        $me = $this->rider($em, 'mine-form@example.test');
        $em->getConnection()->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, :user, 'exists', 'form', '2026-05-12 09:00:00', '2026-05-12 09:00:00')",
            ['item' => $item->getId(), 'user' => $me->getId()],
        );
        $client->loginUser($me);

        $client->request('GET', '/items/'.$item->getId().'/mine');

        self::assertSame(['confirmed_at' => null], $this->payload($client));
    }

    private function assertPrivateNoStore(KernelBrowser $client): void
    {
        $cc = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cc);
        self::assertStringContainsString('no-store', $cc);
        self::assertStringNotContainsString('public', $cc);
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $p */
        $p = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $p;
    }

    private function rider(EntityManagerInterface $em, string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName('R');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function item(EntityManagerInterface $em): Item
    {
        $item = (new Item())->setLetter('B')->setName('Fountain')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Verified)->setSource(ItemSource::Osm)->setSourceRef('node/'.bin2hex(random_bytes(4)))->setAttributes([]);
        $em->persist($item);
        $em->flush();

        return $item;
    }
}
