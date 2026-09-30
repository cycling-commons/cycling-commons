<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Catalog\ChangeHistoryView;
use App\Catalog\Entity\Submission;
use App\Catalog\RiderPseudonym;
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
use Symfony\Component\DomCrawler\Crawler;

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
     * A removed account has no row, so no stored pseudonym: its old work
     * shows one fixed label, never a handle, and nothing is computed from
     * its id.
     */
    public function testARemovedAccountIsNamedByTheRemovedLabelOnTheDesk(): void
    {
        $client = $this->client();
        $curator = $this->user('pseudo-curator@example.com', 'Desk Curator');
        $curator->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $curator->setTwoFaEnabled(true);
        $this->em()->flush();

        $pending = $this->orphanSubmission('Orphan tap', SubmissionStatus::Pending);
        $rejected = $this->orphanSubmission('Orphan rejected tap', SubmissionStatus::Rejected);

        $rows = static::getContainer()->get(SubmissionQueue::class)->filtered(ModerationScope::global(), null, null, null);
        $row = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] === $pending->getId()))[0];
        self::assertNull($row['who'], 'no handle is made up for an account that is gone');
        self::assertSame('', $row['whoUuid']);

        $client->loginUser($curator);
        $crawler = $client->request('GET', '/moderate/submissions');
        self::assertResponseIsSuccessful();
        $card = '.q-item[data-item-id="'.$pending->getId().'"] .q-who';
        self::assertSelectorTextContains($card, 'a removed rider');
        self::assertSelectorTextNotContains($card, 'rider#');
        self::assertSame(0, $crawler->filter($card.' a')->count(), 'a removed rider is never linked');

        $crawler = $client->request('GET', '/moderate/submissions/history');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('.q-item')->reduce(static fn (Crawler $c): bool => str_contains($c->text(), 'Orphan rejected tap'));
        self::assertCount(1, $row);
        self::assertStringContainsString('a removed rider', $row->filter('.q-who')->text());
        self::assertStringNotContainsString('rider#', $row->filter('.q-who')->text());
        self::assertSame(0, $row->filter('.q-who a')->count());
    }

    /** The public change history sends no name for a removed account; the drawer writes the label. */
    public function testThePublicHistoryNamesNoHandleForARemovedAccount(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        $itemId = (int) $db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('D', 'Orphan history tap', ST_SetSRID(ST_MakePoint(5.5, 50.5), 4326), 'BE', 'unverified', 'user', :ref, '{}', NOW(), NOW()) RETURNING id",
            ['ref' => 'user:orphan-'.uniqid()],
        );
        $db->executeStatement(
            "INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at) VALUES (:i, 'name', '\"a\"', '\"b\"', 987654, NOW())",
            ['i' => $itemId],
        );

        $history = static::getContainer()->get(ChangeHistoryView::class)->forItem($itemId);
        self::assertCount(1, $history);
        self::assertNull($history[0]['who']);

        $drawer = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/map/drawer.js');
        self::assertMatchesRegularExpression('/who == null \? \(D\.riderRemoved/', $drawer, 'the drawer writes the removed label for a null name');
    }

    /** No code path turns a user id into a handle. */
    public function testNoHandleIsComputedFromAnId(): void
    {
        self::assertSame(1, (new \ReflectionMethod(RiderPseudonym::class, 'handle'))->getNumberOfParameters());
        self::assertNull(RiderPseudonym::handle(null));
        self::assertNull(RiderPseudonym::handle('RIDER'));
        self::assertSame('rider#k7m2x9qp', RiderPseudonym::handle('k7m2x9qp'));

        $src = \dirname(__DIR__, 2).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            self::assertStringNotContainsString('cc-sub-', (string) file_get_contents($file->getPathname()), $file->getPathname());
        }
    }

    private function orphanSubmission(string $title, SubmissionStatus $status): Submission
    {
        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('D')->setUserId(987654)
            ->setStatus($status)->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        if (SubmissionStatus::Pending !== $status) {
            $sub->setDecidedAt(new \DateTimeImmutable('-1 hour'));
        }
        $this->em()->persist($sub);
        $this->em()->flush();

        return $sub;
    }
}
