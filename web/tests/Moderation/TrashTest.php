<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\AdminActionLog;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\RouteModerationService;
use App\Moderation\SubmissionQueue;
use App\Moderation\TrashActions;
use App\Moderation\TrashBlockedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task 11 (moderation-feedback spec M9): Trash is an immediate, permanent
 * hard delete for spam/abusive contributions — audited content-free (never
 * the rider's body/note text), and it never writes a UserMessage (trashing
 * must never feed spam back to the spammer).
 */
final class TrashTest extends WebTestCase
{
    private const string SPAM_BODY = 'Buy cheap watches now at spam-example.test — limited offer!!!';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function moderation(): ModerationService
    {
        return static::getContainer()->get(ModerationService::class);
    }

    private function routeModeration(): RouteModerationService
    {
        return static::getContainer()->get(RouteModerationService::class);
    }

    private function curator(): User
    {
        $c = static::getContainer();
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $em = $c->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('curator-'.uniqid('', true).'@trash.test')->setDisplayName('C');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword($hasher->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function rider(string $suffix): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('rider-'.$suffix.'-'.uniqid('', true).'@trash.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function seedSubmission(int $userId, SubmissionStatus $status = SubmissionStatus::Pending): Submission
    {
        $em = $this->em();
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($userId)
            ->setTitle('Spam submission')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges(['note' => ['was' => null, 'now' => self::SPAM_BODY]])
            ->setPayload(['note' => self::SPAM_BODY])
            ->setStatus($status);
        $em->persist($sub);
        $em->flush();

        return $sub;
    }

    /**
     * The item intake creates for a submission, wired the way
     * CatalogContributionService wires it (source_ref = sub:<id>), so the
     * trash-side assertions exercise the real shape.
     *
     * @param array<string, mixed> $attributes
     */
    private function seedItemFor(Submission $sub, ItemState $state, array $attributes = []): Item
    {
        $em = $this->em();
        $item = (new Item())->setLetter($sub->getLetter())->setName($sub->getTitle())
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode('BE')->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('sub:'.(string) $sub->getId())->setAttributes($attributes);
        $em->persist($item);
        $em->flush();
        $sub->setItemId((int) $item->getId());
        $em->flush();

        return $item;
    }

    private function route(ItemState $state, ?int $proposedBy = null): RecommendedRoute
    {
        $em = $this->em();
        $r = (new RecommendedRoute())->setName('Trash test route')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:trash-'.bin2hex(random_bytes(8)))->setRegionId(1)->setProposedBy($proposedBy);
        $em->persist($r);
        $em->flush();

        return $r;
    }

    private function suggestion(int $routeId, int $userId): RouteSuggestion
    {
        $em = $this->em();
        $s = new RouteSuggestion($routeId, $userId, RouteSuggestionReason::Other, self::SPAM_BODY, [['start' => 0.1, 'end' => 0.4]]);
        $em->persist($s);
        $em->flush();

        return $s;
    }

    private function adminLogFor(string $action): ?AdminActionLog
    {
        /** @var AdminActionLog|null $row */
        $row = $this->em()->getRepository(AdminActionLog::class)->findOneBy(['action' => $action], ['id' => 'DESC']);

        return $row;
    }

    private function adminLogCountFor(string $action): int
    {
        return \count($this->em()->getRepository(AdminActionLog::class)->findBy(['action' => $action]));
    }

    /** @return list<UserMessage> */
    private function messagesFor(int $userId): array
    {
        /** @var list<UserMessage> $rows */
        $rows = $this->em()->getRepository(UserMessage::class)->findBy(['userId' => $userId]);

        return $rows;
    }

    /**
     * `moderate-trash` / `route-trash` are session-bound CSRF token ids (not
     * in csrf.yaml's stateless_token_ids — same shape as moderate-message /
     * route-suggestion), so a real token has to be read off a rendered form,
     * the way a browser would (CuratorMessageTest's messageToken() pattern).
     * The token is keyed to (session, token id) only, not to the row id, so
     * it can legally be reused against a different row of the same kind.
     */
    private function submissionTrashToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/moderate');

        return (string) $crawler->filter('.trash-confirm input[name="_token"]')->first()->attr('value');
    }

    private function correctionTrashTokenFromRoutesIndex(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/moderate/routes');

        return (string) $crawler->filter('.trash-confirm input[name="_token"]')->first()->attr('value');
    }

    private function proposalTrashToken(KernelBrowser $client, int $submittedRouteId): string
    {
        $crawler = $client->request('GET', '/moderate/routes/'.$submittedRouteId);

        return (string) $crawler->filter('.trash-confirm-proposal input[name="_token"]')->attr('value');
    }

    // ── Service layer: content-free audit, no message, state guardrail ─────────

    public function testTrashSubmissionDeletesRowWritesContentFreeAuditAndNoMessage(): void
    {
        $rider = $this->rider('sub');
        $sub = $this->seedSubmission((int) $rider->getId());
        $subId = (int) $sub->getId();
        $curator = $this->curator();

        $this->moderation()->trashSubmission($subId, $curator);

        // Doctrine nulls a GeneratedValue identifier on the entity instance
        // once its row is actually deleted, so the id must be captured
        // before the call, not read off $sub afterward.
        $this->em()->clear();
        self::assertNull($this->em()->find(Submission::class, $subId));

        $log = $this->adminLogFor(TrashActions::TrashSubmission);
        self::assertNotNull($log);
        self::assertSame((int) $curator->getId(), $log->getActor()?->getId());
        self::assertNull($log->getTargetUser());
        self::assertStringContainsString('SUB-'.$subId, (string) $log->getNote());
        self::assertStringNotContainsString(self::SPAM_BODY, (string) $log->getNote());

        self::assertCount(0, $this->messagesFor((int) $rider->getId()));
    }

    /**
     * Trashing a NEW-place submission takes its unapproved item with it.
     *
     * Intake creates the item up front in state `submitted` so the pending pin
     * reaches the curator's map. Trash used to delete only the submission,
     * stranding that item for good — `source_ref` pointing at a submission that
     * no longer exists, and nothing anywhere to sweep it (owner-reported
     * 2026-08-03).
     */
    public function testTrashSubmissionAlsoRemovesItsUnapprovedNewItem(): void
    {
        $rider = $this->rider('sub-newitem');
        $sub = $this->seedSubmission((int) $rider->getId());
        $item = $this->seedItemFor($sub, ItemState::Submitted);
        $subId = (int) $sub->getId();
        $itemId = (int) $item->getId();

        $this->moderation()->trashSubmission($subId, $this->curator());

        $this->em()->clear();
        self::assertNull($this->em()->find(Submission::class, $subId));
        self::assertNull($this->em()->find(Item::class, $itemId), 'the unapproved item goes with the submission');
    }

    /**
     * The safety rail. Once a new item has been APPROVED it is a real catalogue
     * entry that riders may since have confirmed, photographed or edited —
     * trashing the submission it arrived on must never take that with it.
     */
    public function testTrashSubmissionLeavesAnAlreadyApprovedItemAlone(): void
    {
        $rider = $this->rider('sub-approved-item');
        $sub = $this->seedSubmission((int) $rider->getId(), SubmissionStatus::Approved);
        $item = $this->seedItemFor($sub, ItemState::Unverified);
        $subId = (int) $sub->getId();
        $itemId = (int) $item->getId();

        $this->moderation()->trashSubmission($subId, $this->curator());

        $this->em()->clear();
        self::assertNull($this->em()->find(Submission::class, $subId));
        self::assertNotNull($this->em()->find(Item::class, $itemId), 'an approved catalogue item survives Trash');
    }

    /**
     * An EDIT applies on approve, so a pending edit has changed nothing yet:
     * trashing it discards the proposal and the item keeps the value it had.
     */
    public function testTrashingAnEditLeavesTheEditedItemAndItsValueIntact(): void
    {
        $rider = $this->rider('sub-edit');
        $sub = $this->seedSubmission((int) $rider->getId());
        $sub->setType(SubmissionType::Edit)
            ->setChanges(['effort' => ['was' => 'Tough', 'now' => 'Very steep']]);
        $item = $this->seedItemFor($sub, ItemState::Verified, ['effort' => 'Tough']);
        $this->em()->flush();
        $subId = (int) $sub->getId();
        $itemId = (int) $item->getId();

        $this->moderation()->trashSubmission($subId, $this->curator());

        $this->em()->clear();
        self::assertNull($this->em()->find(Submission::class, $subId));
        $still = $this->em()->find(Item::class, $itemId);
        self::assertNotNull($still, 'the edited item survives');
        self::assertSame('Tough', $still->getAttributes()['effort'] ?? null, 'and keeps its pre-edit value');
    }

    /**
     * A trashed submission still shows up under "Already settled", rebuilt from
     * the content-free audit — a curator who trashes something and then cannot
     * find it anywhere reasonably wonders whether it worked (owner-reported
     * 2026-08-03). What it must NOT carry is the title: preserving that would
     * preserve the spam Trash exists to destroy.
     */
    public function testTrashedSubmissionsAppearInTheSettledHistoryWithoutTheirContent(): void
    {
        $rider = $this->rider('sub-history');
        $sub = $this->seedSubmission((int) $rider->getId());
        $subId = (int) $sub->getId();
        $curator = $this->curator();

        $this->moderation()->trashSubmission($subId, $curator);
        $this->em()->clear();

        $queue = static::getContainer()->get(SubmissionQueue::class);
        $scope = static::getContainer()->get(ModerationScopeProvider::class)->scopeFor($curator);
        $rows = $queue->history($scope);

        $trashed = array_values(array_filter($rows, static fn (array $r): bool => 'trashed' === $r['status']));
        self::assertNotEmpty($trashed, 'the trash shows in the history');
        self::assertSame('SUB-'.$subId, $trashed[0]['title']);
        self::assertSame($curator->getDisplayName(), $trashed[0]['decidedBy']);
        foreach ($rows as $row) {
            self::assertStringNotContainsString(self::SPAM_BODY, json_encode($row, JSON_THROW_ON_ERROR), 'no trashed content is resurrected');
            self::assertStringNotContainsString('Spam submission', (string) $row['title']);
        }
    }

    public function testTrashSubmissionWorksRegardlessOfStatus(): void
    {
        $rider = $this->rider('sub-rejected');
        $sub = $this->seedSubmission((int) $rider->getId(), SubmissionStatus::Rejected);
        $subId = (int) $sub->getId();

        $this->moderation()->trashSubmission($subId, $this->curator());

        $this->em()->clear();
        self::assertNull($this->em()->find(Submission::class, $subId));
    }

    public function testTrashSuggestionDeletesRowIncludingSegmentsWritesContentFreeAuditAndNoMessage(): void
    {
        $rider = $this->rider('corr');
        $route = $this->route(ItemState::Verified);
        $s = $this->suggestion((int) $route->getId(), (int) $rider->getId());
        $sId = (int) $s->getId();
        $routeId = (int) $route->getId();
        $curator = $this->curator();

        $this->routeModeration()->trashSuggestion($sId, $curator);

        $this->em()->clear();
        // The row (and its `segments` JSON column) is entirely gone.
        self::assertNull($this->em()->find(RouteSuggestion::class, $sId));

        $log = $this->adminLogFor(TrashActions::TrashCorrection);
        self::assertNotNull($log);
        self::assertStringContainsString((string) $sId, (string) $log->getNote());
        self::assertStringContainsString((string) $routeId, (string) $log->getNote());
        self::assertStringNotContainsString(self::SPAM_BODY, (string) $log->getNote());

        self::assertCount(0, $this->messagesFor((int) $rider->getId()));
    }

    public function testTrashProposalInSubmittedDeletesRowWritesContentFreeAudit(): void
    {
        $rider = $this->rider('prop-submitted');
        $route = $this->route(ItemState::Submitted, (int) $rider->getId());
        $routeId = (int) $route->getId();
        $curator = $this->curator();

        $this->routeModeration()->trashProposal($routeId, $curator);

        $this->em()->clear();
        self::assertNull($this->em()->find(RecommendedRoute::class, $routeId));

        $log = $this->adminLogFor(TrashActions::TrashRouteProposal);
        self::assertNotNull($log);
        self::assertStringContainsString((string) $routeId, (string) $log->getNote());
        self::assertStringContainsString('submitted', (string) $log->getNote());

        self::assertCount(0, $this->messagesFor((int) $rider->getId()));
    }

    /**
     * The guardrail's legal states are `submitted` OR `rejected` — this
     * covers the second branch, which (unlike `submitted`) has no queue/
     * detail page surface today (detail() 404s a rejected route), so it can
     * only be reached at the service layer.
     */
    public function testTrashProposalInRejectedDeletesRow(): void
    {
        $route = $this->route(ItemState::Rejected);
        $routeId = (int) $route->getId();

        $this->routeModeration()->trashProposal($routeId, $this->curator());

        $this->em()->clear();
        self::assertNull($this->em()->find(RecommendedRoute::class, $routeId));
    }

    public function testTrashProposalBlockedForAnActiveRouteThrowsAndWritesNoAudit(): void
    {
        $route = $this->route(ItemState::Unverified);
        $before = $this->adminLogCountFor(TrashActions::TrashRouteProposal);

        try {
            $this->routeModeration()->trashProposal((int) $route->getId(), $this->curator());
            self::fail('Expected TrashBlockedException.');
        } catch (TrashBlockedException) {
            // expected — the guardrail fails BEFORE any audit row is written.
        }

        $this->em()->clear();
        self::assertNotNull($this->em()->find(RecommendedRoute::class, $route->getId()));
        self::assertSame($before, $this->adminLogCountFor(TrashActions::TrashRouteProposal));
    }

    public function testTrashProposalBlockedForARetiredRoute(): void
    {
        $route = $this->route(ItemState::Retired);

        $this->expectException(TrashBlockedException::class);
        $this->routeModeration()->trashProposal((int) $route->getId(), $this->curator());
    }

    // ── HTTP layer: routes, CSRF, redirect-after-POST, access control ──────────

    public function testModerateTrashEndpointDeletesSubmissionWithSuccessFlash(): void
    {
        $client = static::createClient();
        $rider = $this->rider('http-sub');
        $sub = $this->seedSubmission((int) $rider->getId());

        $client->loginUser($this->curator());
        $token = $this->submissionTrashToken($client);

        $client->request('POST', '/moderate/trash', [
            'kind' => 'submission',
            'confirm' => 'DELETE',
            'id' => (string) $sub->getId(),
            '_token' => $token,
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Trashed');

        $this->em()->clear();
        self::assertNull($this->em()->find(Submission::class, $sub->getId()));
    }

    public function testModerateTrashEndpointRiderIsForbidden(): void
    {
        $client = static::createClient();
        $rider = $this->rider('http-forbidden');
        $sub = $this->seedSubmission((int) $rider->getId());

        // The `^/moderate` access-control rule blocks this before the
        // controller's own CSRF check ever runs, so no real token is needed.
        $client->loginUser($rider);
        $client->request('POST', '/moderate/trash', [
            'kind' => 'submission',
            'confirm' => 'DELETE',
            'id' => (string) $sub->getId(),
            '_token' => 'irrelevant',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Submission::class, $sub->getId()));
    }

    public function testModerateTrashEndpointBadCsrfTokenIsForbidden(): void
    {
        $client = static::createClient();
        $rider = $this->rider('http-badcsrf');
        $sub = $this->seedSubmission((int) $rider->getId());

        $client->loginUser($this->curator());
        $client->request('POST', '/moderate/trash', [
            'kind' => 'submission',
            'confirm' => 'DELETE',
            'id' => (string) $sub->getId(),
            '_token' => 'not-a-real-token',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Submission::class, $sub->getId()));
    }

    public function testRouteTrashEndpointDeletesCorrectionWithSuccessFlash(): void
    {
        $client = static::createClient();
        $rider = $this->rider('http-corr');
        $route = $this->route(ItemState::Verified);
        $s = $this->suggestion((int) $route->getId(), (int) $rider->getId());

        $client->loginUser($this->curator());
        $token = $this->correctionTrashTokenFromRoutesIndex($client);

        $client->request('POST', '/moderate/routes/trash', [
            'kind' => 'correction',
            'confirm' => 'DELETE',
            'id' => (string) $s->getId(),
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/moderate/routes');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Trashed');

        $this->em()->clear();
        self::assertNull($this->em()->find(RouteSuggestion::class, $s->getId()));
    }

    public function testRouteTrashEndpointDeletesASubmittedProposalWithSuccessFlash(): void
    {
        $client = static::createClient();
        $rider = $this->rider('http-prop');
        $route = $this->route(ItemState::Submitted, (int) $rider->getId());

        $client->loginUser($this->curator());
        $token = $this->proposalTrashToken($client, (int) $route->getId());

        $client->request('POST', '/moderate/routes/trash', [
            'kind' => 'proposal',
            'confirm' => 'DELETE',
            'id' => (string) $route->getId(),
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/moderate/routes');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Trashed');

        $this->em()->clear();
        self::assertNull($this->em()->find(RecommendedRoute::class, $route->getId()));
    }

    /**
     * An active (unverified/verified) route's detail page never renders a
     * Trash button (the template gates on state), so the token here is
     * legally minted from a throwaway *submitted* route's detail page — the
     * session-bound token isn't tied to the row id — and then replayed
     * against the active route's id to exercise the controller's own
     * guardrail handling end to end.
     */
    public function testRouteTrashEndpointBlocksAnActiveProposalWithDangerFlashAndNoDelete(): void
    {
        $client = static::createClient();
        $active = $this->route(ItemState::Unverified);
        $warmup = $this->route(ItemState::Submitted);

        $client->loginUser($this->curator());
        $token = $this->proposalTrashToken($client, (int) $warmup->getId());
        $before = $this->adminLogCountFor(TrashActions::TrashRouteProposal);

        $client->request('POST', '/moderate/routes/trash', [
            'kind' => 'proposal',
            'confirm' => 'DELETE',
            'id' => (string) $active->getId(),
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/moderate/routes');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'submitted or rejected');

        $this->em()->clear();
        self::assertNotNull($this->em()->find(RecommendedRoute::class, $active->getId()));
        self::assertSame($before, $this->adminLogCountFor(TrashActions::TrashRouteProposal));
    }

    public function testRouteTrashEndpointRiderIsForbidden(): void
    {
        $client = static::createClient();
        $rider = $this->rider('http-route-forbidden');
        $route = $this->route(ItemState::Submitted);

        $client->loginUser($rider);
        $client->request('POST', '/moderate/routes/trash', [
            'kind' => 'proposal',
            'confirm' => 'DELETE',
            'id' => (string) $route->getId(),
            '_token' => 'irrelevant',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(RecommendedRoute::class, $route->getId()));
    }

    public function testRouteTrashEndpointBadCsrfTokenIsForbidden(): void
    {
        $client = static::createClient();
        $route = $this->route(ItemState::Submitted);

        $client->loginUser($this->curator());
        $client->request('POST', '/moderate/routes/trash', [
            'kind' => 'proposal',
            'confirm' => 'DELETE',
            'id' => (string) $route->getId(),
            '_token' => 'not-a-real-token',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(RecommendedRoute::class, $route->getId()));
    }

    /**
     * Moderator-rulebook guard: Trash requires the literal typed DELETE.
     * Without it nothing is deleted and no trash record is written — a
     * cancelled/unconfirmed Trash leaves zero trace.
     */
    public function testTrashStillNeedsAValidTokenAndAPost(): void
    {
        /* The typed DELETE is gone (owner 2026-08-12): opening the panel and
           pressing Trash inside it are the two deliberate acts, and a word a
           curator types fifty times is a reflex rather than a check.

           What must still hold is what actually protects the row — a valid CSRF
           token and a POST. A forged form or a link somebody was sent must
           destroy nothing. */
        $client = static::createClient();
        $rider = $this->rider('http-noconfirm');
        $sub = $this->seedSubmission((int) $rider->getId());

        $client->loginUser($this->curator());

        $client->catchExceptions(true);
        $client->request('POST', '/moderate/trash', [
            'kind' => 'submission', 'id' => (string) $sub->getId(), '_token' => 'not-a-token',
        ]);
        self::assertResponseStatusCodeSame(403, 'a bad token destroys nothing');

        $client->request('GET', '/moderate/trash?kind=submission&id='.$sub->getId());
        self::assertResponseStatusCodeSame(405, 'and neither does a GET');

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Submission::class, $sub->getId()));
    }

    public function testRouteTrashStillNeedsAValidToken(): void
    {
        // Same rule as the item desk: the token and the method are what protect
        // the row, now that the typed word is gone.
        $client = static::createClient();
        $route = $this->route(ItemState::Submitted);

        $client->loginUser($this->curator());

        $client->request('POST', '/moderate/routes/trash', [
            'kind' => 'proposal',
            'id' => (string) $route->getId(),
            '_token' => 'not-a-token',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(RecommendedRoute::class, $route->getId()));
    }
}
