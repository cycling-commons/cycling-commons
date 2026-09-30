<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\DeskRider;
use App\Moderation\ModerationScope;
use App\Moderation\SubmissionQueue;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A rider's pseudonym is eight random Crockford base32 characters, stored once
 * on the account when it is created, and never derived from anything
 * (docs/specs/account-and-auth.md §9).
 */
final class RiderPseudonymTest extends WebTestCase
{
    use GuardedSignupTrait;

    private const string FORMAT = '/^[0-9a-hjkmnp-tv-z]{8}$/';

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function storedPseudonym(int $userId): mixed
    {
        return static::getContainer()->get(Connection::class)->fetchOne('SELECT pseudonym FROM users WHERE id = ?', [$userId]);
    }

    private function user(string $email, string $name = 'Pseudo Rider'): User
    {
        $user = (new User())->setEmail($email)->setDisplayName($name)->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    public function testRegistrationStoresARandomPseudonym(): void
    {
        $client = $this->client();
        $this->signUp($client, 'pseudonym@example.com');
        self::assertResponseIsSuccessful();

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'pseudonym@example.com']);
        self::assertInstanceOf(User::class, $user);
        $stored = $this->storedPseudonym((int) $user->getId());
        self::assertIsString($stored);
        self::assertMatchesRegularExpression(self::FORMAT, $stored);
        self::assertSame($stored, $user->getPseudonym());
    }

    public function testThePseudonymIsNotDerivedFromTheId(): void
    {
        self::bootKernel();
        $a = $this->user('pseudo-a@example.com');
        $b = $this->user('pseudo-b@example.com');

        self::assertNotSame($a->getPseudonym(), $b->getPseudonym());
        self::assertNotSame(substr(hash('crc32b', 'cc-sub-'.(int) $a->getId()), 0, 4), substr($a->getPseudonym(), 0, 4));
    }

    public function testThePseudonymNeverChangesWithTheProfileOrTheName(): void
    {
        self::bootKernel();
        $user = $this->user('pseudo-keep@example.com');
        $first = $user->getPseudonym();

        $user->setPublicProfile(true);
        $this->em()->flush();
        $user->setDisplayName('Another Name');
        $this->em()->flush();
        $user->setPublicProfile(false);
        $this->em()->flush();
        $this->em()->clear();

        self::assertSame($first, $this->storedPseudonym((int) $user->getId()));
    }

    public function testAClashIsDrawnAgain(): void
    {
        self::bootKernel();
        $taken = $this->user('pseudo-first@example.com');

        $second = (new User())->setEmail('pseudo-second@example.com')->setDisplayName('Second')->setPassword('x');
        (new \ReflectionProperty(User::class, 'pseudonym'))->setValue($second, $taken->getPseudonym());
        $this->em()->persist($second);
        $this->em()->flush();

        self::assertMatchesRegularExpression(self::FORMAT, $second->getPseudonym());
        self::assertNotSame($taken->getPseudonym(), $second->getPseudonym());
    }

    public function testTheDeskShowsTheStoredPseudonym(): void
    {
        self::bootKernel();
        $user = $this->user('pseudo-desk@example.com', 'Private Rider');

        self::assertSame('rider#'.$user->getPseudonym(), DeskRider::ofUser($user)['name']);

        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('D')->setUserId((int) $user->getId())
            ->setStatus(SubmissionStatus::Pending)->setTitle('Pseudonym tap')
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $this->em()->persist($sub);
        $this->em()->flush();

        $rows = static::getContainer()->get(SubmissionQueue::class)->filtered(ModerationScope::global(), null, null, null);
        $row = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] === $sub->getId()))[0];
        self::assertSame('rider#'.$user->getPseudonym(), $row['who']);
    }

    /**
     * A removed account has no row, so no stored pseudonym: its old work keeps
     * the handle derived from its id, `rider#` and four hex characters.
     */
    public function testARemovedAccountKeepsTheHandleDerivedFromItsId(): void
    {
        self::bootKernel();
        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('D')->setUserId(987654)
            ->setStatus(SubmissionStatus::Pending)->setTitle('Orphan tap')
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $this->em()->persist($sub);
        $this->em()->flush();

        $rows = static::getContainer()->get(SubmissionQueue::class)->filtered(ModerationScope::global(), null, null, null);
        $row = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] === $sub->getId()))[0];
        self::assertSame('rider#'.substr(hash('crc32b', 'cc-sub-987654'), 0, 4), $row['who']);
    }
}
