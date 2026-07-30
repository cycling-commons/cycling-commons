<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ContributorsPageTest extends WebTestCase
{
    private function rider(string $email, string $name, bool $publicProfile): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail($email)->setDisplayName($name);
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPublicProfile($publicProfile);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function approvedSubmission(int $userId): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($userId)
            ->setTitle('Wall submission')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $sub->setStatus(SubmissionStatus::Approved);
        $em->persist($sub);
        $em->flush();
    }

    public function testWallShowsOnlyOptInContributorsWithRealCounts(): void
    {
        $client = static::createClient();
        $public = $this->rider('wall-public@test.test', 'Wall Rider', true);
        $private = $this->rider('wall-private@test.test', 'Hidden Rider', false);
        $this->approvedSubmission((int) $public->getId());
        $this->approvedSubmission((int) $private->getId());
        // Opted in but nothing contributed — not on the wall either.
        $this->rider('wall-idle@test.test', 'Idle Rider', true);

        $client->request('GET', '/contributors');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Wall Rider', $html);
        self::assertStringContainsString('/riders/'.$public->getUuid()->toRfc4122(), $html, 'wall rows link the public profile');
        self::assertStringNotContainsString('Hidden Rider', $html, 'no opt-in, no wall — regardless of contributions');
        self::assertStringNotContainsString('Idle Rider', $html, 'opt-in without contributions stays off the wall');
        self::assertStringNotContainsString('rider#', $html, 'the sample handles are gone');
    }

    public function testEmptyWallIsHonest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contributors');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('rider#', $html);
        self::assertStringNotContainsString('312k', $html, 'demo stats are gone');
    }
}
