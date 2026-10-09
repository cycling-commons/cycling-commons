<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\MediaEscalationService;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Moderation\StatementDecision;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasons;
use App\Support\ContentReportService;
use App\Support\Entity\ContentReport;
use App\Support\ReportGround;
use App\Support\ReportResolver;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * An upheld report tells the author why, once, through the one statement of
 * reasons; a place's author is the rider who added it.
 *
 * @see docs/specs/content-reports.md §7, §8
 */
final class ReportStatementTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnUpheldPhotoReportTellsTheUploaderOnceWithTheRuleAndTheAnswerPage(): void
    {
        $uploader = $this->user('uploader');
        $upload = $this->approvedPhoto($uploader);
        $reports = static::getContainer()->get(ContentReportService::class);
        $report = $reports->file(ReportTarget::Photo, $upload->getId()->toRfc4122(), ReportGround::Copyright, 'My photo.', 'owner@example.org', null, '198.51.100.7');

        $reports->decide($report, ReportStatus::Upheld, 'The original is on the claimant\'s site.', $this->user('curator', ['ROLE_CURATOR']));
        $reports->tellAuthor($report, $uploader);

        $messages = $this->em->getRepository(UserMessage::class)->findBy(['userId' => $uploader->getId()], ['id' => 'ASC']);
        self::assertCount(1, $messages, 'one statement, on the removal itself');
        self::assertSame(UserMessageKind::MediaRemovedOnReport, $messages[0]->getKind());
        $statement = StatementOfReasons::fromArray($messages[0]->getBodyParams()[StatementOfReasons::PARAM] ?? null);
        self::assertNotNull($statement);
        self::assertSame(StatementDecision::Removed, $statement->decision);
        self::assertSame(StatementGround::Copyright, $statement->ground);
        self::assertTrue($statement->fromReport);
        self::assertTrue($statement->aboutPhoto);
        self::assertSame($report->getId()->toRfc4122(), $statement->reference);
        self::assertSame('/report/'.$report->getId()->toRfc4122().'/answer', $statement->answerPath);
        self::assertSame('Fontaine des motifs', $statement->subject);
        self::assertSame('dsa_statement.facts.report_upheld', $statement->factsKey, 'a photo that stayed up while it waited was not hidden first');
        self::assertTrue($report->isAuthorTold());
    }

    /**
     * The urgent ground hides the photo on the report alone, and the uploader
     * hears nothing until a curator has decided: a message or a statement at
     * the hide would warn somebody the report suspects (owner 2026-10-09, the
     * reasoning of the legal hold).
     */
    public function testAnUrgentReportHidesThePhotoAndTellsTheUploaderNothingYet(): void
    {
        $uploader = $this->user('uploader');
        $upload = $this->approvedPhoto($uploader);

        $this->fileUrgent($upload);

        self::assertTrue($upload->isTakedownWithheld(), 'hidden on the report alone');
        self::assertSame([], $this->messagesFor($uploader), 'no message and no statement at the hide');
    }

    public function testAnUpheldUrgentReportTellsTheUploaderThePhotoWasHiddenFirst(): void
    {
        $uploader = $this->user('uploader');
        $upload = $this->approvedPhoto($uploader);
        $reports = static::getContainer()->get(ContentReportService::class);
        $report = $this->fileUrgent($upload);

        $reports->decide($report, ReportStatus::Upheld, 'The photo shows a child bathing.', $this->user('curator', ['ROLE_CURATOR']));
        $reports->tellAuthor($report, $uploader);

        $messages = $this->messagesFor($uploader);
        self::assertCount(1, $messages, 'one statement, on the removal');
        self::assertSame(UserMessageKind::MediaRemovedOnReport, $messages[0]->getKind());
        $statement = $this->statementOf($messages[0]);
        self::assertSame(StatementDecision::Removed, $statement->decision);
        self::assertSame(StatementGround::IntimateOrChild, $statement->ground);
        self::assertSame('The photo shows a child bathing.', $statement->facts);
        self::assertSame('dsa_statement.facts.report_upheld_hidden', $statement->factsKey, 'it says the photo was hidden when it was reported');
        self::assertFalse($statement->automated, 'a curator removed it');
        self::assertTrue($statement->fromReport);
        self::assertSame($report->getId()->toRfc4122(), $statement->reference);
    }

    /**
     * Rejected: the photo comes back with the existing "back on the map"
     * message, and that message carries the statement for the hide, which
     * restricted the photo for as long as it lasted (DSA Article 17(1)(a)).
     */
    public function testARejectedUrgentReportPutsThePhotoBackWithTheStatementForTheHide(): void
    {
        $uploader = $this->user('uploader');
        $upload = $this->approvedPhoto($uploader);
        $report = $this->fileUrgent($upload);

        static::getContainer()->get(ContentReportService::class)
            ->decide($report, ReportStatus::Rejected, 'Adults at a fountain, nothing intimate.', $this->user('curator', ['ROLE_CURATOR']));

        $messages = $this->messagesFor($uploader);
        self::assertCount(1, $messages);
        self::assertSame(UserMessageKind::MediaRestoredAfterReview, $messages[0]->getKind());
        $this->assertTheHideIsExplained($this->statementOf($messages[0]));
    }

    /** Escalated: nothing while the hold lasts; the decision after it is the one explained. */
    public function testAHeldUrgentReportTellsTheUploaderNothingUntilTheDecisionAfterTheHold(): void
    {
        $uploader = $this->user('uploader');
        $upload = $this->approvedPhoto($uploader);
        $reports = static::getContainer()->get(ContentReportService::class);
        $report = $this->fileUrgent($upload);

        $reports->escalate($report, $this->user('curator', ['ROLE_CURATOR']), 'Possibly illegal, for an administrator.');
        self::assertSame([], $this->messagesFor($uploader), 'nothing while held');

        static::getContainer()->get(MediaEscalationService::class)->release($upload, $this->user('admin', ['ROLE_ADMIN']), 'Not illegal.');
        self::assertSame([], $this->messagesFor($uploader), 'nothing when the hold is lifted either: no curator has decided yet');

        $reports->decide($report, ReportStatus::Rejected, 'Nothing intimate and no child.', $this->user('curator', ['ROLE_CURATOR']));

        $messages = $this->messagesFor($uploader);
        self::assertCount(1, $messages);
        self::assertSame(UserMessageKind::MediaRestoredAfterReview, $messages[0]->getKind());
        $this->assertTheHideIsExplained($this->statementOf($messages[0]));
    }

    private function assertTheHideIsExplained(StatementOfReasons $statement): void
    {
        self::assertSame(StatementDecision::HiddenThenRestored, $statement->decision);
        self::assertSame(StatementGround::IntimateOrChild, $statement->ground);
        self::assertTrue($statement->automated, 'our checks hid it, before a curator looked');
        self::assertTrue($statement->fromReport);
        self::assertTrue($statement->aboutPhoto);
        self::assertSame('Fontaine des motifs', $statement->subject);
        self::assertSame('dsa_statement.facts.hidden_restored', $statement->factsKey);
    }

    private function fileUrgent(MediaUpload $upload): ContentReport
    {
        return static::getContainer()->get(ContentReportService::class)
            ->file(ReportTarget::Photo, $upload->getId()->toRfc4122(), ReportGround::IntimateOrChild, 'A child.', null, null, '198.51.100.8');
    }

    /** @return list<UserMessage> */
    private function messagesFor(User $rider): array
    {
        return $this->em->getRepository(UserMessage::class)->findBy(['userId' => $rider->getId()], ['id' => 'ASC']);
    }

    private function statementOf(UserMessage $message): StatementOfReasons
    {
        $statement = StatementOfReasons::fromArray($message->getBodyParams()[StatementOfReasons::PARAM] ?? null);
        self::assertNotNull($statement, 'the message carries a statement of reasons');

        return $statement;
    }

    public function testTheRiderWhoAddedAPlaceIsItsAuthorAndIsToldWhenAReportIsUpheld(): void
    {
        $author = $this->user('author');
        $place = $this->place('Source du rapport');
        $this->addedBy($place, $author);
        $seeded = $this->place('Seeded tap');
        $reports = static::getContainer()->get(ContentReportService::class);
        $resolver = static::getContainer()->get(ReportResolver::class);

        $report = $reports->file(ReportTarget::Item, (string) $place->getId(), ReportGround::Untrue, 'There is no tap here.', 'walker@example.org', null, '198.51.100.9');
        $resolved = $resolver->resolve($report);
        self::assertSame($author->getId(), $resolved['author']?->getId());
        $seededReport = $reports->file(ReportTarget::Item, (string) $seeded->getId(), ReportGround::Untrue, 'Gone.', 'walker@example.org', null, '198.51.100.9');
        self::assertNull($resolver->resolve($seededReport)['author'], 'a seeded place has no author');

        $reports->decide($report, ReportStatus::Upheld, 'The tap was removed in 2025.', $this->user('curator', ['ROLE_CURATOR']));
        $reports->tellAuthor($report, $author, $resolved['label']);
        $reports->tellAuthor($report, $author, $resolved['label']);

        $messages = $this->em->getRepository(UserMessage::class)->findBy(['userId' => $author->getId()]);
        self::assertCount(1, $messages, 'told once');
        self::assertSame(UserMessageKind::StatementOfReasons, $messages[0]->getKind());
        $statement = StatementOfReasons::fromArray($messages[0]->getBodyParams()[StatementOfReasons::PARAM] ?? null);
        self::assertNotNull($statement);
        self::assertSame(StatementDecision::ChangedOrRemoved, $statement->decision);
        self::assertSame(StatementGround::Untrue, $statement->ground);
        self::assertSame('The tap was removed in 2025.', $statement->facts);
        self::assertSame('Source du rapport', $statement->subject);
        self::assertNull($statement->answerPath);
    }

    private function place(string $name): Item
    {
        $item = (new Item())->setLetter('B')->setName($name)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('report-statement-'.uniqid('', true))
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function addedBy(Item $item, User $rider): void
    {
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId((int) $rider->getId())
            ->setItemId($item->getId())->setTitle($item->getName())->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode('BE')->setChanges([])->setPayload([])->setStatus(SubmissionStatus::Approved);
        $this->em->persist($sub);
        $this->em->flush();
    }

    private function approvedPhoto(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $item = $this->place('Fontaine des motifs');
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $upload->approve($item->getId());
        $this->em->flush();
        static::getContainer()->get(MediaStorage::class)
            ->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $item->setAttributes(['photos' => [static::getContainer()->get(MediaDecisionService::class)->describe($upload)]]);
        $this->em->flush();

        return $upload;
    }

    /** @param list<string> $roles */
    private function user(string $kind, array $roles = []): User
    {
        $u = (new User())->setEmail($kind.'-'.uniqid('', true).'@report-statement.test')->setDisplayName(ucfirst($kind))->setRoles($roles);
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }
}
