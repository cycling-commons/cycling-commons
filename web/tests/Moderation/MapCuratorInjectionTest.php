<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MapCuratorInjectionTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function login(KernelBrowser $client, string $email, array $roles, bool $curator): void
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Map User');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        if ($curator) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }

    private function seedSubmission(string $title, SubmissionStatus $status = SubmissionStatus::Pending): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('N')->setUserId(3)
            ->setStatus($status)
            ->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[6.0208,50.7549]}')
            ->setCountryCode('NL')
            ->setChanges([])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();

        return $sub;
    }

    public function testCuratorMapCarriesPendingData(): void
    {
        $client = static::createClient();
        $this->login($client, 'map-curator@example.com', ['ROLE_CURATOR'], true);
        $this->seedSubmission('Vaalserberg');

        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('CC_IS_CURATOR', $body);
        self::assertStringContainsString('CC_PENDING', $body);
        self::assertStringContainsString('Vaalserberg', $body); // the seeded submission title
    }

    /** pendingForMap() is strictly-pending: needs-info pins stay hidden from the map until answered. */
    public function testCuratorMapExcludesNeedsInfoSubmissions(): void
    {
        $client = static::createClient();
        $this->login($client, 'map-curator-needsinfo@example.com', ['ROLE_CURATOR'], true);
        $this->seedSubmission('Awaiting clarification', SubmissionStatus::NeedsInfo);

        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Awaiting clarification', $body);
    }

    /**
     * A curator who has NOT completed mandatory 2FA setup must not receive any
     * curator capability yet — including the map's pending moderation payload.
     * /map is a public page on the 2FA-enforcer's bypass list, so the gate has
     * to live in the controller, not the request enforcer.
     */
    public function testSetupPendingCuratorMapHasNoCuratorCapability(): void
    {
        $client = static::createClient();
        // curator: true seeds a TOTP secret + enabled; here we want the
        // opposite - an elevated user who still owes 2FA setup. They fall
        // back to RIDER-grade data: their own rows only (here none), never
        // the queue and never the moderation chrome.
        $this->login($client, 'map-curator-no2fa@example.com', ['ROLE_CURATOR'], false);
        $this->seedSubmission('Should Stay Hidden');

        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('CC_IS_CURATOR', $body);
        self::assertStringNotContainsString('Should Stay Hidden', $body);
    }

    /**
     * A rider's map carries their OWN pending rows (owner 2026-08-16: a
     * pending contribution was invisible to the person who made it) - and
     * ONLY their own: no other rider's title, and never the moderation flag.
     */
    public function testPlainRiderMapCarriesOwnPendingRowsOnly(): void
    {
        $client = static::createClient();
        $this->login($client, 'map-rider@example.com', [], false);
        $this->seedSubmission('Somebody Elses Climb'); // user_id 3, another rider
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $me = $em->getRepository(User::class)->findOneBy(['email' => 'map-rider@example.com']);
        $own = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('P')->setUserId((int) $me->getId())
            ->setStatus(SubmissionStatus::Pending)->setTitle('My Own Viewpoint')
            ->setGeom('{"type":"Point","coordinates":[6.0208,50.7549]}')
            ->setCountryCode('NL')->setChanges([])->setPayload([]);
        $em->persist($own);
        $em->flush();

        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('CC_PENDING', $body);
        self::assertStringContainsString('My Own Viewpoint', $body);
        self::assertStringNotContainsString('Somebody Elses Climb', $body, "never another rider's row");
        self::assertStringNotContainsString('CC_IS_CURATOR', $body, 'never the moderation chrome switch');
    }

    public function testAnonymousMapHasNoPendingData(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('CC_PENDING', $body);
    }
}
