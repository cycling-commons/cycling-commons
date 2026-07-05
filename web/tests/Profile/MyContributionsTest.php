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

final class MyContributionsTest extends WebTestCase
{
    public function testDashboardListsOwnSubmissionsOnly(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $me = (new User())->setEmail('me@subs.test');
        $me->setPassword('x');
        $other = (new User())->setEmail('other@subs.test');
        $other->setPassword('x');
        $em->persist($me);
        $em->persist($other);
        $em->flush();

        $mine = (new Submission())->setType(SubmissionType::Edit)->setLetter('D')->setUserId((int) $me->getId())
            ->setStatus(SubmissionStatus::NeedsInfo)->setTitle('My repair station edit')
            ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
            ->setDecisionNote('photo please')
            ->setChanges([])->setPayload([]);
        $theirs = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId((int) $other->getId())
            ->setStatus(SubmissionStatus::Pending)->setTitle('Their climb')
            ->setGeom('{"type":"Point","coordinates":[5.0,50.0]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $em->persist($mine);
        $em->persist($theirs);
        $em->flush();

        $client->loginUser($me);
        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('My repair station edit', $html);
        self::assertStringContainsString('photo please', $html);
        self::assertStringNotContainsString('Their climb', $html);
    }
}
