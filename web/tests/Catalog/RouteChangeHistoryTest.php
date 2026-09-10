<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\RouteChangeHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Route-scoped append-only history (spec D9): routes live outside the item
 * pipeline, so they get their own history table rather than reusing
 * change_history (whose item_id FKs `item`).
 */
final class RouteChangeHistoryTest extends KernelTestCase
{
    public function testHistoryRowRoundTripsAndIsImmutableAfterConstruct(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $row = new RouteChangeHistory(routeId: 77, field: 'state', oldValue: 'submitted', newValue: 'unverified', changedBy: 4242);
        $em->persist($row);
        $em->flush();
        $em->clear();

        /** @var RouteChangeHistory $reloaded */
        $reloaded = $em->getRepository(RouteChangeHistory::class)->findOneBy(['routeId' => 77]);
        self::assertSame('state', $reloaded->getField());
        self::assertSame('submitted', $reloaded->getOldValue());
        self::assertSame('unverified', $reloaded->getNewValue());
        self::assertSame(4242, $reloaded->getChangedBy());
        self::assertNotNull($reloaded->getCreatedAt());
    }
}
