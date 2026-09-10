<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Moderation\RouteModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task 4 (moderation-feedback spec M2/M11): route approve/reject/retire and
 * correction resolve/dismiss decisions message the proposer/suggester —
 * atomically with the decision — and the correction note enforces the shared
 * 2000-character cap (M11).
 */
final class RouteDecisionMessagesTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(): RouteModerationService
    {
        return static::getContainer()->get(RouteModerationService::class);
    }

    private function curator(): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('curator-'.uniqid('', true).'@route-msg.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function proposer(): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('proposer-'.uniqid('', true).'@route-msg.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function route(ItemState $state, ?int $proposedBy): RecommendedRoute
    {
        $em = $this->em();
        $r = (new RecommendedRoute())->setName('Côte du Message · Route')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:route-msg-'.bin2hex(random_bytes(8)))->setRegionId(1)
            ->setProposedBy($proposedBy);
        $em->persist($r);
        $em->flush();

        return $r;
    }

    /** @return list<UserMessage> */
    private function messagesFor(int $userId): array
    {
        /** @var list<UserMessage> $rows */
        // Ordered, not just fetched. An unordered findBy() whose result is then
        // read positionally is the shape that made ThirdPartyReportTest fail
        // once in a full-suite run and never again (2026-08-09): Postgres is
        // free to return rows in any order, and it agreed with the assertion
        // for months first. Every case here happens to assert exactly one row
        // today — this is what keeps the second one from reopening the bug.
        $rows = $this->em()->getRepository(UserMessage::class)->findBy(['userId' => $userId], ['id' => 'ASC']);

        return $rows;
    }

    public function testApproveWritesOneMessageToTheProposer(): void
    {
        $proposer = $this->proposer();
        $route = $this->route(ItemState::Submitted, (int) $proposer->getId());

        $this->service()->approve((int) $route->getId(), $this->curator());

        $rows = $this->messagesFor((int) $proposer->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame(UserMessageKind::RouteApproved, $m->getKind());
        self::assertSame('system', $m->getSender());
        self::assertNull($m->getSenderId());
        self::assertSame('route', $m->getChannel());
        self::assertSame($route->getId(), $m->getRefId());
        self::assertSame('Côte du Message · Route', $m->getRefLabel());
        self::assertSame('messages.body.route_approved', $m->getBodyKey());
        self::assertSame(['%name%' => 'Côte du Message · Route'], $m->getBodyParams());
        self::assertNull($m->getBodyText()); // approve carries no note
    }

    public function testRejectWritesOneMessageWithNoteAsBodyText(): void
    {
        $proposer = $this->proposer();
        $route = $this->route(ItemState::Submitted, (int) $proposer->getId());

        $this->service()->reject((int) $route->getId(), $this->curator(), 'Duplicate of an existing loop.');

        $rows = $this->messagesFor((int) $proposer->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame(UserMessageKind::RouteRejected, $m->getKind());
        self::assertSame('route', $m->getChannel());
        self::assertSame($route->getId(), $m->getRefId());
        self::assertSame('Côte du Message · Route', $m->getRefLabel());
        self::assertSame('messages.body.route_rejected', $m->getBodyKey());
        self::assertSame('Duplicate of an existing loop.', $m->getBodyText());
    }

    public function testRetireWritesOneMessageWithNoteAsBodyText(): void
    {
        $proposer = $this->proposer();
        $route = $this->route(ItemState::Verified, (int) $proposer->getId());

        $this->service()->retire((int) $route->getId(), $this->curator(), 'Superseded by a better loop.');

        $rows = $this->messagesFor((int) $proposer->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame(UserMessageKind::RouteRetired, $m->getKind());
        self::assertSame('route', $m->getChannel());
        self::assertSame($route->getId(), $m->getRefId());
        self::assertSame('messages.body.route_retired', $m->getBodyKey());
        self::assertSame('Superseded by a better loop.', $m->getBodyText());
    }

    public function testApprovingAnImportedRouteWritesNoMessage(): void
    {
        $route = $this->route(ItemState::Submitted, null); // proposedBy null — imported

        $this->service()->approve((int) $route->getId(), $this->curator());

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_message',
        ));
    }

    public function testResolveSuggestionDoneMessagesTheSuggesterWithTheRouteName(): void
    {
        $route = $this->route(ItemState::Verified, null);
        $suggester = $this->proposer();
        $s = new RouteSuggestion((int) $route->getId(), (int) $suggester->getId(), RouteSuggestionReason::BrokenTrack, 'Gate blocks the path.');
        $this->em()->persist($s);
        $this->em()->flush();

        $this->service()->resolveSuggestion((int) $s->getId(), RouteSuggestionStatus::Done, $this->curator());

        $rows = $this->messagesFor((int) $suggester->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame(UserMessageKind::CorrectionDone, $m->getKind());
        self::assertSame('correction', $m->getChannel());
        self::assertSame($s->getId(), $m->getRefId());
        self::assertSame('Côte du Message · Route', $m->getRefLabel());
        self::assertSame('messages.body.correction_done', $m->getBodyKey());
        self::assertSame(['%name%' => 'Côte du Message · Route'], $m->getBodyParams());
    }

    public function testResolveSuggestionDismissedMessagesTheSuggester(): void
    {
        $route = $this->route(ItemState::Verified, null);
        $suggester = $this->proposer();
        $s = new RouteSuggestion((int) $route->getId(), (int) $suggester->getId(), RouteSuggestionReason::Other, 'Not sure this is right.');
        $this->em()->persist($s);
        $this->em()->flush();

        $this->service()->resolveSuggestion((int) $s->getId(), RouteSuggestionStatus::Dismissed, $this->curator());

        $rows = $this->messagesFor((int) $suggester->getId());
        self::assertCount(1, $rows);
        self::assertSame(UserMessageKind::CorrectionDismissed, $rows[0]->getKind());
        self::assertSame('messages.body.correction_dismissed', $rows[0]->getBodyKey());
    }

    /**
     * M11: the route-drawer correction note enforces the shared 2000-character
     * cap server-side (there is no form-validation layer here — it's a JSON
     * API endpoint) — an over-long note is rejected with 422 and nothing is
     * persisted.
     */
    public function testSuggestOverTwoThousandCharacterNoteIsRejected(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $route = $this->route(ItemState::Verified, null);

        $rider = (new User())->setEmail('rider-'.uniqid('', true).'@route-msg.test')->setDisplayName('R');
        $rider->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $rider->setPassword($hasher->hashPassword($rider, 'password1234'));
        $em->persist($rider);
        $em->flush();

        $client->loginUser($rider);
        $client->request('GET', '/routes/'.$route->getId().'/community');
        $token = json_decode((string) $client->getResponse()->getContent(), true)['token'];

        $client->request('POST', '/routes/'.$route->getId().'/suggest', [
            'reason' => 'broken-track',
            'note' => str_repeat('x', 2001),
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM route_suggestion WHERE route_id = :r', ['r' => $route->getId()],
        ));
    }
}
