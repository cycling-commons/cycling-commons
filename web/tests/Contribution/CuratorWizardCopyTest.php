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

    /**
     * The form warns that a change is already waiting, and warns only.
     *
     * Existence and age, never who and never what: the content is unreviewed
     * and stays unpublished, and the age is the only part a rider needs to
     * decide whether to bother (moderation-and-contribution.md §7.3c). The
     * form underneath still works, because a second rider may have something
     * genuinely different to say.
     */
    public function testTheFormWarnsWhenSomebodyElsesChangeIsWaiting(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $this->seedItem();

        $author = new User();
        $author->setEmail('waiting-author@example.com');
        $author->setDisplayName('Somebody Else');
        $em->persist($author);
        $em->flush();

        self::seedPending($em, (int) $item->getId(), (int) $author->getId());

        $this->login($client, 'waiting-reader@example.com', []);
        $client->request('GET', '/improve?item='.$item->getId().'&type=scenic-views');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.wiz-waiting');
        $html = (string) $client->getResponse()->getContent();
        // Never who, never what.
        self::assertStringNotContainsString('Somebody Else', $html);
        self::assertStringNotContainsString('waiting-author@example.com', $html);
        self::assertStringNotContainsString('Out of order', $html);
        // Warn, never block.
        self::assertSelectorExists('form#improve-form');
    }

    /**
     * A rider's own waiting change gets the other sentence, never the
     * stranger's: what they send next is added to it (§7.3b), and telling
     * them a stranger is waiting would be a lie about their own work.
     */
    public function testYourOwnWaitingChangeGetsItsOwnSentence(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $this->seedItem();

        $this->login($client, 'waiting-mine@example.com', []);
        $me = $em->getRepository(User::class)->findOneBy(['email' => 'waiting-mine@example.com']);
        self::assertNotNull($me);

        self::seedPending($em, (int) $item->getId(), (int) $me->getId());

        $client->request('GET', '/improve?item='.$item->getId().'&type=scenic-views');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.wiz-waiting', 'You already have a change waiting');
    }

    /** One waiting edit on an item, written straight in: the page only reads it. */
    private static function seedPending(EntityManagerInterface $em, int $itemId, int $userId): void
    {
        $em->getConnection()->executeStatement(
            "INSERT INTO submission (type, letter, item_id, user_id, status, title, geom, country_code,
                                     changes, payload, created_at)
             VALUES ('edit', 'P', :item, :uid, 'pending', 'Viewpoint',
                     ST_SetSRID(ST_MakePoint(6.027, 50.426), 4326), 'BE',
                     :changes, '{}', '2026-09-01 12:00:00+00')",
            ['item' => $itemId, 'uid' => $userId, 'changes' => '{"condition":{"was":null,"now":"Out of order"}}'],
        );
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

    /**
     * A pin move that hides rider photos: a rider waits for a curator, and a
     * curator, whose edit applies at once, is told to confirm the photos
     * after saving (photo-uploads.md §5g).
     */
    public function testThePinPhotosWarningTellsACuratorWhatToDoNext(): void
    {
        $client = static::createClient();
        $this->login($client, 'pin-photos-curator@example.com', ['ROLE_CURATOR']);
        $item = $this->seedItem();

        $client->request('GET', '/improve?item='.$item->getId().'&type=scenic-views');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('After you save, open the place on the map and click', $html);
        self::assertStringNotContainsString('stays hidden until a curator confirms', $html);
    }

    public function testThePinPhotosWarningTellsARiderACuratorDecides(): void
    {
        $client = static::createClient();
        $this->login($client, 'pin-photos-rider@example.com', []);
        $item = $this->seedItem();

        $client->request('GET', '/improve?item='.$item->getId().'&type=scenic-views');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('It stays hidden until a curator confirms it was taken here.', $html);
        self::assertStringNotContainsString('After you save, open the place on the map', $html);
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

    /**
     * A curator adding a place is ASKED whether it is already in
     * OpenStreetMap, and the page carries both promises.
     *
     * Approving a new place needs that answer (catalog-data-model.md §5b), so
     * whether this one publishes now is not known when the page renders: it
     * depends on what the curator picks. Both funnels ship and
     * assets/contribute/osm-answer.js shows whichever the answer makes true.
     */
    public function testACuratorAddingANewPlaceIsAskedTheOsmQuestion(): void
    {
        $client = static::createClient();
        $this->login($client, 'wizard-curator-add@example.com', ['ROLE_CURATOR']);

        $client->request('GET', '/improve?type=scenic-views&mode=add');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#wz-osmq');
        self::assertSelectorExists('[name="improve[osmAnswer]"]');
        self::assertSelectorExists('script[src*="osm-answer"]');
        // Both promises, one hidden, for the script to swap.
        self::assertSelectorExists('#lc-queued');
        self::assertSelectorExists('#lc-curator[hidden]');
    }

    /**
     * A rider is never asked (catalog-data-model.md §5b): the question is not
     * theirs to settle, and offering it would imply their answer counts.
     */
    public function testARiderAddingANewPlaceIsNotAskedTheOsmQuestion(): void
    {
        $client = static::createClient();
        $this->login($client, 'wizard-rider-add@example.com', []);

        $client->request('GET', '/improve?type=scenic-views&mode=add');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#wz-osmq');
        self::assertSelectorNotExists('#lc-curator');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('osm-answer', $html);
        self::assertStringContainsString('Submit for review', $html);
    }

    /** The candidate list is curator-only, like the question it feeds. */
    public function testTheOsmCandidateEndpointIsCuratorOnly(): void
    {
        $client = static::createClient();
        $this->login($client, 'wizard-rider-nearby@example.com', []);

        $client->request('GET', '/contribute/osm-nearby?type=scenic-views&lat=50.42&lng=6.02');
        self::assertResponseStatusCodeSame(403);
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
