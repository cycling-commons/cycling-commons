<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationScope;
use App\Moderation\ModerationService;
use App\Moderation\OutOfScopeException;
use App\Moderation\SubmissionQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Escalating a submission's words (docs/specs/photo-uploads.md §6d).
 *
 * The photo side got the third verb first, but words can be illegal content
 * just as pixels can, and Trash was a curator's only option for either —
 * destroying the evidence along with the material.
 */
final class SubmissionEscalationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ModerationService $moderation;
    private SubmissionQueue $queue;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->moderation = static::getContainer()->get(ModerationService::class);
        $this->queue = static::getContainer()->get(SubmissionQueue::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email.'@example.test');
        $user->setPassword('x');
        $user->setDisplayName('Escalating Curator');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function region(string $slug, string $cc): Region
    {
        $r = (new Region())->setSlug($slug.'-'.uniqid())->setName(ucfirst($slug))->setCountryCode($cc);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function submission(User $author): Submission
    {
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('N')
            ->setUserId((int) $author->getId())
            ->setTitle('Côte de Escalade')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    /** @return list<Email> */
    private function sent(): array
    {
        return array_map(
            static fn (\Symfony\Component\Mailer\Event\MessageEvent $e): object => $e->getMessage(),
            static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents(),
        );
    }

    public function testEscalatingTakesItOutOfTheQueueAndAlertsAHuman(): void
    {
        $curator = $this->user('esc-sub-curator');
        $sub = $this->submission($this->user('esc-sub-author'));
        $id = (int) $sub->getId();

        $before = $this->queue->total(ModerationScope::global());
        $this->moderation->escalateSubmission($id, $curator, 'The description is a threat against a named person.');

        self::assertTrue($sub->isEscalated());
        self::assertSame($before - 1, $this->queue->total(ModerationScope::global()), 'gone from the desk');

        $mails = $this->sent();
        self::assertCount(1, $mails);
        $body = (string) $mails[0]->getTextBody();
        self::assertStringContainsString('The description is a threat against a named person.', $body);
        // The submission's own words never travel in the alert.
        self::assertStringNotContainsString('Côte de l', $body);
    }

    public function testTrashCannotDestroyAHeldSubmission(): void
    {
        $curator = $this->user('esc-sub-trash');
        $sub = $this->submission($this->user('esc-sub-trash-author'));
        $id = (int) $sub->getId();

        $this->moderation->escalateSubmission($id, $curator, 'Suspected illegal content.');

        try {
            $this->moderation->trashSubmission($id, $curator);
            self::fail('Trash must refuse a submission under legal hold');
        } catch (\LogicException) {
            // expected
        }

        self::assertNotNull($this->em->find(Submission::class, $id), 'the row survives');
    }

    /** Photos attached to it are the same act by the same person, so they are held too. */
    public function testAttachedPhotosAreHeldWithIt(): void
    {
        $curator = $this->user('esc-sub-photos');
        $author = $this->user('esc-sub-photos-author');
        $sub = $this->submission($author);
        $id = (int) $sub->getId();

        $consent = new ConsentRecord(Uuid::v4(), (int) $author->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $author->getId(), $consent->getId(), 'EU', 900, 600, 4242, bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $upload->claim($id);
        $this->em->flush();

        $this->moderation->escalateSubmission($id, $curator, 'Both the words and the photo.');

        self::assertTrue($upload->isEscalated());
    }

    public function testEscalatingTwiceAlertsOnceAndAnEmptyReasonIsRefused(): void
    {
        $curator = $this->user('esc-sub-twice');
        $sub = $this->submission($this->user('esc-sub-twice-author'));
        $id = (int) $sub->getId();

        $this->moderation->escalateSubmission($id, $curator, 'First.');
        $this->moderation->escalateSubmission($id, $curator, 'Double submit.');
        self::assertCount(1, $this->sent());
        self::assertSame('First.', $sub->getEscalatedReason());

        $other = $this->submission($this->user('esc-sub-empty-author'));
        $this->expectException(\InvalidArgumentException::class);
        $this->moderation->escalateSubmission((int) $other->getId(), $curator, '   ');
    }

    public function testReleasingReturnsItToTheQueue(): void
    {
        $curator = $this->user('esc-sub-release');
        $admin = $this->user('esc-sub-admin');
        $sub = $this->submission($this->user('esc-sub-release-author'));
        $id = (int) $sub->getId();

        $this->moderation->escalateSubmission($id, $curator, 'Escalating to be safe.');
        $held = $this->moderation->heldSubmissions();
        self::assertCount(1, $held);
        self::assertSame($id, $held[0]['id']);
        self::assertSame('Escalating to be safe.', $held[0]['reason']);

        self::assertTrue($this->moderation->releaseSubmission($id, $admin, 'False alarm.'));

        self::assertFalse($sub->isEscalated());
        self::assertSame([], $this->moderation->heldSubmissions());
    }

    /**
     * The admin's held list pages, and the count matches it. Releasing takes
     * a row out of both — a count that outlived its list would tell an admin
     * there is still material under hold when there is none.
     */
    public function testTheHeldListPagesAndCountsTheSameSet(): void
    {
        $curator = $this->user('esc-sub-paging-curator');
        $admin = $this->user('esc-sub-paging-admin');

        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $id = (int) $this->submission($this->user('esc-sub-paging-author-'.$i))->getId();
            $this->moderation->escalateSubmission($id, $curator, 'Held '.$i);
            $ids[] = $id;
        }

        self::assertSame(3, $this->moderation->heldSubmissionCount());
        self::assertCount(2, $this->moderation->heldSubmissions(1, 2));
        self::assertCount(1, $this->moderation->heldSubmissions(2, 2));

        $this->moderation->releaseSubmission($ids[0], $admin, 'False alarm.');

        self::assertSame(2, $this->moderation->heldSubmissionCount());
        self::assertCount(2, $this->moderation->heldSubmissions(1, 25));
    }

    /**
     * A region-limited curator cannot escalate a submission from somebody
     * else's area.
     *
     * Escalation is the heaviest verb on the desk: it puts a row into legal
     * hold, hides its photos and mails a human. Every other write already
     * checked the curator's areas, and this one did not (security scan
     * 2026-08-25), so a curator scoped to one province could have pulled any
     * submission on the platform out of its queue. The endpoint takes a bare
     * submission id, so "the queue only shows you your own regions" was never
     * the guard.
     */
    public function testARegionLimitedCuratorCannotEscalateOutsideTheirAreas(): void
    {
        $curator = $this->user('esc-sub-scope');
        $wallonia = $this->region('scope-wallonia', 'BE');
        $flanders = $this->region('scope-flanders', 'BE');

        $this->em->persist(new ModeratorArea((int) $curator->getId(), (int) $wallonia->getId(), null));
        $this->em->flush();

        $foreign = $this->submission($this->user('esc-sub-scope-author'));
        $foreign->setRegionId((int) $flanders->getId());
        $this->em->flush();

        try {
            $this->moderation->escalateSubmission((int) $foreign->getId(), $curator, 'Not mine to hold.');
            self::fail('Escalation must refuse a submission outside the curator\'s areas');
        } catch (OutOfScopeException) {
            // expected
        }

        self::assertFalse($foreign->isEscalated(), 'the row is untouched');
        self::assertSame([], $this->sent(), 'and no human was paged');
    }

    /** The same curator escalates normally inside their own area. */
    public function testTheSameCuratorEscalatesInsideTheirOwnArea(): void
    {
        $curator = $this->user('esc-sub-scope-ok');
        $wallonia = $this->region('scope-ok-wallonia', 'BE');

        $this->em->persist(new ModeratorArea((int) $curator->getId(), (int) $wallonia->getId(), null));
        $this->em->flush();

        $mine = $this->submission($this->user('esc-sub-scope-ok-author'));
        $mine->setRegionId((int) $wallonia->getId());
        $this->em->flush();

        $this->moderation->escalateSubmission((int) $mine->getId(), $curator, 'This one is mine.');

        self::assertTrue($mine->isEscalated());
        self::assertCount(1, $this->sent());
    }
}
