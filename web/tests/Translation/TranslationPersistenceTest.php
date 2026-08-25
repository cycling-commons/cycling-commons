<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TranslationPersistenceTest extends KernelTestCase
{
    public function testEntryRoundTripAndUniqueKey(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = new TranslationEntry('home.cta_map', 'Explore the map');
        $em->persist($a);
        $em->flush();
        $em->clear();
        $found = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => 'home.cta_map']);
        self::assertNotNull($found);
        self::assertSame('Explore the map', $found->getEnglish());
    }

    public function testOverlayUniquePerEntryAndLocale(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = new TranslationEntry('nav.map', 'Map');
        $em->persist($entry);
        $em->flush();
        $em->persist(new TranslationOverlay($entry, 'fr', 'Carte', null, null));
        $em->flush();
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $em->persist(new TranslationOverlay($entry, 'fr', 'La carte', null, null));
        $em->flush();
    }
}
