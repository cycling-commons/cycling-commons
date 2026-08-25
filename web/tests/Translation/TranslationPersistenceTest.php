<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TranslationPersistenceTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    public function testEntryRoundTripAndUniqueKey(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->findOrCreateEntry($em, 'home.cta_map', 'Explore the map');
        $em->clear();
        $found = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => 'home.cta_map']);
        self::assertNotNull($found);
        self::assertSame('Explore the map', $found->getEnglish());

        // Prove uniq_translation_entry_message_key without colliding with CI sync.
        $em->persist(new TranslationEntry('test.unique.fixture.only', 'Once'));
        $em->flush();
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $em->persist(new TranslationEntry('test.unique.fixture.only', 'Twice'));
        $em->flush();
    }

    public function testOverlayUniquePerEntryAndLocale(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $em->persist(new TranslationOverlay($entry, 'fr', 'Carte', null, null));
        $em->flush();
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $em->persist(new TranslationOverlay($entry, 'fr', 'La carte', null, null));
        $em->flush();
    }
}
