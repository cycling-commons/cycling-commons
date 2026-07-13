<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Moderation\ModerationService;
use App\Moderation\RouteModerationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Final-review fix (moderation-feedback spec M1/M10): `user_message.user_id`
 * carries a real FK (ON DELETE CASCADE) to `users`, but the author/proposer
 * columns of the entities a decision messages — `submission.user_id`,
 * `route_suggestion.user_id`, `recommended_route.proposed_by` — carry NO FK
 * and survive account deletion (no deletion hooks decouple them). A decision
 * on a submission/suggestion/route whose author has since deleted their
 * account must still succeed and must simply skip the now-undeliverable
 * inbox copy, never 500 on the FK violation and never strand the decision.
 */
final class DeletedRecipientTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function moderationService(): ModerationService
    {
        return static::getContainer()->get(ModerationService::class);
    }

    private function routeModerationService(): RouteModerationService
    {
        return static::getContainer()->get(RouteModerationService::class);
    }

    private function user(string $suffix): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('deleted-recipient-'.$suffix.'-'.uniqid('', true).'@moderation.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    /**
     * Hard-deletes a user row directly, bypassing the ORM — simulates an
     * account deletion that happened before the decision under test, without
     * needing a real account-deletion flow. Safe for a fresh test user: no
     * reset_password_request row, and admin_action_log/user_message FKs are
     * SET NULL/CASCADE respectively.
     */
    private function hardDeleteUser(int $userId): void
    {
        $this->db()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $userId]);
    }

    private function messageCountFor(int $userId): int
    {
        return \count($this->em()->getRepository(UserMessage::class)->findBy(['userId' => $userId]));
    }

    // ── ModerationService::decide ───────────────────────────────────────────

    public function testApprovingASubmissionWithDeletedAuthorSkipsTheMessage(): void
    {
        $author = $this->user('sub-author');
        $authorId = (int) $author->getId();
        $curator = $this->user('sub-curator');

        $em = $this->em();
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($authorId)
            ->setTitle('Côte du Deleted Recipient')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $em->persist($sub);
        $em->flush();

        $this->hardDeleteUser($authorId);

        $decided = $this->moderationService()->decide((int) $sub->getId(), 'approve', $curator, 'Looks good.');

        self::assertSame(SubmissionStatus::Approved, $decided->getStatus());
        self::assertSame(0, $this->messageCountFor($authorId));
    }

    // ── RouteModerationService::retire ──────────────────────────────────────

    public function testRetiringARouteWithDeletedProposerSkipsTheMessage(): void
    {
        $proposer = $this->user('route-proposer');
        $proposerId = (int) $proposer->getId();
        $curator = $this->user('route-curator');

        $em = $this->em();
        $route = (new RecommendedRoute())->setName('Côte du Deleted Recipient · Route')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setState(ItemState::Verified)->setSource(ItemSource::User)
            ->setSourceRef('user:deleted-recipient-'.bin2hex(random_bytes(8)))->setRegionId(1)
            ->setProposedBy($proposerId);
        $em->persist($route);
        $em->flush();

        $this->hardDeleteUser($proposerId);

        $retired = $this->routeModerationService()->retire((int) $route->getId(), $curator, 'Superseded.');

        self::assertSame(ItemState::Retired, $retired->getState());
        self::assertSame(0, $this->messageCountFor($proposerId));
    }

    // ── RouteModerationService::resolveSuggestion ───────────────────────────

    public function testResolvingASuggestionWithDeletedSuggesterSkipsTheMessage(): void
    {
        $suggester = $this->user('suggestion-author');
        $suggesterId = (int) $suggester->getId();
        $curator = $this->user('suggestion-curator');

        $em = $this->em();
        $route = (new RecommendedRoute())->setName('Côte du Deleted Recipient · Correction')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setState(ItemState::Verified)->setSource(ItemSource::User)
            ->setSourceRef('user:deleted-recipient-'.bin2hex(random_bytes(8)))->setRegionId(1);
        $em->persist($route);
        $em->flush();

        $suggestion = new RouteSuggestion((int) $route->getId(), $suggesterId, RouteSuggestionReason::BrokenTrack, 'Gate blocks the path.');
        $em->persist($suggestion);
        $em->flush();

        $this->hardDeleteUser($suggesterId);

        $resolved = $this->routeModerationService()->resolveSuggestion((int) $suggestion->getId(), RouteSuggestionStatus::Done, $curator);

        self::assertSame(RouteSuggestionStatus::Done, $resolved->getStatus());
        self::assertSame(0, $this->messageCountFor($suggesterId));
    }

    // ── Reply-path guard: deciding curator deleted ──────────────────────────

    /**
     * A rider answers a needs-info request whose deciding curator has since
     * deleted their own account (submission.decided_by is the same kind of
     * no-FK column). There is no curator left to deliver the reply to, so
     * this must flash the same "too late" outcome as an already-resolved
     * submission — never a 500, and the submission stays NeedsInfo for
     * another curator to pick up.
     */
    public function testReplyingAfterTheDecidingCuratorAccountIsDeletedFlashesTooLateInsteadOf500(): void
    {
        $client = static::createClient();
        $curator = $this->user('reply-curator');
        $curatorId = (int) $curator->getId();
        $rider = $this->user('reply-rider');

        $em = $this->em();
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId((int) $rider->getId())
            ->setTitle('Côte du Deleted Recipient · Reply')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $em->persist($sub);
        $em->flush();

        $this->moderationService()->decide((int) $sub->getId(), 'needs_info', $curator, 'Can you confirm the surface?');
        $em->clear();

        $this->hardDeleteUser($curatorId);

        /** @var UserMessage|null $needsInfoMessage */
        $needsInfoMessage = $em->getRepository(UserMessage::class)->findOneBy([
            'userId' => (int) $rider->getId(),
        ]);
        self::assertInstanceOf(UserMessage::class, $needsInfoMessage);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form.msg-reply input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/messages/'.$needsInfoMessage->getId().'/reply', [
            'body' => 'Confirmed — loose gravel for the last 200m.',
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/messages');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'no longer waiting');

        $em->clear();
        /** @var Submission $reloaded */
        $reloaded = $em->find(Submission::class, $sub->getId());
        self::assertSame(SubmissionStatus::NeedsInfo, $reloaded->getStatus());
        self::assertSame(0, $this->messageCountFor($curatorId));
    }
}
