<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ChangeHistoryView;
use App\Catalog\Entity\ChangeHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ChangeHistoryViewTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ChangeHistoryView $view;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->view = static::getContainer()->get(ChangeHistoryView::class);
    }

    private function seed(int $itemId, string $field, mixed $old, mixed $new, int $changedBy, string $at): ChangeHistory
    {
        $history = (new ChangeHistory())
            ->setItemId($itemId)
            ->setField($field)
            ->setOldValue($old)
            ->setNewValue($new)
            ->setChangedBy($changedBy);
        $this->em->persist($history);
        $this->em->flush();

        // ChangeHistory's constructor always stamps "now" — pin changed_at
        // directly so ordering is deterministic (same technique as
        // ImportCatalogCommandTest).
        $this->em->getConnection()->executeStatement(
            'UPDATE change_history SET changed_at = :at WHERE id = :id',
            ['at' => $at, 'id' => $history->getId()],
        );
        $this->em->clear();

        return $history;
    }

    public function testReturnsNewestFirstWithCorrectShape(): void
    {
        $itemId = 424242;
        $this->seed($itemId, 'hours', '24/7', 'closed Sundays', 9, '2026-07-01 10:00:00');
        $this->seed($itemId, 'pump', null, 'yes', 9, '2026-07-03 10:00:00');

        $rows = $this->view->forItem($itemId);

        self::assertCount(2, $rows);

        self::assertSame('pump', $rows[0]['field'], 'newest first');
        self::assertNull($rows[0]['oldValue']);
        self::assertSame('yes', $rows[0]['newValue']);
        self::assertMatchesRegularExpression('/^rider#[0-9a-f]{4}$/', $rows[0]['who']);
        self::assertNotSame('', $rows[0]['when']);
        self::assertSame('2026-07-03T10:00:00+00:00', $rows[0]['changedAt']);

        self::assertSame('hours', $rows[1]['field']);
        self::assertSame('24/7', $rows[1]['oldValue']);
        self::assertSame('closed Sundays', $rows[1]['newValue']);
    }

    public function testUnknownItemReturnsEmptyList(): void
    {
        self::assertSame([], $this->view->forItem(999999999));
    }

    public function testLimitCapsResults(): void
    {
        $itemId = 424243;
        for ($i = 1; $i <= 3; ++$i) {
            $this->seed($itemId, 'f'.$i, 'a', 'b', 1, sprintf('2026-07-0%d 10:00:00', $i));
        }

        self::assertCount(2, $this->view->forItem($itemId, 2));
    }
}
