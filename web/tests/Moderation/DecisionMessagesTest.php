<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task 3 (moderation-feedback spec M2/M11): every item-pipeline decision
 * (approve/reject/needs_info) writes the submitter exactly one message,
 * atomically with the decision itself — and the decision-note textarea
 * enforces the shared 2000-character cap (M11).
 */
final class DecisionMessagesTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(): ModerationService
    {
        return static::getContainer()->get(ModerationService::class);
    }

    private function curator(): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('curator-'.uniqid('', true).'@decide-msg.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function submitter(): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('submitter-'.uniqid('', true).'@decide-msg.test');
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

    public function testApproveWritesOneMessageWithNoteAsBodyText(): void
    {
        $submitter = $this->submitter();
        $sub = $this->seedSubmission((int) $submitter->getId(), 'Côte du Message · Approve');

        $this->service()->decide($sub->getId(), 'approve', $this->curator(), '  Looks great, thanks!  ');

        $rows = $this->messagesFor((int) $submitter->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame(UserMessageKind::SubmissionApproved, $m->getKind());
        self::assertSame('system', $m->getSender());
        self::assertNull($m->getSenderId());
        self::assertSame('submission', $m->getChannel());
        self::assertSame($sub->getId(), $m->getRefId());
        self::assertSame('SUB-'.$sub->getId(), $m->getRefLabel());
        self::assertSame('messages.body.submission_approved', $m->getBodyKey());
        self::assertSame(['%title%' => 'Côte du Message · Approve'], $m->getBodyParams());
        self::assertSame('Looks great, thanks!', $m->getBodyText());
    }

    public function testRejectWritesOneMessageWithNoteAsBodyText(): void
    {
        $submitter = $this->submitter();
        $sub = $this->seedSubmission((int) $submitter->getId(), 'Côte du Message · Reject');

        $this->service()->decide($sub->getId(), 'reject', $this->curator(), 'Duplicate of an existing climb.');

        $rows = $this->messagesFor((int) $submitter->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame(UserMessageKind::SubmissionRejected, $m->getKind());
        self::assertSame($sub->getId(), $m->getRefId());
        self::assertSame('SUB-'.$sub->getId(), $m->getRefLabel());
        self::assertSame('messages.body.submission_rejected', $m->getBodyKey());
        self::assertSame('Duplicate of an existing climb.', $m->getBodyText());
    }

    public function testNeedsInfoWritesOneMessageWithNoteAsBodyText(): void
    {
        $submitter = $this->submitter();
        $sub = $this->seedSubmission((int) $submitter->getId(), 'Côte du Message · Needs info');

        $this->service()->decide($sub->getId(), 'needs_info', $this->curator(), 'Can you add a photo of the gate?');

        $rows = $this->messagesFor((int) $submitter->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame(UserMessageKind::SubmissionNeedsInfo, $m->getKind());
        self::assertSame($sub->getId(), $m->getRefId());
        self::assertSame('SUB-'.$sub->getId(), $m->getRefLabel());
        self::assertSame('messages.body.submission_needs_info', $m->getBodyKey());
        self::assertSame('Can you add a photo of the gate?', $m->getBodyText());
    }

    public function testDecisionWithNullNoteLeavesBodyTextNull(): void
    {
        $submitter = $this->submitter();
        $sub = $this->seedSubmission((int) $submitter->getId(), 'Côte du Message · No note');

        $this->service()->decide($sub->getId(), 'approve', $this->curator(), null);

        $rows = $this->messagesFor((int) $submitter->getId());
        self::assertCount(1, $rows);
        self::assertNull($rows[0]->getBodyText());
    }

    public function testDecidingTwiceWritesNoSecondMessage(): void
    {
        $submitter = $this->submitter();
        $sub = $this->seedSubmission((int) $submitter->getId(), 'Côte du Message · Twice');
        $curator = $this->curator();

        $this->service()->decide($sub->getId(), 'approve', $curator, 'First decision.');
        self::assertCount(1, $this->messagesFor((int) $submitter->getId()));

        $this->expectException(AlreadyDecidedException::class);
        try {
            $this->service()->decide($sub->getId(), 'reject', $curator, 'Second attempt.');
        } finally {
            // The guard throws before any message is queued for the second
            // decision — still exactly one row, from the first decision.
            self::assertCount(1, $this->messagesFor((int) $submitter->getId()));
        }
    }

    /**
     * M11: the note textarea enforces the shared 2000-character cap at the
     * form-validation layer — a curator posting an over-long note gets the
     * form re-rendered invalid (422) instead of the decision being applied.
     */
    public function testFormRejectsNoteOverTwoThousandCharacters(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $submitter = (new User())->setEmail('submitter-form-'.uniqid('', true).'@decide-msg.test');
        $submitter->setPassword('x');
        $em->persist($submitter);

        $curator = (new User())->setEmail('curator-form-'.uniqid('', true).'@decide-msg.test')->setDisplayName('C');
        $curator->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $curator->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $curator->setTwoFaEnabled(true);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $curator->setPassword($hasher->hashPassword($curator, 'password1234'));
        $em->persist($curator);
        $em->flush();

        $sub = $this->seedSubmission((int) $submitter->getId(), 'Côte du Message · Long note');

        $client->loginUser($curator);

        // The decision form now lives on the map drawer; mint the stateless
        // "submit" CSRF token from window.CC_MOD_TOKEN there and POST the
        // decision endpoint directly, as the drawer's script would.
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', $html, $m));
        $token = $m[1];

        $client->request('POST', '/moderate/decide', [
            'moderation_decision' => [
                'submission_id' => (string) $sub->getId(),
                'decision' => 'approve',
                'note' => str_repeat('x', 2001),
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);

        // The decision was never applied — no message was written and the
        // submission is still pending.
        self::assertCount(0, $this->messagesFor((int) $submitter->getId()));
        $em->clear();
        self::assertSame('pending', $em->find(Submission::class, $sub->getId())->getStatus()->value);
    }
}
