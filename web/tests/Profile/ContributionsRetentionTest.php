<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Profile;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The rider's contributions list keeps a rejected submission as long as the
 * account, however old the decision (owner 2026-10-01), and shows none that
 * a curator moved to Trash.
 *
 * @see docs/specs/moderation-and-contribution.md §6, §8
 */
final class ContributionsRetentionTest extends WebTestCase
{
    public function testAnOldRejectedSubmissionStaysAndATrashedOneIsHidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $me = (new User())->setEmail('retention@subs.test');
        $me->setPassword('x');
        $other = (new User())->setEmail('retention-other@subs.test');
        $other->setPassword('x');
        $em->persist($me);
        $em->persist($other);
        $em->flush();

        $old = $this->submission((int) $me->getId(), SubmissionStatus::Rejected, 'Old rejected edit', '-4 years');
        $young = $this->submission((int) $me->getId(), SubmissionStatus::Rejected, 'Young rejected edit', '-1 day');
        $trashed = $this->submission((int) $me->getId(), SubmissionStatus::Pending, 'Trashed pending edit', null);
        $trashed->moveToTrash(1, new \DateTimeImmutable());
        // Owned by a different user entirely: never in $me's list, whatever
        // its status, which locks the ownership scoping (`s.user_id = :uid`).
        $othersSubmission = $this->submission((int) $other->getId(), SubmissionStatus::Pending, 'Someone elses submission', null);
        foreach ([$old, $young, $trashed, $othersSubmission] as $s) {
            $em->persist($s);
        }
        $em->flush();

        $client->loginUser($me);
        $client->request('GET', '/account/contributions');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Old rejected edit', $html);
        self::assertStringContainsString('Young rejected edit', $html);
        self::assertStringNotContainsString('Trashed pending edit', $html);
        self::assertStringNotContainsString('Someone elses submission', $html);
    }

    private function submission(int $userId, SubmissionStatus $status, string $title, ?string $decidedAt): Submission
    {
        $s = (new Submission())->setType(SubmissionType::Edit)->setLetter('D')->setUserId($userId)
            ->setStatus($status)->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        if (null !== $decidedAt) {
            $s->setDecidedAt(new \DateTimeImmutable($decidedAt));
        }

        return $s;
    }
}
