<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The moderation workload view: per month x per REGION, deliberately never
 * per moderator (owner 2026-08-13) - it answers how much and where, not who.
 * Admin-only, read-only; the rulebook tells moderators it exists.
 */
final class ModerationActivityPageTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = [], bool $admin2fa = false): User
    {
        $c = static::getContainer();
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles($roles);
        $u->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        if ($admin2fa) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function url(): string
    {
        return static::getContainer()->get('router')->generate('admin_moderation_activity');
    }

    public function testANonAdminIsForbidden(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('activity-curator@example.com', ['ROLE_CURATOR']));
        $client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDecidedWorkShowsGroupedAndNoModeratorIsNamed(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('activity-admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('activity-rider@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $s = (new Submission())->setType(SubmissionType::NewItem)->setLetter('C')
            ->setUserId((int) $rider->getId())
            ->setStatus(SubmissionStatus::Approved)->setTitle('activity row')
            ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $s->setDecidedBy((int) $admin->getId());
        $s->setDecidedAt(new \DateTimeImmutable('first day of this month noon'));
        $em->persist($s);
        $em->flush();

        $client->loginUser($admin);
        $client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(date('Y-m'), $html, 'this month must appear as a row');
        // Per region, never per person: the deciding admin's name may appear
        // in the EasyAdmin chrome (logged-in user menu) but the table itself
        // carries no moderator column.
        self::assertStringNotContainsString('activity-rider', $html, 'no rider is named');
        self::assertStringContainsString('Approved', $html);
    }
}
