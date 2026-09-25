<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\User;
use App\Tests\Coverage\CoverageRunSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What the harvest did last night, read from the two tracker tables the
 * pipeline writes. Admin-only, read-only, nothing new measured.
 *
 * @see docs/specs/coverage-runs-admin.md
 */
final class CoverageRunsTest extends WebTestCase
{
    use CoverageRunSchema;

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

    private function listUrl(): string
    {
        return static::getContainer()->get('router')->generate('admin_coverage_runs');
    }

    private function runUrl(int $id): string
    {
        return static::getContainer()->get('router')->generate('admin_coverage_run', ['id' => $id]);
    }

    private function db(): Connection
    {
        $db = static::getContainer()->get(Connection::class);
        self::ensureCoverageRunSchema($db);

        return $db;
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $client = static::createClient();
        $this->db();
        $client->request('GET', $this->listUrl());

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testACuratorIsForbidden(): void
    {
        $client = static::createClient();
        $this->db();
        $client->loginUser($this->createUser('runs-curator@example.com', ['ROLE_CURATOR']));
        $client->request('GET', $this->listUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheListNamesTheTriggerAndTheRebuiltCountries(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::insertCoverageRun($db);

        $client->loginUser($this->createUser('runs-admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $client->request('GET', $this->listUrl());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('dispatcher', $html);
        self::assertStringContainsString('be,nl', $html);
        self::assertStringContainsString('2 of 2', $html, 'regions loaded of requested');
    }

    public function testTheListNamesEachRunsTileFamily(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::insertCoverageRun($db, ['family' => 'routes', 'url' => 'be']);
        self::insertCoverageRun($db, ['family' => 'points', 'url' => 'nl']);

        $client->loginUser($this->createUser('runs-family@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $crawler = $client->request('GET', $this->listUrl());

        self::assertResponseIsSuccessful();
        $cells = $crawler->filter('td[data-family]')->each(static fn ($td) => $td->attr('data-family').'='.trim($td->text()));
        sort($cells);
        self::assertSame(['points=points', 'routes=routes'], $cells);
    }

    public function testARunLeftRunningForHalfADayReadsAbandoned(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::insertCoverageRun($db, ['status' => 'running', 'started' => '13 hours',
            'finished' => null, 'loaded' => null, 'url' => null]);

        $client->loginUser($this->createUser('runs-abandoned@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $client->request('GET', $this->listUrl());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('abandoned', $html, 'a killed run never finishes its row');
        self::assertStringNotContainsString('>running<', $html);
    }

    public function testARunStillRunningReadsRunning(): void
    {
        $client = static::createClient();
        $db = $this->db();
        self::insertCoverageRun($db, ['status' => 'running', 'started' => '20 minutes',
            'finished' => null, 'loaded' => null, 'url' => null]);

        $client->loginUser($this->createUser('runs-running@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $client->request('GET', $this->listUrl());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('running', $html);
        self::assertStringNotContainsString('abandoned', $html);
    }

    public function testARunPageShowsItsStepsAndWhatEachRuleDropped(): void
    {
        $client = static::createClient();
        $db = $this->db();
        $runId = self::insertCoverageRun($db);
        self::insertCoverageRunStep($db, $runId, ['step' => 'download', 'seconds' => 412.0,
            'bytes' => 4_200_000_000, 'detail' => null]);
        self::insertCoverageRunStep($db, $runId, ['step' => 'load', 'seconds' => 655.0, 'rows' => 31204,
            'detail' => '{"previous": 1402118, "dropped": {"name:P": 1363, "exclude:Q:memorial": 826, "near_way": 69}}']);

        $client->loginUser($this->createUser('runs-detail@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $client->request('GET', $this->runUrl($runId));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('europe/france', $html);
        self::assertStringContainsString('download', $html);
        self::assertStringContainsString('31,204', $html, 'rows are grouped for reading');
        self::assertStringContainsString('1,402,118', $html, 'the previous count');
        self::assertStringContainsString('P needs a name', $html);
        self::assertStringContainsString('1,363', $html);
        self::assertStringContainsString('Q excludes memorial', $html);
        self::assertStringContainsString('826', $html);
        self::assertStringContainsString('near-way', $html);
        self::assertStringContainsString('69', $html);
    }

    public function testADetailWrittenBeforeTheJsonChangeIsShownAsText(): void
    {
        $client = static::createClient();
        $db = $this->db();
        $runId = self::insertCoverageRun($db);
        self::insertCoverageRunStep($db, $runId, ['step' => 'load', 'rows' => 7, 'detail' => 'previous 5']);

        $client->loginUser($this->createUser('runs-legacy@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $client->request('GET', $this->runUrl($runId));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('previous 5', (string) $client->getResponse()->getContent());
    }

    public function testAFailedStepShowsItsExceptionInsteadOfNumbers(): void
    {
        $client = static::createClient();
        $db = $this->db();
        $runId = self::insertCoverageRun($db, ['status' => 'partial', 'loaded' => 1]);
        self::insertCoverageRunStep($db, $runId, ['step' => 'load', 'status' => 'failed',
            'detail' => 'DriftAbort: 1 row vs 100 previously']);

        $client->loginUser($this->createUser('runs-failed@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $client->request('GET', $this->runUrl($runId));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('DriftAbort: 1 row vs 100 previously', (string) $client->getResponse()->getContent());
    }

    public function testAnUnknownRunIs404(): void
    {
        $client = static::createClient();
        $this->db();
        $client->loginUser($this->createUser('runs-404@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $client->request('GET', $this->runUrl(987654));

        self::assertResponseStatusCodeSame(404);
    }
}
