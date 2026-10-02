<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Vote\VoterEligibility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class VoterEligibilityTest extends KernelTestCase
{
    private const string NOW = '2027-04-10T12:00:00+00:00';

    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function eligibility(): VoterEligibility
    {
        return new VoterEligibility($this->db, new MockClock(new \DateTimeImmutable(self::NOW)));
    }

    private function rider(string $createdAt = '2027-03-01 09:00:00', bool $verified = true): User
    {
        $u = (new User())->setEmail('elig-'.bin2hex(random_bytes(4)).'@test.test')->setDisplayName('Rider');
        $u->setEmailVerified($verified)->setRoles([])->setPassword('not-a-real-hash');
        $this->em->persist($u);
        $this->em->flush();
        $this->db->executeStatement('UPDATE users SET created_at = ? WHERE id = ?', [$createdAt, $u->getId()]);
        $this->em->refresh($u);

        return $u;
    }

    private function rode(User $u): void
    {
        $this->db->executeStatement(
            "INSERT INTO route_ride (route_id, user_id, bike_type, created_at) VALUES (1, ?, 'Road', NOW())",
            [$u->getId()],
        );
    }

    private function confirmed(User $u, string $source): void
    {
        $this->db->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, by_curator, created_at, updated_at)
             VALUES (1, ?, 'exists', ?, false, NOW(), NOW())",
            [$u->getId(), $source],
        );
    }

    private function submitted(User $u, SubmissionStatus $status): void
    {
        $this->em->persist((new Submission())->setType(SubmissionType::Edit)->setLetter('A')
            ->setUserId((int) $u->getId())->setTitle('Surface fix')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([])->setStatus($status));
        $this->em->flush();
    }

    private function proposed(User $u, string $state): void
    {
        $this->db->executeStatement(
            "INSERT INTO recommended_route (name, geom, state, source, source_ref, attributes, proposed_by, created_at, updated_at)
             VALUES ('Proposed loop', ST_SetSRID(ST_GeomFromText('LINESTRING(4.5 50.5, 4.6 50.6)'), 4326), ?, 'user', ?, '{}', ?, NOW(), NOW())",
            [$state, 'user:elig-'.bin2hex(random_bytes(6)), $u->getId()],
        );
    }

    private function corrected(User $u, RouteSuggestionStatus $status): void
    {
        $s = new RouteSuggestion(1, (int) $u->getId(), RouteSuggestionReason::Other, 'The gate is open now.');
        $s->resolve($status, 9);
        $this->em->persist($s);
        $this->em->flush();
    }

    public function testAnEstablishedRiderWhoRodeARouteMayVote(): void
    {
        $u = $this->rider();
        $this->rode($u);
        self::assertSame([], $this->eligibility()->missing($u));
    }

    public function testFourteenDaysToTheSecondIsEnoughAndOneSecondLessIsNot(): void
    {
        $exactly = $this->rider('2027-03-27 12:00:00');
        $this->rode($exactly);
        self::assertSame([], $this->eligibility()->missing($exactly));

        $short = $this->rider('2027-03-27 12:00:01');
        $this->rode($short);
        self::assertSame([VoterEligibility::AGE], $this->eligibility()->missing($short));
        self::assertSame('2027-04-10 12:00:01', $this->eligibility()->votesFrom($short)?->format('Y-m-d H:i:s'));
    }

    public function testAnUnconfirmedEmailCannotVote(): void
    {
        $u = $this->rider(verified: false);
        $this->rode($u);
        self::assertSame([VoterEligibility::EMAIL], $this->eligibility()->missing($u));
    }

    public function testAnAccountThatDidNothingCannotVote(): void
    {
        self::assertSame([VoterEligibility::ACTIVITY], $this->eligibility()->missing($this->rider()));
    }

    public function testEveryKindOfThingDoneCounts(): void
    {
        $confirmed = $this->rider();
        $this->confirmed($confirmed, 'drawer');
        $pendingSubmitter = $this->rider();
        $this->submitted($pendingSubmitter, SubmissionStatus::Pending);
        $needsInfoSubmitter = $this->rider();
        $this->submitted($needsInfoSubmitter, SubmissionStatus::NeedsInfo);
        $approved = $this->rider();
        $this->submitted($approved, SubmissionStatus::Approved);
        $submittedProposer = $this->rider();
        $this->proposed($submittedProposer, 'submitted');
        $unverifiedProposer = $this->rider();
        $this->proposed($unverifiedProposer, 'unverified');
        $verifiedProposer = $this->rider();
        $this->proposed($verifiedProposer, 'verified');
        $retiredProposer = $this->rider();
        $this->proposed($retiredProposer, 'retired');
        $corrector = $this->rider();
        $this->corrected($corrector, RouteSuggestionStatus::Done);

        foreach ([$confirmed, $pendingSubmitter, $needsInfoSubmitter, $approved, $submittedProposer, $unverifiedProposer, $verifiedProposer, $retiredProposer, $corrector] as $u) {
            self::assertSame([], $this->eligibility()->missing($u));
        }
    }

    public function testThingsThatWereNotAcceptedDoNotCount(): void
    {
        $formOnly = $this->rider();
        $this->confirmed($formOnly, 'form');
        $rejectedSubmitter = $this->rider();
        $this->submitted($rejectedSubmitter, SubmissionStatus::Rejected);
        $withdrawnSubmitter = $this->rider();
        $this->submitted($withdrawnSubmitter, SubmissionStatus::Withdrawn);
        $trashedSubmitter = $this->rider();
        $this->submitted($trashedSubmitter, SubmissionStatus::Trashed);
        $rejectedProposer = $this->rider();
        $this->proposed($rejectedProposer, 'rejected');
        $trashedProposer = $this->rider();
        $this->proposed($trashedProposer, 'trashed');
        $pendingCorrection = $this->rider();
        $this->corrected($pendingCorrection, RouteSuggestionStatus::Pending);
        $dismissed = $this->rider();
        $this->corrected($dismissed, RouteSuggestionStatus::Dismissed);
        $trashedCorrection = $this->rider();
        $this->corrected($trashedCorrection, RouteSuggestionStatus::Trashed);

        foreach ([$formOnly, $rejectedSubmitter, $withdrawnSubmitter, $trashedSubmitter, $rejectedProposer, $trashedProposer, $dismissed, $trashedCorrection] as $u) {
            self::assertSame([VoterEligibility::ACTIVITY], $this->eligibility()->missing($u));
        }

        // F2: a pending correction does not count either (only an applied, "done" one does).
        self::assertSame([VoterEligibility::ACTIVITY], $this->eligibility()->missing($pendingCorrection));
    }

    /** The check runs on every ballot page and cast: each table it asks is indexed by rider first. */
    public function testEachActivityTableIsIndexedByRider(): void
    {
        $leading = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT tablename, indexdef FROM pg_indexes WHERE tablename IN ('route_ride', 'item_confirmation', 'submission', 'route_suggestion')",
        ) as $r) {
            if (1 === preg_match('/\((\w+)/', (string) $r['indexdef'], $m) && 'user_id' === $m[1]) {
                $leading[(string) $r['tablename']] = true;
            }
        }
        ksort($leading);

        self::assertSame(['item_confirmation', 'route_ride', 'route_suggestion', 'submission'], array_keys($leading));
    }
}
