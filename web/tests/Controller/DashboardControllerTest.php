<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The rider dashboard (account-and-auth.md §8): the rider's own open counts on
 * one page, read-only, with the curator dashboard's look.
 */
final class DashboardControllerTest extends WebTestCase
{
    public function testARiderSeesTheirOwnCountsLinkedFromOnePage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $rider = $this->login($client, 'dash-rider-own@example.com');
        $other = $this->login($client, 'dash-rider-other@example.com');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($this->submission((int) $rider->getId(), SubmissionStatus::Pending, 'Fountain at Ronse'));
        $em->persist($this->submission((int) $rider->getId(), SubmissionStatus::Pending, 'Bench on the Kwaremont'));
        $em->persist($this->submission((int) $rider->getId(), SubmissionStatus::NeedsInfo, 'Tap in Geraardsbergen'));
        $em->persist($this->submission((int) $other->getId(), SubmissionStatus::NeedsInfo, 'Not mine'));
        $em->flush();
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dashboard');
        self::assertSelectorExists('.dtabs a.on[href$="/account"]', 'the dashboard tab is the active one');

        $tiles = $crawler->filter('a.db-tile')->each(static fn ($a): array => [
            'href' => (string) $a->attr('href'),
            'n' => trim($a->filter('.db-n')->text()),
            'due' => str_contains((string) $a->attr('class'), 'db-due'),
        ]);
        $hrefs = array_column($tiles, 'href');
        foreach (['/account/contributions', '/account/messages', '/account/reports'] as $page) {
            self::assertContains($page, $hrefs, $page.' has a tile');
        }
        self::assertContains('/translate/mine?status=needs_info', $hrefs);
        self::assertContains('/translate/mine?status=pending', $hrefs);

        // First tile: the curator's question on one contribution, in red.
        self::assertSame(['href' => '/account/contributions', 'n' => '1', 'due' => true], $tiles[0]);
        // The contributions tile under "Your work": two still with a curator.
        self::assertSame(['href' => '/account/contributions', 'n' => '2', 'due' => false], $tiles[3]);

        $recent = $crawler->filter('.db-list')->first()->text();
        self::assertStringContainsString('Tap in Geraardsbergen', $recent);
        self::assertStringNotContainsString('Not mine', $recent, 'another rider never shows');
    }

    public function testWithoutABaseLocationTheNearbyBlockPointsToSettings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, 'dash-rider-nobase@example.com');

        $crawler = $client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.sec-empty a[href$="/account/settings"]'));
    }

    public function testAVisitorIsSentToLogIn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    private function submission(int $userId, SubmissionStatus $status, string $title): Submission
    {
        return (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($userId)
            ->setStatus($status)->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[3.6,50.8]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
    }

    private function login(KernelBrowser $client, string $email): User
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Dash rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $user;
    }
}
