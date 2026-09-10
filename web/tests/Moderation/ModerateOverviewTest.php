<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\SubmissionQueue;
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
            ->setType(SubmissionType::NewItem)->setLetter('N')->setUserId(3)
            ->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode($country)
            ->setChanges([])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();

        return $sub;
    }

    /**
     * Title search, on both desks (2026-08-03, owner). ILIKE with the wildcards
     * in the bound value — a `%` typed by a curator is a literal percent, not a
     * pattern they did not know they were writing.
     */
    public function testSearchNarrowsTheQueueByTitle(): void
    {
        $client = static::createClient();
        $this->loginCurator($client);
        $this->seedSubmission('Côte de Recherche', 'BE');
        $this->seedSubmission('Fontaine ailleurs', 'BE');

        $crawler = $client->request('GET', '/moderate?q=recherche');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Côte de Recherche', $crawler->text());
        self::assertStringNotContainsString('Fontaine ailleurs', $crawler->text());
    }

    /** A `%` in the box matches a literal percent, not everything. */
    public function testSearchTreatsWildcardsAsLiteralText(): void
    {
        $client = static::createClient();
        $this->loginCurator($client);
        $this->seedSubmission('Col du 100%', 'BE');
        $this->seedSubmission('Plain title', 'BE');

        $crawler = $client->request('GET', '/moderate?q=100%25');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Col du 100%', $crawler->text());
        self::assertStringNotContainsString('Plain title', $crawler->text());
    }

    /**
     * The queue pages, and the pager agrees with the rows it is paging: the
     * page query and the count query share one WHERE builder.
     */
    public function testTheQueuePagesAndCountsTheSameSet(): void
    {
        $client = static::createClient();
        $this->loginCurator($client);
        for ($i = 0; $i < 3; ++$i) {
            $this->seedSubmission('Paged climb '.$i, 'BE');
        }

        $queue = static::getContainer()->get(SubmissionQueue::class);
        $scope = static::getContainer()->get(ModerationScopeProvider::class)
            ->scopeFor(static::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(User::class)->findOneBy(['email' => 'overview-curator@example.com']));

        $total = $queue->countFiltered($scope, null, null, null, 'Paged climb');
        self::assertSame(3, $total);

        $first = $queue->filtered($scope, null, null, null, 'Paged climb', 1, 2);
        $second = $queue->filtered($scope, null, null, null, 'Paged climb', 2, 2);
        self::assertCount(2, $first);
        self::assertCount(1, $second);
        // No row appears on both pages.
        $ids = array_merge(array_column($first, 'id'), array_column($second, 'id'));
        self::assertCount(3, array_unique($ids));
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
        $href = (string) $crawler->filter('.q-item a.q-review')->first()->attr('href');
        self::assertStringContainsString('/map?pending=', $href);
        self::assertStringNotContainsString('/improve', $href);
    }
}
