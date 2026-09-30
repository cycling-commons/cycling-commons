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
use App\Moderation\ModerationScope;
use App\Moderation\ModerationService;
use App\Moderation\NotTheSubmitterException;
use App\Moderation\SubmissionQueue;
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
        $crawler = $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('withdraw/'.$sub->getId(), (string) $client->getResponse()->getContent(),
            'no withdraw button on a decided row');

        $pending = $this->submission($me, SubmissionStatus::Pending);
        // Withdraw FROM a category-filtered view: the redirect must land back
        // on the same filter (owner 2026-08-16: "the system loses the
        // selected category").
        $crawler = $client->request('GET', '/account/contributions?letter=Q');
        $form = $crawler->filter('form[action$="/account/contributions/withdraw/'.$pending->getId().'"]')->form();
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertStringContainsString('letter=Q', (string) $client->getResponse()->headers->get('Location'));
        $em->clear();
        self::assertSame(SubmissionStatus::Withdrawn, $em->find(Submission::class, (int) $pending->getId())->getStatus());
        self::assertSame(SubmissionStatus::Approved, $em->find(Submission::class, (int) $sub->getId())->getStatus());
    }

    /**
     * Legal hold is beyond every change (docs/specs/photo-uploads.md §6d): the
     * rider cannot withdraw a held submission, sees no button for it, and a
     * form rendered before the hold ends in the same flash a decided one gets.
     * The admin's release then returns it to the queue it left.
     */
    public function testAHeldSubmissionCannotBeWithdrawn(): void
    {
        $client = static::createClient();
        $me = $this->rider('withdraw-held@example.test');
        $sub = $this->submission($me, SubmissionStatus::Pending);
        $id = (int) $sub->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $moderation = static::getContainer()->get(ModerationService::class);
        $admin = $this->rider('withdraw-held-admin@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $em->flush();

        // The page rendered before the hold still carries the form.
        $client->loginUser($me);
        $crawler = $client->request('GET', '/account/contributions');
        $form = $crawler->filter('form[action$="/account/contributions/withdraw/'.$id.'"]')->form();

        $moderation->escalateSubmission($id, $admin, 'Suspected illegal content.');

        $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('withdraw/'.$id, (string) $client->getResponse()->getContent(),
            'no withdraw button on a held row');

        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.cc-notice', 'A curator decided this one before your withdrawal',
            'the same words as for a decided submission, nothing about a hold');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $held = $em->find(Submission::class, $id);
        self::assertSame(SubmissionStatus::Pending, $held->getStatus());
        self::assertNull($held->getDecidedAt());

        self::assertTrue(static::getContainer()->get(ModerationService::class)
            ->releaseSubmission($id, $em->find(User::class, (int) $admin->getId())));
        $queue = static::getContainer()->get(SubmissionQueue::class);
        self::assertContains($id, array_column($queue->filtered(ModerationScope::global(), null, null, null), 'id'),
            'released, it is back in the queue it left');
    }
}
