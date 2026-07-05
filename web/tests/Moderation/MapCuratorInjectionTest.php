<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
            ->setType(SubmissionType::NewItem)->setLetter('B')->setUserId(3)
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

    public function testPlainRiderMapHasNoPendingData(): void
    {
        $client = static::createClient();
        $this->login($client, 'map-rider@example.com', [], false);

        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('CC_PENDING', $body);
        self::assertStringNotContainsString('CC_IS_CURATOR', $body);
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
