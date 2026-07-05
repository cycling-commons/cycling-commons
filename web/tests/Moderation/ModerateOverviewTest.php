<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ModerateOverviewTest extends WebTestCase
{
    private function loginCurator(KernelBrowser $client): void
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('overview-curator@example.com');
        $user->setDisplayName('Overview Curator');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles(['ROLE_CURATOR']);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    private function seedSubmission(string $title, string $country): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('B')->setUserId(3)
            ->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode($country)
            ->setChanges([])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();

        return $sub;
    }

    public function testFilterByCountryNarrowsTheQueue(): void
    {
        $client = static::createClient();
        $this->loginCurator($client);
        $this->seedSubmission('Vaalserberg', 'NL');
        $this->seedSubmission('Repair station · Malmedy', 'BE');

        $client->request('GET', '/moderate?country=NL');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Vaalserberg', $body);          // the NL item
        self::assertStringNotContainsString('Repair station · Malmedy', $body); // a BE item
    }

    public function testViewOnMapLinksToPendingOnTheRealMap(): void
    {
        $client = static::createClient();
        $this->loginCurator($client);
        $this->seedSubmission('Côte de la Vecquée', 'BE');

        $crawler = $client->request('GET', '/moderate');

        self::assertResponseIsSuccessful();
        $href = (string) $crawler->filter('.q-item a.q-view')->first()->attr('href');
        self::assertStringContainsString('/map?pending=', $href);
        self::assertStringNotContainsString('/improve', $href);
    }
}
