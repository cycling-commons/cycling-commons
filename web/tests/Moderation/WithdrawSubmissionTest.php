<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\ModerationService;
use App\Moderation\NotTheSubmitterException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A rider takes back their own undecided submission (owner 2026-08-16).
 * Withdrawal reuses the reject mechanics (a new-item's row leaves the map,
 * exactly like a rejection) but it is not a decision: only the submitter may
 * do it, only while undecided, and no outcome message is sent to the person
 * who did it themselves.
 */
final class WithdrawSubmissionTest extends WebTestCase
{
    private function rider(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail($email);
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function submission(User $by, SubmissionStatus $status, ?int $itemId = null, SubmissionType $type = SubmissionType::Edit): Submission
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $s = (new Submission())->setType($type)->setLetter('Q')->setUserId((int) $by->getId())
            ->setStatus($status)->setTitle('Withdraw-me')
            ->setGeom('{"type":"Point","coordinates":[6.0,49.9]}')->setCountryCode('LU')
            ->setChanges([])->setPayload([]);
        if (null !== $itemId) {
            $s->setItemId($itemId);
        }
        $em->persist($s);
        $em->flush();

        return $s;
    }

    private function makeItem(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('Q', 'Withdraw Castle', ST_SetSRID(ST_MakePoint(6.0, 49.9), 4326), 'LU', 'unverified', 'user', 'user:withdraw-1', '{}', NOW(), NOW())
             RETURNING id",
        );
    }

    public function testWithdrawingANewItemTakesItOffTheMapLikeARejection(): void
    {
        static::createClient();
        $me = $this->rider('withdraw-me@example.test');
        $itemId = $this->makeItem();
        $sub = $this->submission($me, SubmissionStatus::Pending, $itemId, SubmissionType::NewItem);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        static::getContainer()->get(ModerationService::class)->withdraw((int) $sub->getId(), $me);

        $em->clear();
        $fresh = $em->find(Submission::class, (int) $sub->getId());
        self::assertSame(SubmissionStatus::Withdrawn, $fresh->getStatus());
        self::assertNotNull($fresh->getDecidedAt(), 'the retention clock starts');
        self::assertSame(ItemState::Rejected, $em->find(Item::class, $itemId)->getState(), 'the minted row leaves the map');
        $messages = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM user_message WHERE user_id = :uid', ['uid' => (int) $me->getId()],
        );
        self::assertSame(0, (int) $messages, 'no outcome message to the person who did it themselves');
    }

    public function testOnlyTheSubmitterMayWithdraw(): void
    {
        static::createClient();
        $mine = $this->submission($this->rider('withdraw-owner@example.test'), SubmissionStatus::Pending);
        $stranger = $this->rider('withdraw-stranger@example.test');

        $this->expectException(NotTheSubmitterException::class);
        static::getContainer()->get(ModerationService::class)->withdraw((int) $mine->getId(), $stranger);
    }

    public function testADecidedSubmissionCannotBeWithdrawn(): void
    {
        $client = static::createClient();
        $me = $this->rider('withdraw-late@example.test');
        $sub = $this->submission($me, SubmissionStatus::Approved);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Through the endpoint: the race with a curator ends in a flash and an
        // unchanged status, never a half-withdrawal.
        $client->loginUser($me);
        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('withdraw/'.$sub->getId(), (string) $client->getResponse()->getContent(),
            'no withdraw button on a decided row');

        $pending = $this->submission($me, SubmissionStatus::Pending);
        // Withdraw FROM a category-filtered view: the redirect must land back
        // on the same filter (owner 2026-08-16: "the system loses the
        // selected category").
        $crawler = $client->request('GET', '/profile?letter=Q');
        $form = $crawler->filter('form[action$="/profile/withdraw/'.$pending->getId().'"]')->form();
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertStringContainsString('letter=Q', (string) $client->getResponse()->headers->get('Location'));
        $em->clear();
        self::assertSame(SubmissionStatus::Withdrawn, $em->find(Submission::class, (int) $pending->getId())->getStatus());
        self::assertSame(SubmissionStatus::Approved, $em->find(Submission::class, (int) $sub->getId())->getStatus());
    }
}
