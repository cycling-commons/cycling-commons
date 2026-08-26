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
        $theirs = (new Submission())->setType(SubmissionType::NewItem)->setLetter('N')->setUserId((int) $other->getId())
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

    /**
     * The category filter (owner 2026-08-16): ?letter=Q shows only that
     * kind, the chips render only for letters this rider has, and a garbage
     * letter falls back to the unfiltered list rather than an empty one.
     */
    public function testContributionsFilterByCategory(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $me = (new User())->setEmail('filter-me@subs.test');
        $me->setPassword('x');
        $em->persist($me);
        $em->flush();

        $mk = static function (string $letter, string $title) use ($em, $me): void {
            $em->persist((new Submission())->setType(SubmissionType::Edit)->setLetter($letter)
                ->setUserId((int) $me->getId())->setStatus(SubmissionStatus::Pending)->setTitle($title)
                ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
                ->setChanges([])->setPayload([]));
        };
        $mk('Q', 'My castle edit');
        $mk('B', 'My fountain edit');
        $em->flush();

        $client->loginUser($me);
        $html = (string) $client->request('GET', '/profile?letter=Q')->html();
        self::assertStringContainsString('My castle edit', $html);
        self::assertStringNotContainsString('My fountain edit', $html);
        self::assertStringContainsString('?letter=B', $html, 'the other category stays one click away');

        $html = (string) $client->request('GET', '/profile?letter=%27%22zz')->html();
        self::assertStringContainsString('My castle edit', $html, 'garbage filter = unfiltered, never empty');
        self::assertStringContainsString('My fountain edit', $html);
    }

    /**
     * The withdrawn toggle combines with the category chips (owner
     * 2026-08-16): both are plain GET params, ANDed; every chip href carries
     * the other filter so switching one never resets the other.
     */
    public function testWithdrawnFilterCombinesWithCategory(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $me = (new User())->setEmail('wfilter-me@subs.test');
        $me->setPassword('x');
        $em->persist($me);
        $em->flush();

        $mk = static function (string $letter, string $title, SubmissionStatus $status) use ($em, $me): void {
            $em->persist((new Submission())->setType(SubmissionType::Edit)->setLetter($letter)
                ->setUserId((int) $me->getId())->setStatus($status)->setTitle($title)
                ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
                ->setChanges([])->setPayload([]));
        };
        $mk('Q', 'Castle pending', SubmissionStatus::Pending);
        $mk('Q', 'Castle withdrawn', SubmissionStatus::Withdrawn);
        $mk('B', 'Fountain withdrawn', SubmissionStatus::Withdrawn);
        $em->flush();
        $client->loginUser($me);

        $html = (string) $client->request('GET', '/profile?status=withdrawn')->html();
        self::assertStringContainsString('Castle withdrawn', $html);
        self::assertStringContainsString('Fountain withdrawn', $html);
        self::assertStringNotContainsString('Castle pending', $html);

        $html = (string) $client->request('GET', '/profile?status=withdrawn&letter=Q')->html();
        self::assertStringContainsString('Castle withdrawn', $html, 'both filters AND together');
        self::assertStringNotContainsString('Fountain withdrawn', $html);
        self::assertStringContainsString('letter=B&amp;status=withdrawn', $html, 'category chips keep the status filter');
    }
}
