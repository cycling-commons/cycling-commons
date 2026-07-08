<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The moderated correction channel (spec §4.2 route_suggestion): riders report
 * a route problem (preset reason + note); curators resolve done/dismissed.
 * Riders never edit route data (D3).
 */
final class RouteSuggestionTest extends KernelTestCase
{
    public function testSuggestionStartsPendingAndResolves(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $s = new RouteSuggestion(routeId: 12, userId: 99, reason: RouteSuggestionReason::BrokenTrack, note: 'Track jumps the river.');
        self::assertSame(RouteSuggestionStatus::Pending, $s->getStatus());
        $em->persist($s);
        $em->flush();

        $s->resolve(RouteSuggestionStatus::Done, curatorId: 4242);
        $em->flush();
        $em->clear();

        /** @var RouteSuggestion $reloaded */
        $reloaded = $em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => 12]);
        self::assertSame(RouteSuggestionStatus::Done, $reloaded->getStatus());
        self::assertSame(4242, $reloaded->getResolvedBy());
        self::assertNotNull($reloaded->getResolvedAt());
        self::assertSame(RouteSuggestionReason::BrokenTrack, $reloaded->getReason());
    }
}
