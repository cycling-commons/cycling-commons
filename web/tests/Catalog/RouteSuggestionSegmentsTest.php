<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\RouteSuggestionReason;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RouteSuggestionSegmentsTest extends KernelTestCase
{
    public function testPersistsSegmentsAsJsonb(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $s = new RouteSuggestion(1, 2, RouteSuggestionReason::BrokenTrack, 'gate here', [
            ['start' => 0.1, 'end' => 0.25],
            ['start' => 0.6, 'end' => 0.72],
        ]);
        $em->persist($s);
        $em->flush();
        $em->clear();

        $found = $em->find(RouteSuggestion::class, $s->getId());
        $segments = $found->getSegments();
        self::assertNotNull($segments);

        // JSONB is a canonical binary format: PostgreSQL does not preserve the
        // original key insertion order of nested objects (observed: shorter
        // keys first). Normalize both sides before comparing so the assertion
        // checks segment values, not incidental key ordering.
        $normalize = static function (array $rows): array {
            foreach ($rows as &$row) {
                ksort($row);
            }

            return $rows;
        };
        self::assertSame(
            $normalize([['start' => 0.1, 'end' => 0.25], ['start' => 0.6, 'end' => 0.72]]),
            $normalize($segments),
        );
    }

    public function testSegmentsDefaultNull(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $s = new RouteSuggestion(1, 2, RouteSuggestionReason::Other, null);
        $em->persist($s);
        $em->flush();
        $em->clear();

        self::assertNull($em->find(RouteSuggestion::class, $s->getId())->getSegments());
    }
}
