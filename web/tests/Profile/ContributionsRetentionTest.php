<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Profile;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Retention phase 1 (M8): the profile's contributions list must hide a
 * rejected submission past the retention cutoff even when no sweep has run —
 * the lazy filter is the rider-facing correctness guarantee, independent of
 * the opportunistic GC.
 */
final class ContributionsRetentionTest extends WebTestCase
{
    public function testExpiredRejectedSubmissionIsHiddenButYoungOneShows(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $me = (new User())->setEmail('retention@subs.test');
        $me->setPassword('x');
        $em->persist($me);
        $em->flush();

        $expired = (new Submission())->setType(SubmissionType::Edit)->setLetter('D')->setUserId((int) $me->getId())
            ->setStatus(SubmissionStatus::Rejected)->setTitle('Expired rejected edit')
            ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
            ->setDecidedAt(new \DateTimeImmutable('-4 months'))
            ->setChanges([])->setPayload([]);
        $young = (new Submission())->setType(SubmissionType::Edit)->setLetter('D')->setUserId((int) $me->getId())
            ->setStatus(SubmissionStatus::Rejected)->setTitle('Young rejected edit')
            ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
            ->setDecidedAt(new \DateTimeImmutable('-1 day'))
            ->setChanges([])->setPayload([]);
        $em->persist($expired);
        $em->persist($young);
        $em->flush();

        // No sweep has run — this is purely the lazy read-filter.
        $client->loginUser($me);
        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Expired rejected edit', $html);
        self::assertStringContainsString('Young rejected edit', $html);
    }
}
