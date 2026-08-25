<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\CatalogueSync;
use App\Translation\Entity\TranslationEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * English YAML → translation_entry projection (translations.md §1, §3.1).
 */
final class CatalogueSyncTest extends KernelTestCase
{
    private const FIXTURE = __DIR__.'/fixtures/messages.en.yaml';
    private const FIXTURE_UPDATED = __DIR__.'/fixtures/messages.en.updated.yaml';

    public function testSyncUpsertsKeysAndIsIdempotent(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new TranslationEntry('obsolete.key', 'Gone'));
        $em->flush();

        $sync = new CatalogueSync($em, self::FIXTURE);
        self::assertSame(2, $sync->sync());
        self::assertSame(2, $sync->sync());

        $repo = $em->getRepository(TranslationEntry::class);
        $alpha = $repo->findOneBy(['messageKey' => 'alpha']);
        $beta = $repo->findOneBy(['messageKey' => 'beta']);
        $obsolete = $repo->findOneBy(['messageKey' => 'obsolete.key']);

        self::assertNotNull($alpha);
        self::assertSame('Alpha', $alpha->getEnglish());
        self::assertNull($alpha->getAbsentAt());

        self::assertNotNull($beta);
        self::assertSame('Beta', $beta->getEnglish());
        self::assertNull($beta->getAbsentAt());

        self::assertNotNull($obsolete);
        self::assertNotNull($obsolete->getAbsentAt());
    }

    public function testUpdateEnglishAndMarkMissingAbsent(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sync = new CatalogueSync($em, self::FIXTURE);
        $sync->sync();

        $updated = new CatalogueSync($em, self::FIXTURE_UPDATED);
        self::assertSame(2, $updated->sync());

        $repo = $em->getRepository(TranslationEntry::class);
        $alpha = $repo->findOneBy(['messageKey' => 'alpha']);
        $beta = $repo->findOneBy(['messageKey' => 'beta']);
        $nested = $repo->findOneBy(['messageKey' => 'nested.leaf']);

        self::assertNotNull($alpha);
        self::assertSame('Alpha updated', $alpha->getEnglish());
        self::assertNull($alpha->getAbsentAt());

        self::assertNotNull($beta);
        self::assertNotNull($beta->getAbsentAt());

        self::assertNotNull($nested);
        self::assertSame('Leaf', $nested->getEnglish());
        self::assertNull($nested->getAbsentAt());
    }

    public function testAlreadyAbsentKeyIsNotTouchedAgain(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = new TranslationEntry('gone.forever', 'x');
        $entry->markAbsent();
        $firstAbsent = $entry->getAbsentAt();
        $em->persist($entry);
        $em->flush();

        (new CatalogueSync($em, self::FIXTURE))->sync();
        (new CatalogueSync($em, self::FIXTURE))->sync();

        self::assertSame($firstAbsent, $entry->getAbsentAt());
    }
}
