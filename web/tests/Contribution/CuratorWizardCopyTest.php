<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\ItemConfirmation;
use App\Catalog\Entity\Region;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The wizard's promise matches what happens: a curator's own EDIT is applied
 * at once (moderation-and-contribution.md 1.6), so the button and the
 * lifecycle box must not say "a curator reads it first" to a curator.
 */
final class CuratorWizardCopyTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function login(KernelBrowser $client, string $email, array $roles): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Wizard reader');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if ([] !== $roles) {
            // Elevated roles without a TOTP secret are redirected to /2fa/setup.
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }

    private function seedItem(): Item
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('P')->setName('Viewpoint')
            ->setGeom('{"type":"Point","coordinates":[6.027,50.426]}')
            ->setCountryCode('BE')->setState(ItemState::Unverified)->setSource(ItemSource::Osm)
            ->setSourceRef('node/wizard-copy-'.bin2hex(random_bytes(4)))
            ->setAttributes([]);
        $em->persist($item);
        $em->flush();

        return $item;
    }

    public function testARiderIsPromisedAReview(): void
    {
        $client = static::createClient();
        $this->login($client, 'wizard-rider@example.com', []);
        $item = $this->seedItem();

        $client->request('GET', '/improve?item='.$item->getId().'&type=scenic-views');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Submit for review', $html);
        self::assertStringContainsString('a curator reads it first', $html);
        self::assertStringNotContainsString('Apply change', $html);
        self::assertStringNotContainsString('improve[confirmNow]', $html, 'a rider never sees the box, not even hidden');
    }

    public function testACuratorEditingIsPromisedAnAppliedChange(): void
    {
        $client = static::createClient();
        $this->login($client, 'wizard-curator@example.com', ['ROLE_CURATOR']);
        $item = $this->seedItem();

        $client->request('GET', '/improve?item='.$item->getId().'&type=scenic-views');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Apply change', $html);
        self::assertStringContainsString('goes on the map at once', $html);
        self::assertStringNotContainsString('Submit for review', $html);
        self::assertStringNotContainsString('a curator reads it first', $html);
        self::assertStringContainsString('Mark it confirmed', $html, 'the optional confirmation, off by default');
        self::assertStringContainsString('name="improve[confirmNow]"', $html);
        self::assertStringNotContainsString('name="improve[confirmNow]" checked', $html);
    }

    /**
     * A box that asks what the curator already did is noise (owner
     * 2026-09-10): once their own drawer confirmation is on the row, the
     * wizard stops offering "Mark it confirmed".
     */
    public function testACuratorWhoAlreadyConfirmedThePlaceSeesNoBox(): void
    {
        $client = static::createClient();
        $this->login($client, 'wizard-curator-done@example.com', ['ROLE_CURATOR']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $this->seedItem();
        $me = $em->getRepository(User::class)->findOneBy(['email' => 'wizard-curator-done@example.com']);
        $em->getConnection()->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, :user, 'exists', 'drawer', NOW(), NOW())",
            ['item' => $item->getId(), 'user' => (int) $me?->getId()],
        );

        $client->request('GET', '/improve?item='.$item->getId().'&type=scenic-views');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Apply change', $html, 'the edit still applies at once');
        self::assertStringNotContainsString('Mark it confirmed', $html, 'already confirmed by this curator: nothing to ask');
    }

    public function testACuratorAddingANewPlaceStillReadsTheRiderCopy(): void
    {
        // A NEW place from a bare pin queues even for a curator: the OSM
        // question lives on the queue card. One taken from an OSM node applies
        // (CatalogContributionServiceTest::testACuratorsNewPlaceFromAnOsmNodeIsAppliedAtOnce).
        $client = static::createClient();
        $this->login($client, 'wizard-curator-add@example.com', ['ROLE_CURATOR']);

        $client->request('GET', '/improve?type=scenic-views&mode=add');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Submit for review', $html);
        self::assertStringNotContainsString('Apply change', $html);
        self::assertStringNotContainsString('improve[confirmNow]', $html);
    }

    public function testATickedBoxTurnsTheCuratorsEditIntoAFullPin(): void
    {
        $client = static::createClient();
        $this->login($client, 'wizard-curator-tick@example.com', ['ROLE_CURATOR']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Region())->setSlug('wallonia')->setName('Wallonia')->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}'));
        $em->flush();
        $item = $this->seedItem();

        $crawler = $client->request('GET', '/improve?item='.$item->getId().'&type=scenic-views');
        $form = $crawler->selectButton('Next →')->form(['improve[details][name]' => 'Signal de Botrange']);
        $form['improve[confirmNow]']->tick();
        $client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('you confirmed the place', (string) $client->getResponse()->getContent());

        // The kernel rebooted on submit; read the row back through the new container.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $fresh = $em->find(Item::class, (int) $item->getId());
        self::assertNotNull($fresh);
        self::assertSame(ItemState::Verified, $fresh->getState());
        self::assertSame(1, $em->getRepository(ItemConfirmation::class)->count(['itemId' => (int) $item->getId()]));
    }
}
