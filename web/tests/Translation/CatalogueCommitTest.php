<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\CatalogueCommit;
use App\Translation\CatalogueWriter;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Exception\CatalogueProtectedKeyException;
use App\Translation\TranslationCaches;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The catalogue file is the base, so a write to it kills the DB row that
 * was shadowing it (translations.md §7.5).
 *
 * Every test writes into a scratch copy of the fixture catalogues, never
 * into web/translations/messages.*.yaml.
 */
final class CatalogueCommitTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    private const array FIXTURE_LOCALES = ['fr', 'nl'];

    private string $scratchDir;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->scratchDir = sys_get_temp_dir().'/catalogue-commit-test-'.bin2hex(random_bytes(8));
        mkdir($this->scratchDir);
        foreach (self::FIXTURE_LOCALES as $locale) {
            copy(__DIR__."/fixtures/messages.{$locale}.yaml", $this->scratchPath($locale));
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (self::FIXTURE_LOCALES as $locale) {
            @unlink($this->scratchPath($locale));
        }
        @rmdir($this->scratchDir);
        parent::tearDown();
    }

    public function testWritingALocaleValueDeletesThatLocalesOverlayRow(): void
    {
        $em = $this->em();
        $entry = $this->findOrCreateEntry($em, 'blog.kicker', 'From the Commons');
        $em->persist(new TranslationOverlay($entry, 'nl', 'Overlay-tekst', null, null, 1));
        $em->flush();

        $this->commit()->commit($entry, 'nl', 'Nieuwe tekst');

        self::assertNull($this->overlayFor('blog.kicker', 'nl'));
        self::assertStringContainsString("kicker: 'Nieuwe tekst'", $this->scratchBytes('nl'));
    }

    /**
     * The reason this class exists: a hand edit already moved the YAML, so
     * the write is a no-op, and without the eviction the stale overlay would
     * keep shadowing the file exactly as it did before.
     */
    public function testAValueTheFileAlreadyHoldsStillDeletesTheOverlayRow(): void
    {
        $em = $this->em();
        $entry = $this->findOrCreateEntry($em, 'blog.kicker', 'From the Commons');
        $em->persist(new TranslationOverlay($entry, 'nl', 'Overlay-tekst', null, null, 1));
        $em->flush();

        $before = $this->scratchBytes('nl');
        $this->commit()->commit($entry, 'nl', 'Uit de Commons');

        self::assertNull($this->overlayFor('blog.kicker', 'nl'));
        self::assertSame($before, $this->scratchBytes('nl'), 'An identical value must leave the file byte-identical.');
    }

    public function testAnotherLocalesOverlaySurvivesTheWrite(): void
    {
        $em = $this->em();
        $entry = $this->findOrCreateEntry($em, 'blog.kicker', 'From the Commons');
        $em->persist(new TranslationOverlay($entry, 'nl', 'Overlay-tekst', null, null, 1));
        $em->persist(new TranslationOverlay($entry, 'fr', 'Texte overlay', null, null, 1));
        $em->flush();

        $this->commit()->commit($entry, 'nl', 'Nieuwe tekst');

        self::assertNull($this->overlayFor('blog.kicker', 'nl'));
        self::assertNotNull($this->overlayFor('blog.kicker', 'fr'));
    }

    /**
     * The write is attempted first, so a refusal leaves BOTH sides untouched:
     * no half state where the file kept the old wording and the row that was
     * overriding it is gone.
     */
    public function testARefusedWriteLeavesTheOverlayRowInPlace(): void
    {
        $em = $this->em();
        $entry = $this->findOrCreateEntry($em, 'translate.consent.contract', 'Consent contract');
        $em->persist(new TranslationOverlay($entry, 'nl', 'Overlay-tekst', null, null, 1));
        $em->flush();

        $this->expectException(CatalogueProtectedKeyException::class);

        try {
            $this->commit()->commit($entry, 'nl', 'Nieuwe tekst');
        } finally {
            self::assertNotNull($this->overlayFor('translate.consent.contract', 'nl'));
        }
    }

    private function commit(): CatalogueCommit
    {
        return new CatalogueCommit(
            new CatalogueWriter($this->scratchDir, 'dev', '1'),
            $this->em(),
            static::getContainer()->get(TranslationCaches::class),
        );
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function overlayFor(string $key, string $locale): ?TranslationOverlay
    {
        $em = $this->em();
        $em->clear();
        $entry = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => $key]);
        self::assertNotNull($entry);

        return $em->getRepository(TranslationOverlay::class)->findOneBy(['entry' => $entry, 'locale' => $locale]);
    }

    private function scratchPath(string $locale): string
    {
        return $this->scratchDir."/messages.{$locale}.yaml";
    }

    private function scratchBytes(string $locale): string
    {
        return (string) file_get_contents($this->scratchPath($locale));
    }
}
