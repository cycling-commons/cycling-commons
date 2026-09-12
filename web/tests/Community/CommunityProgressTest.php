<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\BikeType;
use App\Catalog\ConfirmationSource;
use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\ItemConfirmation;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteRide;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Community\CommunityProgress;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The spin-out counters, checked the way the governance page promises them:
 * adding and verifying weigh the same, one account on one place counts once,
 * staff never count on either side, and the GitHub half and the database
 * half of the contributor count both feed the page.
 *
 * The translation_proposal arm of the contributors union is not seeded here:
 * it needs the consent_record + translation_entry chain, and its SQL is the
 * same shape as the curator_post arm, which is covered. The targets are read
 * from wiki/governance.md so the page and the code cannot drift apart.
 */
final class CommunityProgressTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDataPointsWeighAddingAndVerifyingTheSame(): void
    {
        $alice = $this->user('alice@example.test');
        $bob = $this->user('bob@example.test');
        $item = $this->item();

        // Alice adds the place; her own confirmation of it must not double-count.
        $this->approvedSubmission($alice->getId(), $item->getId());
        $this->confirmation($item->getId(), $alice->getId());
        // Bob stands behind the same place: that is a second rider backing.
        $this->confirmation($item->getId(), $bob->getId());
        // Alice also rides a route: a third act, on another number space.
        $route = $this->route();
        $this->em->persist(new RouteRide($route->getId(), $alice->getId(), BikeType::Road));
        $this->em->flush();

        self::assertSame(3, $this->progress()->dataPoints());
    }

    public function testDataPointsExcludeStaffAccounts(): void
    {
        $staff = $this->user('staff@example.test');
        $item = $this->item();
        $this->confirmation($item->getId(), $staff->getId());

        self::assertSame(0, $this->progress()->dataPoints());
    }

    public function testContributorsMergeGitHubAndSiteHalvesByEmail(): void
    {
        $alice = $this->user('alice@example.test'); // curator writing
        $bob = $this->user('bob@example.test');    // curatorship
        $carol = $this->user('carol@example.test'); // both halves, one person

        // Alice and Bob are curators (role granted)
        $this->grantRole($alice->getId());
        $this->grantRole($bob->getId());
        // Bob applied and was accepted for a region: the appointment itself
        // is the reviewed contribution, no decision has to follow it.
        $this->curatorship($bob->getId());
        // Alice writes a curator post.
        $this->db->insert('curator_post', [
            'author_id' => $alice->getId(),
            'body' => 'Bath gravel notes.',
            'created_at' => '2026-09-10 12:00:00+00',
        ]);
        // Carol ships code and curates: one email in both halves is one person.
        $this->curatorship($carol->getId());
        $this->githubLogin('carol', 'carol@example.test');
        // Dave ships code but hides his email: he counts on his login key.
        $this->githubLogin('dave');
        $this->githubLogin('staff-login');

        self::assertSame(4, $this->progress(staffLogins: ['staff-login'])->summary()['contributors']);
    }

    /**
     * The GitHub-arm key: LOWER(COALESCE(NULLIF(email, ''), github_login)).
     * Each branch of that expression is a real GitHub state — published
     * email, hidden email (NULL), and an explicitly blank one ('', which is
     * why NULLIF exists and a bare COALESCE would not) — and DISTINCT is
     * what makes one person with two accounts one contributor.
     */
    public function testGithubArmKeyFallsBackToLoginAndCollapsesSharedEmails(): void
    {
        // Published email, mixed case in both fields: LOWER must fold them.
        $this->githubLogin('Alice', 'Alice@Example.ORG');
        // Hidden email: NULL falls back to the lower-cased login.
        $this->githubLogin('bob');
        // Blank email: '' is not a key, NULLIF turns it into the login.
        $this->githubLogin('carol', '');
        // Alice's second account, same email: DISTINCT collapses it away.
        $this->githubLogin('mallory', 'alice@example.org');

        // alice + bob + carol + mallory-as-alice-collapsed = 3 people.
        self::assertSame(3, $this->progress()->contributors());
    }

    /**
     * Appointing a moderator must not read as a new external contributor.
     *
     * The site half counts any account holding a role other than ROLE_USER,
     * so without the role exclusion the counter would rise by one every time
     * the project granted somebody a steward right, which is the opposite of
     * what the trigger measures. A curator is NOT excluded: curating is the
     * contribution, and only the steward roles are listed.
     */
    public function testAnAccountHoldingAStewardRoleIsNeverAContributor(): void
    {
        $curator = $this->user('curator@example.test');
        $moderator = $this->user('moderator@example.test');
        $this->grantRole($curator->getId());
        $this->grantRole($moderator->getId(), 'ROLE_MODERATOR');

        self::assertSame(1, $this->progress()->contributors(), 'the curator counts, the moderator does not');
    }

    /**
     * The same rule on the other counter: a steward's own confirmations and
     * rides are not rider-backed data points either.
     */
    public function testAStewardRolesDataPointsAreNotCounted(): void
    {
        $moderator = $this->user('moderator@example.test');
        $this->grantRole($moderator->getId(), 'ROLE_ADMIN');
        $item = $this->item();
        $this->confirmation($item->getId(), $moderator->getId());

        self::assertSame(0, $this->progress()->dataPoints());
    }

    /** With no roles listed, nobody is excluded for holding one. */
    public function testNoExcludedRolesMeansNobodyIsExcludedByRole(): void
    {
        $moderator = $this->user('moderator@example.test');
        $this->grantRole($moderator->getId(), 'ROLE_MODERATOR');

        self::assertSame(1, $this->progress(staffRoles: [])->contributors());
    }

    public function testTargetsMatchTheGovernancePage(): void
    {
        $page = file_get_contents(__DIR__.'/../../../wiki/governance.md');
        self::assertNotFalse($page, 'wiki/governance.md must exist for the parity check');
        $summary = $this->progress()->summary();

        self::assertStringContainsString(
            (string) $summary['target_contributors'].' unique external', $page,
            'commitment 3 must name the contributor target the code uses');
        self::assertStringContainsString(
            number_format($summary['target_data_points']), $page,
            'commitment 3 must name the data-point target the code uses');
    }

    /**
     * @param list<string> $staffLogins
     * @param list<string> $staffRoles
     */
    private function progress(array $staffLogins = [], array $staffRoles = ['ROLE_ADMIN', 'ROLE_MODERATOR']): CommunityProgress
    {
        return new CommunityProgress(
            $this->db,
            25,
            12500,
            ['staff@example.test'],
            $staffLogins,
            $staffRoles,
        );
    }

    private function user(string $email): User
    {
        $u = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function item(): Item
    {
        $item = (new Item())->setLetter('w')->setName('Fountain')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Verified)->setSource(ItemSource::User)
            ->setSourceRef('node/'.bin2hex(random_bytes(4)))->setAttributes([]);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function route(): RecommendedRoute
    {
        $route = (new RecommendedRoute())->setName('Loop')
            ->setGeom('{"type":"LineString","coordinates":[[5.86,50.47],[5.87,50.48]]}')
            ->setState(ItemState::Verified)
            ->setSource(ItemSource::User)->setSourceRef('way/'.bin2hex(random_bytes(4)))
            ->setAttributes([]);
        $this->em->persist($route);
        $this->em->flush();

        return $route;
    }

    private function approvedSubmission(int $userId, int $itemId): void
    {
        $s = (new Submission())->setType(SubmissionType::NewItem)->setLetter('w')
            ->setTitle('A tap')->setUserId($userId)->setItemId($itemId)
            ->setStatus(SubmissionStatus::Approved)->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode('BE');
        $this->em->persist($s);
        $this->em->flush();
    }

    private function confirmation(int $itemId, int $userId): void
    {
        $this->em->persist(new ItemConfirmation($itemId, $userId, ConfirmationStance::Exists, ConfirmationSource::Drawer));
        $this->em->flush();
    }

    private function curatorship(int $userId): void
    {
        $this->db->insert('curator_application', [
            'user_id' => $userId,
            'country_code' => 'BE',
            'about' => 'I ride every week here.',
            'status' => 'approved',
            'created_at' => '2026-09-10 12:00:00+00',
        ]);
    }

    private function grantRole(int $userId, string $role = 'ROLE_CURATOR'): void
    {
        $user = $this->em->find(User::class, $userId);
        $user->setRoles([$role]);
        $this->em->flush();
    }

    private function githubLogin(string $login, ?string $email = null): void
    {
        $this->db->insert('github_contributor', [
            'github_login' => $login,
            'first_pr_at' => '2026-09-01 12:00:00+00',
            'last_pr_at' => '2026-09-01 12:00:00+00',
            'email' => $email,
        ]);
    }
}
