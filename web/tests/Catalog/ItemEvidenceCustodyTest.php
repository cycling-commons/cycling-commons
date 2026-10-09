<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CustodyTier;
use App\Catalog\Entity\ChangeHistory;
use App\Catalog\ItemEvidenceResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An OSM copy a person changed is our own item (data-provider-hierarchy.md
 * §6.7.7, owner 2026-10-08), read through the real selectSql() against the
 * database: a field a person changed counts, through the history or through
 * an approved submission; the system clock and a verification do not.
 */
final class ItemEvidenceCustodyTest extends KernelTestCase
{
    private const int RIDER = 4242;

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function osmRow(): int
    {
        return (int) $this->db()->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('P', 'Custody test', ST_SetSRID(ST_MakePoint(5.1, 50.1), 4326), 'BE', 'unverified', 'osm', :ref, '{}', NOW(), NOW())
             RETURNING id",
            ['ref' => 'node/'.random_int(900000000, 999999999)],
        );
    }

    private function history(int $item, string $field, ?int $by): void
    {
        $this->db()->executeStatement(
            "INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at)
             VALUES (:item, :field, '\"a\"', '\"b\"', :by, NOW())",
            ['item' => $item, 'field' => $field, 'by' => $by],
        );
    }

    /** @param array<string, mixed> $changes */
    private function submission(int $item, string $status, array $changes, ?int $by = self::RIDER, ?string $trashedFrom = null): void
    {
        $this->db()->executeStatement(
            "INSERT INTO submission (type, letter, item_id, user_id, status, title, geom, country_code, changes, payload, created_at, trashed_from)
             VALUES ('new', 'P', :item, :by, :status, 'Custody test', ST_SetSRID(ST_MakePoint(5.1, 50.1), 4326), 'BE', CAST(:changes AS jsonb), '{}', NOW(), :from)",
            ['item' => $item, 'by' => $by, 'status' => $status, 'changes' => json_encode((object) $changes, \JSON_THROW_ON_ERROR), 'from' => $trashedFrom],
        );
    }

    private function custody(int $item): CustodyTier
    {
        /** @var array{state: string, source: string, imported_at: string|null, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_curator?: bool|null, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null, ev_edited?: bool|null} $row */
        $row = $this->db()->fetchAssociative(
            'SELECT i.state, i.source, i.letter, i.imported_at, '.ItemEvidenceResolver::selectSql('i').' FROM item i WHERE i.id = :id',
            ['id' => $item],
        );

        return static::getContainer()->get(ItemEvidenceResolver::class)->fromRow($row, new \DateTimeImmutable())->custody;
    }

    public function testAnUntouchedOsmCopyIsGross(): void
    {
        self::bootKernel();

        self::assertSame(CustodyTier::Gross, $this->custody($this->osmRow()));
    }

    public function testAPersonsFieldEditMakesItOurs(): void
    {
        self::bootKernel();
        $item = $this->osmRow();
        $this->history($item, 'name', self::RIDER);

        self::assertSame(CustodyTier::Ours, $this->custody($item));
    }

    public function testAnEditOrASubmissionByADeletedAccountStillMakesItOurs(): void
    {
        self::bootKernel();
        $edited = $this->osmRow();
        $this->history($edited, 'name', null);
        $submitted = $this->osmRow();
        $this->submission($submitted, 'approved', ['note' => ['was' => null, 'now' => 'A fine view']], null);

        self::assertSame(CustodyTier::Ours, $this->custody($edited), 'the edit stays when the editor goes');
        self::assertSame(CustodyTier::Ours, $this->custody($submitted), 'and so does the approved change');
    }

    public function testTheSystemClockOrAStateRowLeavesItGross(): void
    {
        self::bootKernel();
        $item = $this->osmRow();
        $this->history($item, 'state', ChangeHistory::SYSTEM_ACTOR);
        $this->history($item, 'state', self::RIDER);
        $this->history($item, 'note', ChangeHistory::SYSTEM_ACTOR);

        self::assertSame(CustodyTier::Gross, $this->custody($item));
    }

    public function testAnApprovedSubmissionThatChangedAFieldMakesItOurs(): void
    {
        // A rider who adds a place from an OSM point: the approval writes only a `state` history row.
        self::bootKernel();
        $item = $this->osmRow();
        $this->history($item, 'state', self::RIDER);
        $this->submission($item, 'approved', ['type' => ['was' => null, 'now' => 'waterfall']]);

        self::assertSame(CustodyTier::Ours, $this->custody($item));
    }

    public function testAnApprovedSubmissionInTrashStillCounts(): void
    {
        self::bootKernel();
        $item = $this->osmRow();
        $this->submission($item, 'trashed', ['note' => ['was' => null, 'now' => 'A fine view']], trashedFrom: 'approved');

        self::assertSame(CustodyTier::Ours, $this->custody($item));
    }

    public function testASubmissionThatChangedNothingOrIsUndecidedLeavesItGross(): void
    {
        self::bootKernel();
        $item = $this->osmRow();
        // Materialised from the OSM point with its values as they were.
        $this->submission($item, 'approved', ['type' => ['was' => 'waterfall', 'now' => 'waterfall']]);
        $this->submission($item, 'approved', []);
        $this->submission($item, 'pending', ['note' => ['was' => null, 'now' => 'Not decided']]);
        $this->submission($item, 'rejected', ['note' => ['was' => null, 'now' => 'Refused']]);
        $this->submission($item, 'approved', ['note' => ['was' => null, 'now' => 'By the system']], ChangeHistory::SYSTEM_ACTOR);

        self::assertSame(CustodyTier::Gross, $this->custody($item));
    }
}
