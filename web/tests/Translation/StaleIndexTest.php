<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationOverlay;
use App\Translation\ProtectedKeys;
use App\Translation\StaleIndex;
use App\Translation\TranslationCaches;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StaleIndexTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    public function testYamlServedKeyIsStaleOnlyAfterAnInSiteEnglishChange(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'stale.yaml', 'Photos up to 5 MB');
        $em->flush();
        $index = static::getContainer()->get(StaleIndex::class);
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();

        self::assertFalse($index->isStale((int) $entry->getId(), 'nl'));

        $entry->applyApprovedEnglish('Photos up to 10 MB');
        $em->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();

        self::assertTrue($index->isStale((int) $entry->getId(), 'nl'));
        self::assertTrue($index->isStale((int) $entry->getId(), 'fr'));
    }

    public function testOverlayMadeAgainstOlderVersionIsStaleAndNewerOneIsNot(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'stale.overlay', 'Photos up to 5 MB');
        $entry->applyApprovedEnglish('Photos up to 10 MB'); // v2
        $em->persist(new TranslationOverlay($entry, 'nl', "Foto's tot 5 MB", null, null, 1));
        $em->persist(new TranslationOverlay($entry, 'fr', 'Photos jusqu\'à 10 Mo', null, null, 2));
        $em->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();

        $index = static::getContainer()->get(StaleIndex::class);
        self::assertTrue($index->isStale((int) $entry->getId(), 'nl'));
        self::assertFalse($index->isStale((int) $entry->getId(), 'fr'));

        $list = $index->listFor('nl');
        $row = array_values(array_filter($list, static fn (array $r): bool => 'stale.overlay' === $r['message_key']))[0];
        self::assertSame(2, $row['english_version']);
        self::assertSame(1, $row['made_against']);
    }

    /**
     * A consent contract is never stale work.
     *
     * {@see ProtectedKeys} changes by a VERSION bump in code and nowhere
     * else, and {@see \App\Translation\ProposalService::submit()} refuses a
     * proposal for one, so a protected key in this list would be an item on
     * the curator stale list, and a number in the account chip's badge, that
     * nobody can ever act on. {@see \App\Translation\CatalogueBrowser} has
     * always excluded them; this index had not, and the two copies of the
     * predicate drifting is exactly what the exclusion is pinned against
     * (translations.md §3.3, §4).
     */
    public function testAProtectedKeyIsNeverStale(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $key = ProtectedKeys::KEYS[0];
        $entry = $this->findOrCreateEntry($em, $key, 'I agree.');
        // An approved English edit leaves every locale behind the English,
        // so without the exclusion this row WOULD be listed.
        $entry->applyApprovedEnglish('I agree, reworded.');
        $em->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();

        $index = static::getContainer()->get(StaleIndex::class);
        $id = (int) $entry->getId();

        self::assertFalse($index->isStale($id, 'nl'));
        self::assertArrayNotHasKey($id, $index->ids('nl'));
        self::assertSame(
            [],
            array_values(array_filter($index->listFor('nl'), static fn (array $r): bool => $key === $r['message_key'])),
            $key.' is a consent contract and must never be offered as stale work',
        );
    }
}
