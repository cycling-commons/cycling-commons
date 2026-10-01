<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Account\DataExportService;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Region;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\AdminActionLog;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationService;
use App\Moderation\OutOfScopeException;
use App\Moderation\RouteModerationService;
use App\Moderation\TrashActions;
use App\Moderation\TrashBin;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The curators' Trash is a bin (owner 2026-10-01): a trashed row and its
 * thread are kept TrashBin::TRASH_DAYS days, hidden everywhere but the Trash
 * list, restorable to exactly what they were, then purged. Legal hold
 * outlives the bin.
 *
 * @see docs/specs/moderation-and-contribution.md §6
 */
final class TrashBinTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string TITLE = 'Bin fixture fountain';
    private const string QUESTION = 'Which side of the square is it on?';
    private const string REPLY = 'North side, next to the bakery.';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function moderation(): ModerationService
    {
        return static::getContainer()->get(ModerationService::class);
    }

    private function routes(): RouteModerationService
    {
        return static::getContainer()->get(RouteModerationService::class);
    }

    private function bin(): TrashBin
    {
        return static::getContainer()->get(TrashBin::class);
    }

    private function messages(): MessageService
    {
        return static::getContainer()->get(MessageService::class);
    }

    private function curator(?int $regionId = null): User
    {
        $u = (new User())->setEmail('bin-curator-'.uniqid('', true).'@bin.test')->setDisplayName('Bin curator');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword('x');
        $this->em()->persist($u);
        $this->em()->flush();
        if (null !== $regionId) {
            $this->em()->persist(new ModeratorArea((int) $u->getId(), $regionId, null));
            $this->em()->flush();
        }

        return $u;
    }

    private function rider(): User
    {
        $u = (new User())->setEmail('bin-rider-'.uniqid('', true).'@bin.test')->setDisplayName('Bin rider');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setPassword('x');
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    private function region(string $country): Region
    {
        $region = (new Region())->setSlug('bin-'.uniqid())->setName('Bin region '.uniqid())->setCountryCode($country)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $this->em()->persist($region);
        $this->em()->flush();

        return $region;
    }

    /** A new-place submission waiting on the rider, its submitted pin and a question-and-answer thread. */
    private function newPlace(User $rider, User $curator, ?int $regionId = null): Submission
    {
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('A')->setUserId((int) $rider->getId())
            ->setTitle(self::TITLE)->setStatus(SubmissionStatus::NeedsInfo)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')->setRegionId($regionId)
            ->setChanges(['name' => ['was' => null, 'now' => self::TITLE]])->setPayload([]);
        $this->em()->persist($sub);
        $this->em()->flush();
        $item = (new Item())->setLetter('A')->setName(self::TITLE)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')->setRegionId($regionId)
            ->setState(ItemState::Submitted)->setSource(ItemSource::User)->setSourceRef('sub:'.(string) $sub->getId());
        $this->em()->persist($item);
        $this->em()->flush();
        $sub->setItemId((int) $item->getId());

        $id = (int) $sub->getId();
        $this->messages()->sendSystem((int) $rider->getId(), UserMessageKind::SubmissionNeedsInfo, 'submission', $id, 'SUB-'.$id, 'messages.body.submission_needs_info', ['%title%' => self::TITLE], self::QUESTION);
        $this->em()->flush();
        $this->messages()->sendRiderReply((int) $curator->getId(), (int) $rider->getId(), 'submission', $id, 'SUB-'.$id, self::REPLY);

        return $sub;
    }

    private function route(ItemState $state, ?int $proposedBy, ?int $regionId = null): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Bin route '.uniqid())
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:bin-'.bin2hex(random_bytes(8)))->setRegionId($regionId)->setProposedBy($proposedBy);
        $this->em()->persist($r);
        $this->em()->flush();

        return $r;
    }

    private function threadSize(string $channel, int $refId): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM user_message WHERE channel = ? AND ref_id = ?', [$channel, $refId]);
    }

    private function auditCount(string $action): int
    {
        return \count($this->em()->getRepository(AdminActionLog::class)->findBy(['action' => $action]));
    }

    // ── Restore ─────────────────────────────────────────────────────────────

    public function testRestoreBringsASubmissionBackExactlyAsItWas(): void
    {
        $rider = $this->rider();
        $curator = $this->curator();
        $sub = $this->newPlace($rider, $curator);
        $id = (int) $sub->getId();
        $itemId = (int) $sub->getItemId();
        // The rider had read the question: a restore must not make it new again.
        $this->messages()->markAllRead((int) $rider->getId());
        $before = $this->db()->fetchAllAssociative('SELECT id, user_id, read_at FROM user_message WHERE channel = ? AND ref_id = ? ORDER BY id', ['submission', $id]);

        $this->moderation()->trashSubmission($id, $curator);
        self::assertSame(0, $this->messages()->countFor((int) $rider->getId()), 'in the bin, the thread is in no inbox');
        $restoresBefore = $this->auditCount(TrashActions::RestoreSubmission);

        $this->moderation()->restoreSubmission($id, $curator);

        $this->em()->clear();
        $back = $this->em()->find(Submission::class, $id);
        self::assertSame(SubmissionStatus::NeedsInfo, $back?->getStatus());
        self::assertNull($back->getTrashedAt());
        self::assertNull($back->getTrashedBy());
        self::assertNull($back->getTrashedFrom());
        self::assertSame(ItemState::Submitted, $this->em()->find(Item::class, $itemId)?->getState(), 'the pending pin is back on the curators\' map');
        self::assertSame($before, $this->db()->fetchAllAssociative('SELECT id, user_id, read_at FROM user_message WHERE channel = ? AND ref_id = ? ORDER BY id', ['submission', $id]), 'the same thread, read state and all');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM user_message WHERE channel = ? AND ref_id = ? AND trashed_at IS NOT NULL', ['submission', $id]));
        self::assertSame(1, $this->messages()->countFor((int) $rider->getId()), 'the question is back in the rider\'s inbox');
        self::assertSame($restoresBefore + 1, $this->auditCount(TrashActions::RestoreSubmission), 'a restore is audited, content-free');
    }

    public function testRestoreBringsACorrectionAndAProposalBack(): void
    {
        $rider = $this->rider();
        $curator = $this->curator();
        $live = $this->route(ItemState::Verified, null);
        $correction = new RouteSuggestion((int) $live->getId(), (int) $rider->getId(), RouteSuggestionReason::Other, 'A note');
        $this->em()->persist($correction);
        $this->em()->flush();
        $correction->resolve(RouteSuggestionStatus::Dismissed, (int) $curator->getId());
        $this->em()->flush();
        $proposal = $this->route(ItemState::Rejected, (int) $rider->getId());
        $sId = (int) $correction->getId();
        $rId = (int) $proposal->getId();

        $this->routes()->trashSuggestion($sId, $curator);
        $this->routes()->trashProposal($rId, $curator);
        $this->routes()->restoreSuggestion($sId, $curator);
        $this->routes()->restoreProposal($rId, $curator);

        $this->em()->clear();
        self::assertSame(RouteSuggestionStatus::Dismissed, $this->em()->find(RouteSuggestion::class, $sId)?->getStatus());
        self::assertSame(ItemState::Rejected, $this->em()->find(RecommendedRoute::class, $rId)?->getState());
    }

    public function testRestoreOutsideTheCuratorsAreasIsRefused(): void
    {
        $inArea = $this->region('BE');
        $elsewhere = $this->region('NL');
        $rider = $this->rider();
        $global = $this->curator();
        $local = $this->curator((int) $inArea->getId());
        $sub = $this->newPlace($rider, $global, (int) $elsewhere->getId());
        $this->moderation()->trashSubmission((int) $sub->getId(), $global);

        $scope = static::getContainer()->get(\App\Moderation\ModerationScopeProvider::class)->scopeFor($local);
        $listed = array_map(static fn (array $e): int => $e['id'], $this->bin()->page($scope, 1, 100));
        self::assertNotContains((int) $sub->getId(), $listed, 'the Trash list shows only the reader\'s areas');

        $this->expectException(OutOfScopeException::class);
        $this->moderation()->restoreSubmission((int) $sub->getId(), $local);
    }

    // ── The purge ───────────────────────────────────────────────────────────

    public function testThePurgeWaitsThirtyDaysThenDeletesRowPinAndThread(): void
    {
        $rider = $this->rider();
        $curator = $this->curator();
        $sub = $this->newPlace($rider, $curator);
        $id = (int) $sub->getId();
        $itemId = (int) $sub->getItemId();
        $trashedAt = new \DateTimeImmutable();
        self::mockTime($trashedAt);
        $this->moderation()->trashSubmission($id, $curator);
        $this->em()->clear();

        self::mockTime($trashedAt->modify('+29 days'));
        $this->bin()->purgeExpired();
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Submission::class, $id), 'still in the bin on day 29');
        self::assertSame(2, $this->threadSize('submission', $id));

        self::mockTime($trashedAt->modify('+31 days'));
        $counts = $this->bin()->purgeExpired();
        $this->em()->clear();
        self::assertGreaterThanOrEqual(1, $counts['submissions']);
        self::assertNull($this->em()->find(Submission::class, $id), 'deleted for good after 30 days');
        self::assertNull($this->em()->find(Item::class, $itemId), 'its unapproved pin goes with it');
        self::assertSame(0, $this->threadSize('submission', $id), 'and so does its thread, from both inboxes');
    }

    public function testThePurgeEmptiesCorrectionsAndProposalsAfterThirtyDays(): void
    {
        $rider = $this->rider();
        $curator = $this->curator();
        $live = $this->route(ItemState::Verified, null);
        $correction = new RouteSuggestion((int) $live->getId(), (int) $rider->getId(), RouteSuggestionReason::Other, 'A note');
        $this->em()->persist($correction);
        $this->em()->flush();
        $proposal = $this->route(ItemState::Submitted, (int) $rider->getId());
        $sId = (int) $correction->getId();
        $rId = (int) $proposal->getId();
        $this->messages()->sendCurator((int) $rider->getId(), (int) $curator->getId(), 'correction', $sId, 'Bin route', 'About your correction.');
        $this->messages()->sendCurator((int) $rider->getId(), (int) $curator->getId(), 'route', $rId, 'Bin route', 'About your proposal.');

        $trashedAt = new \DateTimeImmutable();
        self::mockTime($trashedAt);
        $this->routes()->trashSuggestion($sId, $curator);
        $this->routes()->trashProposal($rId, $curator);

        self::mockTime($trashedAt->modify('+31 days'));
        $this->bin()->purgeExpired();

        $this->em()->clear();
        self::assertNull($this->em()->find(RouteSuggestion::class, $sId));
        self::assertNull($this->em()->find(RecommendedRoute::class, $rId));
        self::assertNotNull($this->em()->find(RecommendedRoute::class, $live->getId()), 'the live route a correction was about stays');
        self::assertSame(0, $this->threadSize('correction', $sId));
        self::assertSame(0, $this->threadSize('route', $rId));
    }

    public function testLegalHoldOutlivesTheBin(): void
    {
        $rider = $this->rider();
        $curator = $this->curator();
        $sub = $this->newPlace($rider, $curator);
        $id = (int) $sub->getId();
        $trashedAt = new \DateTimeImmutable();
        self::mockTime($trashedAt);
        $this->moderation()->trashSubmission($id, $curator);
        $this->db()->executeStatement('UPDATE submission SET escalated_at = NOW() WHERE id = ?', [$id]);

        self::mockTime($trashedAt->modify('+400 days'));
        $this->bin()->purgeExpired();

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Submission::class, $id), 'a held row is never purged');
        self::assertSame(2, $this->threadSize('submission', $id), 'and keeps its thread');
    }

    public function testAHeldSubmissionCannotBeTrashed(): void
    {
        $rider = $this->rider();
        $curator = $this->curator();
        $sub = $this->newPlace($rider, $curator);
        $this->db()->executeStatement('UPDATE submission SET escalated_at = NOW() WHERE id = ?', [$sub->getId()]);
        $this->em()->clear();

        $this->expectException(\LogicException::class);
        $this->moderation()->trashSubmission((int) $sub->getId(), $curator);
    }

    // ── Hidden everywhere but the Trash list ────────────────────────────────

    public function testATrashedSubmissionIsHiddenFromTheRidersPagesButInTheirExport(): void
    {
        $client = static::createClient();
        $rider = $this->rider();
        $curator = $this->curator();
        $sub = $this->newPlace($rider, $curator);
        $this->moderation()->trashSubmission((int) $sub->getId(), $curator);

        $client->loginUser($rider);
        foreach (['/account/contributions', '/account', '/account/messages'] as $page) {
            $client->request('GET', $page);
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString(self::TITLE, $html, $page.' shows a trashed submission');
            self::assertStringNotContainsString(self::QUESTION, $html, $page.' shows a trashed thread');
        }

        $zipPath = static::getContainer()->get(DataExportService::class)->export($rider);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        $contributions = (string) $zip->getFromName('contributions.json');
        $messages = (string) $zip->getFromName('messages.json');
        $zip->close();
        @unlink($zipPath);
        // The download is everything we hold about the rider (GDPR Art. 15),
        // the bin included, marked as trashed.
        self::assertStringContainsString(self::TITLE, $contributions);
        self::assertStringContainsString('"status": "trashed"', $contributions);
        self::assertStringContainsString(self::QUESTION, $messages);
    }

    public function testATrashedSubmissionIsOffTheDesksAndOnlyOnTheTrashList(): void
    {
        $client = static::createClient();
        $rider = $this->rider();
        $curator = $this->curator();
        $sub = $this->newPlace($rider, $curator);
        $id = (int) $sub->getId();
        $this->moderation()->trashSubmission($id, $curator);

        $client->loginUser($curator);
        foreach (['/moderate/submissions', '/moderate/submissions/history'] as $page) {
            $client->request('GET', $page);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString(self::TITLE, (string) $client->getResponse()->getContent(), $page.' lists a trashed submission');
        }

        $crawler = $client->request('GET', '/moderate/trash');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter(\sprintf('[data-trash-kind="submission"][data-trash-id="%d"]', $id));
        self::assertCount(1, $card, 'the Trash list shows it');
        self::assertStringContainsString(self::TITLE, $card->text());
        self::assertStringContainsString(self::REPLY, $card->text(), 'with its thread');

        $client->submit($card->selectButton('Restore')->form());
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Restored');
        $this->em()->clear();
        self::assertSame(SubmissionStatus::NeedsInfo, $this->em()->find(Submission::class, $id)?->getStatus());
    }

    public function testACuratorCannotMessageARiderAboutATrashedRow(): void
    {
        $client = static::createClient();
        $rider = $this->rider();
        $curator = $this->curator();
        $sub = $this->newPlace($rider, $curator);
        $id = (int) $sub->getId();
        // A card still on the queue, for a real session-bound message token.
        $this->newPlace($this->rider(), $curator);
        $this->moderation()->trashSubmission($id, $curator);

        $client->loginUser($curator);
        $crawler = $client->request('GET', '/moderate/submissions');
        $token = (string) $crawler->filter('form[action$="/moderate/message"] input[name="_token"]')->first()->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', '/moderate/message', ['channel' => 'submission', 'id' => (string) $id, 'body' => 'Hello?', '_token' => $token]);
        self::assertResponseRedirects();
        self::assertSame(2, $this->threadSize('submission', $id), 'no message was added to a thread in the bin');
    }

    public function testARiderCannotOpenTheTrashList(): void
    {
        $client = static::createClient();
        $client->loginUser($this->rider());
        $client->request('GET', '/moderate/trash');
        self::assertResponseStatusCodeSame(403);
    }
}
