<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The OSM question on a new place is asked in the map drawer, where the
 * place is approved (catalog-data-model.md §5b, moderation-and-contribution.md
 * §5.4): the pending payload carries its state, the answer endpoint speaks
 * JSON to the drawer, and the approval that was refused goes through once
 * the question is answered.
 */
final class OsmAnswerFromTheDrawerTest extends WebTestCase
{
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'];

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email, array $roles): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName('Drawer tester');
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles($roles);
        if ([] !== $roles) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'securepass12345!'));
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    private function unansweredItem(): Item
    {
        $item = (new Item())
            ->setLetter('P')
            ->setName('Uitkijkpunt Drawer '.uniqid('', true))
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode('BE')
            ->setSource(ItemSource::User)
            ->setSourceRef('test:osm-drawer:'.uniqid('', true))
            ->setState(ItemState::Submitted)
            ->setAttributes([]);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function newPlace(Item $item, int $userId): Submission
    {
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('P')->setUserId($userId)
            ->setTitle('Drawer osm '.uniqid('', true))
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([])
            ->setItemId((int) $item->getId());
        $this->em()->persist($sub);
        $this->em()->flush();

        return $sub;
    }

    /** @return array{mod: string, osm: string, html: string} */
    private function openMap(KernelBrowser $client): array
    {
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', $html, $m));
        self::assertSame(1, preg_match('/CC_OSM_TOKEN\s*=\s*"([^"]+)"/', $html, $o), 'the drawer gets its own token for the answer endpoint');

        return ['mod' => $m[1], 'osm' => $o[1], 'html' => $html];
    }

    public function testThePendingPayloadCarriesTheOpenQuestion(): void
    {
        $client = static::createClient();
        $rider = $this->user('drawer-osm-rider-'.uniqid('', true).'@example.com', []);
        $this->newPlace($this->unansweredItem(), (int) $rider->getId());
        $client->loginUser($this->user('drawer-osm-curator-'.uniqid('', true).'@example.com', ['ROLE_CURATOR']));

        $map = $this->openMap($client);
        self::assertStringContainsString('"osm":{"state":"open","ref":null,"candidates":[]}', $map['html']);
    }

    public function testAnsweringInTheDrawerLetsTheRefusedApprovalThrough(): void
    {
        $client = static::createClient();
        $rider = $this->user('drawer-osm-rider-'.uniqid('', true).'@example.com', []);
        $item = $this->unansweredItem();
        $sub = $this->newPlace($item, (int) $rider->getId());
        $client->loginUser($this->user('drawer-osm-curator-'.uniqid('', true).'@example.com', ['ROLE_CURATOR']));
        $map = $this->openMap($client);

        // Approve first: refused, and the drawer is told why.
        $client->request('POST', '/moderate/decide', ['moderation_decision' => [
            'submission_id' => (string) $sub->getId(), 'decision' => 'approve', '_token' => $map['mod'],
        ]], [], self::XHR);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'osm_unanswered'], json_decode((string) $client->getResponse()->getContent(), true));

        // "Not in OSM", from the drawer: JSON back, the row answered.
        $client->request('POST', '/moderate/osm-answer', [
            'submission_id' => (string) $sub->getId(), 'ref' => '', '_token' => $map['osm'],
        ], [], self::XHR);
        self::assertResponseIsSuccessful();
        self::assertSame(['state' => 'none', 'ref' => null], json_decode((string) $client->getResponse()->getContent(), true));
        $this->em()->clear();
        $fresh = $this->em()->find(Item::class, (int) $item->getId());
        self::assertNotNull($fresh);
        self::assertTrue($fresh->osmAnswered());
        self::assertNull($fresh->getOsmRef());

        // The same approve now goes through.
        $client->request('POST', '/moderate/decide', ['moderation_decision' => [
            'submission_id' => (string) $sub->getId(), 'decision' => 'approve', '_token' => $map['mod'],
        ]], [], self::XHR);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $decided = $this->em()->find(Submission::class, $sub->getId());
        self::assertNotNull($decided);
        self::assertSame(SubmissionStatus::Approved, $decided->getStatus());
    }

    public function testLinkingFromTheDrawerReturnsTheRef(): void
    {
        $client = static::createClient();
        $rider = $this->user('drawer-osm-rider-'.uniqid('', true).'@example.com', []);
        $item = $this->unansweredItem();
        $sub = $this->newPlace($item, (int) $rider->getId());
        $client->loginUser($this->user('drawer-osm-curator-'.uniqid('', true).'@example.com', ['ROLE_CURATOR']));
        $map = $this->openMap($client);

        $client->request('POST', '/moderate/osm-answer', [
            'submission_id' => (string) $sub->getId(), 'ref' => 'node/930340800', '_token' => $map['osm'],
        ], [], self::XHR);
        self::assertResponseIsSuccessful();
        self::assertSame(['state' => 'linked', 'ref' => 'node/930340800'], json_decode((string) $client->getResponse()->getContent(), true));
        $this->em()->clear();
        $fresh = $this->em()->find(Item::class, (int) $item->getId());
        self::assertNotNull($fresh);
        self::assertSame('node/930340800', $fresh->getOsmRef());
    }

    public function testARefusalIsACodeForTheDrawerAndAFlashForTheQueue(): void
    {
        $client = static::createClient();
        $rider = $this->user('drawer-osm-rider-'.uniqid('', true).'@example.com', []);
        $sub = $this->newPlace($this->unansweredItem(), (int) $rider->getId());
        $client->loginUser($this->user('drawer-osm-curator-'.uniqid('', true).'@example.com', ['ROLE_CURATOR']));
        $map = $this->openMap($client);

        $client->request('POST', '/moderate/osm-answer', [
            'submission_id' => (string) $sub->getId(), 'ref' => 'relation/1', '_token' => $map['osm'],
        ], [], self::XHR);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'bad_ref'], json_decode((string) $client->getResponse()->getContent(), true));

        // The queue card's form keeps its redirect-and-flash.
        $client->request('POST', '/moderate/osm-answer', [
            'submission_id' => (string) $sub->getId(), 'ref' => 'relation/1', '_token' => $map['osm'],
        ]);
        self::assertResponseRedirects('/moderate/submissions');
    }

    public function testARidersOwnPinCarriesNoQuestion(): void
    {
        $client = static::createClient();
        $rider = $this->user('drawer-osm-rider-'.uniqid('', true).'@example.com', []);
        $this->newPlace($this->unansweredItem(), (int) $rider->getId());
        $client->loginUser($rider);

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"osm":null', $html);
        self::assertStringNotContainsString('"state":"open"', $html);
        self::assertStringNotContainsString('CC_OSM_TOKEN', $html);
    }
}
