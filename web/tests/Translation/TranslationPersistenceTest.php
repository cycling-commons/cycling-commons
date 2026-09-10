<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
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
        $em->persist(new TranslationOverlay($entry, 'fr', 'Carte', null, null, 1));
        $em->flush();
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $em->persist(new TranslationOverlay($entry, 'fr', 'La carte', null, null, 1));
        $em->flush();
    }

    public function testNewEntryStartsAtVersionOneWithYamlEqualToEnglish(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = new TranslationEntry('test.versions.new', 'Photos up to 5 MB');
        $em->persist($entry);
        $em->flush();
        $em->clear();

        $found = $em->find(TranslationEntry::class, $entry->getId());
        self::assertSame(1, $found->getEnglishVersion());
        self::assertSame(1, $found->getYamlEnglishVersion());
        self::assertSame('Photos up to 5 MB', $found->getEnglishYaml());
        self::assertSame('Photos up to 5 MB', $found->getEnglish());
    }

    public function testApprovedEnglishBumpsVersionButNotYamlVersion(): void
    {
        $entry = new TranslationEntry('test.versions.approve', 'Photos up to 5 MB');
        $entry->applyApprovedEnglish('Photos up to 10 MB');

        self::assertSame(2, $entry->getEnglishVersion());
        self::assertSame(1, $entry->getYamlEnglishVersion());
        self::assertSame('Photos up to 10 MB', $entry->getEnglish());
        self::assertSame('Photos up to 5 MB', $entry->getEnglishYaml());
    }

    public function testGitEnglishBumpsBothVersions(): void
    {
        $entry = new TranslationEntry('test.versions.git', 'Photos up to 5 MB');
        $entry->applyGitEnglish('Photos up to 10 MB', new \DateTimeImmutable());

        self::assertSame(2, $entry->getEnglishVersion());
        self::assertSame(2, $entry->getYamlEnglishVersion());
        self::assertSame('Photos up to 10 MB', $entry->getEnglish());
        self::assertSame('Photos up to 10 MB', $entry->getEnglishYaml());
    }

    public function testAbsorbGitEnglishKeepsVersionAndMarksYamlFresh(): void
    {
        $entry = new TranslationEntry('test.versions.absorb', 'Photos up to 5 MB');
        $entry->applyApprovedEnglish('Photos up to 10 MB'); // v2, yaml v1
        $entry->absorbGitEnglish('Photos up to 10 MB', new \DateTimeImmutable());

        self::assertSame(2, $entry->getEnglishVersion());
        self::assertSame(2, $entry->getYamlEnglishVersion());
        self::assertSame('Photos up to 10 MB', $entry->getEnglishYaml());
    }

    public function testEnglishOverlayIsAcceptedByTheDatabase(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $overlay = new TranslationOverlay($entry, 'en', 'Map view', null, null, 1);
        $em->persist($overlay);
        $em->flush();

        self::assertNotNull($overlay->getId());
        self::assertSame(1, $overlay->getEnglishVersion());
    }

    public function testProposalWithoutConsentIsAccepted(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $proposal = new TranslationProposal($entry, 'en', 'Map view', 'Map', null, null, 1);
        $em->persist($proposal);
        $em->flush();

        self::assertNull($proposal->getConsentRecordId());
        self::assertSame(1, $proposal->getEnglishVersionAtSubmit());
    }
}
