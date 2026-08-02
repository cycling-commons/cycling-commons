<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Moderation\ModerationScope;
use App\Moderation\ModerationService;
use App\Moderation\SubmissionQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Task 9 (moderation-feedback spec M6b): the needs-info reply loop — a rider
 * answers a curator's needs-info request from their own /messages page, and
 * the reply re-queues the submission (back to `pending`) plus delivers a
 * `rider_reply` message to the deciding curator. Every curator also sees the
 * reply surfaced directly on the queue row (SubmissionQueue::filtered), not
 * only the original decider's own inbox.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class NeedsInfoReplyTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function moderation(): ModerationService
    {
        return static::getContainer()->get(ModerationService::class);
    }

    private function queue(): SubmissionQueue
    {
        return static::getContainer()->get(SubmissionQueue::class);
    }

    private function curator(string $suffix): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('curator-'.$suffix.'-'.uniqid('', true).'@reply.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function rider(string $suffix): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('rider-'.$suffix.'-'.uniqid('', true).'@reply.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function seedSubmission(int $userId, string $title): Submission
    {
        $em = $this->em();
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($userId)
            ->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $em->persist($sub);
        $em->flush();

        return $sub;
    }

    /**
     * Seeds a NeedsInfo submission + its needs-info message via
     * ModerationService::decide, for the same real decision path
     * DecisionMessagesTest exercises — not a hand-rolled fixture.
     */
    private function seedNeedsInfo(User $rider, User $curator, string $title): Submission
    {
        $sub = $this->seedSubmission((int) $rider->getId(), $title);
        $this->moderation()->decide((int) $sub->getId(), 'needs_info', $curator, 'Can you confirm the surface?');
        $this->em()->clear();

        /** @var Submission $reloaded */
        $reloaded = $this->em()->find(Submission::class, $sub->getId());

        return $reloaded;
    }

    private function needsInfoMessageId(int $riderId): int
    {
        /** @var UserMessage|null $m */
        $m = $this->em()->getRepository(UserMessage::class)->findOneBy([
            'userId' => $riderId,
            'kind' => UserMessageKind::SubmissionNeedsInfo,
        ]);
        self::assertInstanceOf(UserMessage::class, $m);

        return (int) $m->getId();
    }

    /** @return list<UserMessage> */
    private function riderReplyMessagesFor(int $curatorId): array
    {
        /** @var list<UserMessage> $rows */
        $rows = $this->em()->getRepository(UserMessage::class)->findBy([
            'userId' => $curatorId,
            'kind' => UserMessageKind::RiderReply,
        ]);

        return $rows;
    }

    /** @return array{id:int,riderReply:?string} */
    private function findQueueRow(int $submissionId): array
    {
        foreach ($this->queue()->filtered(ModerationScope::global(), null, null, null) as $row) {
            if ($submissionId === $row['id']) {
                /* @var array{id:int,riderReply:?string} $row */
                return $row;
            }
        }

        self::fail('Submission '.$submissionId.' was not found in the moderation queue.');
    }

    private function loginAndVisitMessages(KernelBrowser $client, User $user): Crawler
    {
        $client->loginUser($user);
        $crawler = $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * `message-reply` is a session-bound CSRF token id (not in csrf.yaml's
     * stateless_token_ids — same shape as `moderate-message`), so it can't
     * be minted from the container between requests. Read a real value off
     * a rendered reply form instead, the way a browser would (see
     * CuratorMessageTest::messageToken for the same pattern). The token id
     * is fixed, not tied to which row the form is for — any rendered reply
     * form in the viewer's own session yields a value valid for any POST in
     * that session.
     */
    private function replyTokenFrom(Crawler $crawler): string
    {
        $field = $crawler->filter('form.msg-reply input[name="_token"]');
        self::assertGreaterThan(0, $field->count(), 'Expected a rendered reply form to read the CSRF token from.');

        return (string) $field->first()->attr('value');
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function testRiderReplyRequeuesTheSubmissionAndNotifiesTheCurator(): void
    {
        $client = static::createClient();
        $curator = $this->curator('happy');
        $rider = $this->rider('happy');
        $sub = $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Happy path');

        $crawler = $this->loginAndVisitMessages($client, $rider);
        self::assertGreaterThan(0, $crawler->filter('form.msg-reply')->count());
        $token = $this->replyTokenFrom($crawler);

        $msgId = $this->needsInfoMessageId((int) $rider->getId());

        $client->request('POST', '/messages/'.$msgId.'/reply', [
            'body' => 'Confirmed — loose gravel for the last 200m.',
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/messages');

        $this->em()->clear();
        /** @var Submission $reloaded */
        $reloaded = $this->em()->find(Submission::class, $sub->getId());
        self::assertSame(SubmissionStatus::Pending, $reloaded->getStatus());

        $replies = $this->riderReplyMessagesFor((int) $curator->getId());
        self::assertCount(1, $replies);
        $reply = $replies[0];
        self::assertSame('rider', $reply->getSender());
        self::assertSame((int) $rider->getId(), $reply->getSenderId());
        self::assertSame('submission', $reply->getChannel());
        self::assertSame($sub->getId(), $reply->getRefId());
        self::assertSame('SUB-'.$sub->getId(), $reply->getRefLabel());
        self::assertSame('Confirmed — loose gravel for the last 200m.', $reply->getBodyText());

        // Every curator sees the reply on the queue row — not only the
        // decider's own inbox.
        $row = $this->findQueueRow((int) $sub->getId());
        self::assertSame('Confirmed — loose gravel for the last 200m.', $row['riderReply']);
    }

    // ── Too-late path ────────────────────────────────────────────────────────

    public function testSecondReplyIsTooLateAndWritesNoSecondMessage(): void
    {
        $client = static::createClient();
        $curator = $this->curator('twice');
        $rider = $this->rider('twice');
        $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Twice');

        $crawler = $this->loginAndVisitMessages($client, $rider);
        $token = $this->replyTokenFrom($crawler);
        $msgId = $this->needsInfoMessageId((int) $rider->getId());

        $client->request('POST', '/messages/'.$msgId.'/reply', [
            'body' => 'First reply.',
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/messages');
        self::assertCount(1, $this->riderReplyMessagesFor((int) $curator->getId()));

        $client->request('POST', '/messages/'.$msgId.'/reply', [
            'body' => 'Second reply — should not land.',
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/messages');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'no longer waiting');

        self::assertCount(1, $this->riderReplyMessagesFor((int) $curator->getId()));
    }

    // ── Ownership guard ──────────────────────────────────────────────────────

    public function testReplyOnAnotherUsersMessageIs404(): void
    {
        $client = static::createClient();
        $curator = $this->curator('intrude');
        $owner = $this->rider('owner');
        $intruder = $this->rider('intruder');
        $this->seedNeedsInfo($owner, $curator, 'Côte du Reply · Intruder');
        $msgId = $this->needsInfoMessageId((int) $owner->getId());
        // The intruder needs their own needs-info row to render a reply
        // form to read a valid (session-bound) token from — the token id is
        // fixed, not tied to which submission the form targets.
        $this->seedNeedsInfo($intruder, $curator, 'Côte du Reply · Intruder decoy');

        // The intruder's own session supplies a valid CSRF token — the 404
        // must come from the ownership check, not CSRF.
        $crawler = $this->loginAndVisitMessages($client, $intruder);
        $token = $this->replyTokenFrom($crawler);

        $client->request('POST', '/messages/'.$msgId.'/reply', [
            'body' => 'Trying to answer someone elses needs-info request.',
            '_token' => $token,
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->riderReplyMessagesFor((int) $curator->getId()));
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function testOverLongBodyFlashesErrorAndLeavesStateUnchanged(): void
    {
        $client = static::createClient();
        $curator = $this->curator('long');
        $rider = $this->rider('long');
        $sub = $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Too long');

        $crawler = $this->loginAndVisitMessages($client, $rider);
        $token = $this->replyTokenFrom($crawler);
        $msgId = $this->needsInfoMessageId((int) $rider->getId());

        $client->request('POST', '/messages/'.$msgId.'/reply', [
            'body' => str_repeat('x', 2001),
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/messages');

        self::assertCount(0, $this->riderReplyMessagesFor((int) $curator->getId()));
        $this->em()->clear();
        /** @var Submission $reloaded */
        $reloaded = $this->em()->find(Submission::class, $sub->getId());
        self::assertSame(SubmissionStatus::NeedsInfo, $reloaded->getStatus());
    }

    public function testEmptyBodyFlashesRequiredErrorAndLeavesStateUnchanged(): void
    {
        $client = static::createClient();
        $curator = $this->curator('empty');
        $rider = $this->rider('empty');
        $sub = $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Empty');

        $crawler = $this->loginAndVisitMessages($client, $rider);
        $token = $this->replyTokenFrom($crawler);
        $msgId = $this->needsInfoMessageId((int) $rider->getId());

        $client->request('POST', '/messages/'.$msgId.'/reply', [
            'body' => '   ',
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/messages');

        self::assertCount(0, $this->riderReplyMessagesFor((int) $curator->getId()));
        $this->em()->clear();
        /** @var Submission $reloaded */
        $reloaded = $this->em()->find(Submission::class, $sub->getId());
        self::assertSame(SubmissionStatus::NeedsInfo, $reloaded->getStatus());
    }

    // ── The rider's own side of the conversation ─────────────────────────────

    /**
     * The reply has to come back to the rider who wrote it, in the card
     * holding the question it answers.
     *
     * It is addressed to the deciding curator, so a recipient-only inbox
     * showed the rider the question and nothing else — their answer appeared
     * to have gone nowhere. And listed as its own row it landed ABOVE the
     * question (newest first), reading as two unrelated events rather than
     * one exchange.
     */
    public function testTheRidersAnswerRendersInsideTheQuestionsCard(): void
    {
        $client = static::createClient();
        $curator = $this->curator('mine');
        $rider = $this->rider('mine');
        $sub = $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Own thread');

        $crawler = $this->loginAndVisitMessages($client, $rider);
        $token = $this->replyTokenFrom($crawler);
        $msgId = $this->needsInfoMessageId((int) $rider->getId());

        $client->request('POST', '/messages/'.$msgId.'/reply', [
            'body' => 'It is the tap by the second bench.',
            '_token' => $token,
        ]);

        $crawler = $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('#msg-'.$msgId);
        self::assertSame(1, $card->count());
        self::assertStringContainsString('It is the tap by the second bench.', $card->filter('.msg-answer')->text());
        // One card for the exchange, not two rows.
        self::assertSame(0, $crawler->filter('.msg-row.msg-mine')->count());
        // Answered, so nothing left to reply to.
        self::assertSame(0, $card->filter('form.msg-reply')->count());
        // The subject links back to the contribution it is about.
        self::assertStringEndsWith('/profile#sub-'.$sub->getId(), (string) $card->filter('a.msg-subject')->attr('href'));
    }

    /**
     * The lead ("a curator needs more information about X … you can reply
     * below") is scaffolding around a question. With the question there, it
     * repeats the card back at the reader, so it only stands in for rows from
     * before the note was required.
     */
    public function testTheBoilerplateGivesWayToTheQuestionItself(): void
    {
        $client = static::createClient();
        $curator = $this->curator('lead');
        $rider = $this->rider('lead');
        $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Lead');

        $crawler = $this->loginAndVisitMessages($client, $rider);
        $card = $crawler->filter('#msg-'.$this->needsInfoMessageId((int) $rider->getId()));

        self::assertStringContainsString('Can you confirm the surface?', $card->text());
        self::assertStringNotContainsString('needs more information about', $card->filter('.msg-body')->text());
    }

    /**
     * A waiting question must be reachable from the contribution it is about.
     * The status chip alone was the whole of what the rider saw.
     */
    public function testTheSubmissionRowLinksToTheWaitingQuestion(): void
    {
        $client = static::createClient();
        $curator = $this->curator('profile-link');
        $rider = $this->rider('profile-link');
        $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Profile link');
        $msgId = $this->needsInfoMessageId((int) $rider->getId());

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $answer = $crawler->filter('a.item-answer');
        self::assertSame(1, $answer->count(), 'A needs-info row must offer a way to answer.');
        self::assertStringEndsWith('/messages#msg-'.$msgId, (string) $answer->attr('href'));
    }

    /**
     * And once answered, the row shows the answer — the same line the
     * curator's desk shows as "Rider replied".
     */
    public function testTheSubmissionRowShowsTheRidersOwnReply(): void
    {
        $client = static::createClient();
        $curator = $this->curator('profile-reply');
        $rider = $this->rider('profile-reply');
        $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Profile reply');

        $crawler = $this->loginAndVisitMessages($client, $rider);
        $token = $this->replyTokenFrom($crawler);
        $msgId = $this->needsInfoMessageId((int) $rider->getId());
        $client->request('POST', '/messages/'.$msgId.'/reply', [
            'body' => 'Gravel, and the gate is open.',
            '_token' => $token,
        ]);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Gravel, and the gate is open.', $crawler->filter('.item-reply')->text());
        // Back in the queue, so there is no question left to answer.
        self::assertSame(0, $crawler->filter('a.item-answer')->count());
    }

    /**
     * The desk lists needs-info rows with a "review on the map" link, so that
     * link has to land on the submission.
     *
     * The map layer leaves needs-info pins out — they wait on the rider, not
     * on a curator — but the layer rule was applied to the deep link too, so
     * `/map?pending=<id>` rendered a map with no such pin and the place
     * underneath opened its ordinary drawer. The submission looked lost.
     */
    public function testTheMapDeepLinkStillReachesANeedsInfoSubmission(): void
    {
        $queue = $this->queue();
        $curator = $this->curator('deeplink');
        $rider = $this->rider('deeplink');
        $sub = $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Deep link');
        $id = (int) $sub->getId();

        $ids = static fn (array $rows): array => array_map(static fn (array $r): int => $r['id'], $rows);

        // Not in the general layer…
        self::assertNotContains($id, $ids($queue->pendingForMap(ModerationScope::global())));
        // …but served when it is the one asked for, and marked as waiting.
        $focused = $queue->pendingForMap(ModerationScope::global(), $id);
        self::assertContains($id, $ids($focused));

        $row = array_values(array_filter($focused, static fn (array $r): bool => $id === $r['id']))[0];
        self::assertSame('needs_info', $row['status']);
        self::assertSame('Can you confirm the surface?', $row['asked']);
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    public function testBadCsrfTokenIsForbidden(): void
    {
        $client = static::createClient();
        $curator = $this->curator('csrf');
        $rider = $this->rider('csrf');
        $this->seedNeedsInfo($rider, $curator, 'Côte du Reply · Bad CSRF');
        $msgId = $this->needsInfoMessageId((int) $rider->getId());

        $client->loginUser($rider);
        $client->request('POST', '/messages/'.$msgId.'/reply', [
            'body' => 'Hello',
            '_token' => 'not-a-real-token',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->riderReplyMessagesFor((int) $curator->getId()));
    }
}
