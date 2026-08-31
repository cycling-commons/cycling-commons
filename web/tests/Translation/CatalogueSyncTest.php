<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\CatalogueSync;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\TranslationCaches;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * English YAML → translation_entry projection (translations.md §1, §3.1, §3.3).
 */
final class CatalogueSyncTest extends KernelTestCase
{
    private const FIXTURE = __DIR__.'/fixtures/messages.en.yaml';
    private const FIXTURE_UPDATED = __DIR__.'/fixtures/messages.en.updated.yaml';

    /** @var list<string> */
    private array $tempFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    /** @param array<string, string> $messages */
    private function writeYaml(array $messages): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cc-sync-').'.yaml';
        file_put_contents($path, Yaml::dump($messages));
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testSyncUpsertsKeysAndIsIdempotent(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new TranslationEntry('obsolete.key', 'Gone'));
        $em->flush();

        $sync = new CatalogueSync($em, self::FIXTURE, new NullLogger(), static::getContainer()->get(TranslationCaches::class));
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
        $sync = new CatalogueSync($em, self::FIXTURE, new NullLogger(), static::getContainer()->get(TranslationCaches::class));
        $sync->sync();

        $updated = new CatalogueSync($em, self::FIXTURE_UPDATED, new NullLogger(), static::getContainer()->get(TranslationCaches::class));
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

        (new CatalogueSync($em, self::FIXTURE, new NullLogger(), static::getContainer()->get(TranslationCaches::class)))->sync();
        (new CatalogueSync($em, self::FIXTURE, new NullLogger(), static::getContainer()->get(TranslationCaches::class)))->sync();

        self::assertSame($firstAbsent, $entry->getAbsentAt());
    }

    public function testSyncStampsSuppliedNowOnAllTouchedRows(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new TranslationEntry('obsolete.key', 'Gone', new \DateTimeImmutable('2020-01-01T00:00:00+00:00')));
        $em->persist(new TranslationEntry('alpha', 'Old Alpha', new \DateTimeImmutable('2020-01-01T00:00:00+00:00')));
        $em->flush();

        $now = new \DateTimeImmutable('2026-08-25T12:00:00+00:00');
        (new CatalogueSync($em, self::FIXTURE, new NullLogger(), static::getContainer()->get(TranslationCaches::class)))->sync($now);

        $repo = $em->getRepository(TranslationEntry::class);
        $alpha = $repo->findOneBy(['messageKey' => 'alpha']);
        $beta = $repo->findOneBy(['messageKey' => 'beta']);
        $obsolete = $repo->findOneBy(['messageKey' => 'obsolete.key']);

        self::assertNotNull($alpha);
        self::assertEquals($now, $alpha->getSyncedAt());
        self::assertNull($alpha->getAbsentAt());

        self::assertNotNull($beta);
        self::assertEquals($now, $beta->getSyncedAt());
        self::assertNull($beta->getAbsentAt());

        self::assertNotNull($obsolete);
        self::assertEquals($now, $obsolete->getSyncedAt());
        self::assertEquals($now, $obsolete->getAbsentAt());
    }

    public function testGitChangeBumpsVersionAndMarksYamlFresh(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $path = $this->writeYaml(['sync.v.git' => 'Photos up to 5 MB']);
        (new CatalogueSync($em, $path, new NullLogger(), static::getContainer()->get(TranslationCaches::class)))->sync();
        $entry = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => 'sync.v.git']);
        self::assertSame(1, $entry->getEnglishVersion());

        $path = $this->writeYaml(['sync.v.git' => 'Photos up to 10 MB']);
        (new CatalogueSync($em, $path, new NullLogger(), static::getContainer()->get(TranslationCaches::class)))->sync();
        $em->refresh($entry);
        self::assertSame(2, $entry->getEnglishVersion());
        self::assertSame(2, $entry->getYamlEnglishVersion());
        self::assertSame('Photos up to 10 MB', $entry->getEnglish());
    }

    public function testGitCatchingUpDropsTheEnglishOverlayWithoutABump(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $path = $this->writeYaml(['sync.v.absorb' => 'Photos up to 5 MB']);
        (new CatalogueSync($em, $path, new NullLogger(), static::getContainer()->get(TranslationCaches::class)))->sync();
        $entry = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => 'sync.v.absorb']);
        $entry->applyApprovedEnglish('Photos up to 10 MB'); // v2
        $em->persist(new TranslationOverlay($entry, 'en', 'Photos up to 10 MB', null, null, 2));
        $em->flush();

        $path = $this->writeYaml(['sync.v.absorb' => 'Photos up to 10 MB']);
        (new CatalogueSync($em, $path, new NullLogger(), static::getContainer()->get(TranslationCaches::class)))->sync();
        $em->refresh($entry);

        self::assertSame(2, $entry->getEnglishVersion());
        self::assertSame(2, $entry->getYamlEnglishVersion());
        self::assertSame('Photos up to 10 MB', $entry->getEnglishYaml());
        self::assertNull($em->getRepository(TranslationOverlay::class)->findOneBy(['entry' => $entry, 'locale' => 'en']));
    }

    public function testGitDisagreeingWinsAndLogs(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $path = $this->writeYaml(['sync.v.conflict' => 'Photos up to 5 MB']);
        (new CatalogueSync($em, $path, new NullLogger(), static::getContainer()->get(TranslationCaches::class)))->sync();
        $entry = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => 'sync.v.conflict']);
        $entry->applyApprovedEnglish('Photos up to 10 MB'); // v2
        $em->persist(new TranslationOverlay($entry, 'en', 'Photos up to 10 MB', null, null, 2));
        $em->flush();

        $logger = new RecordingLogger();
        $path = $this->writeYaml(['sync.v.conflict' => 'Photos up to 20 MB']);
        (new CatalogueSync($em, $path, $logger, static::getContainer()->get(TranslationCaches::class)))->sync();
        $em->refresh($entry);

        self::assertSame(3, $entry->getEnglishVersion());
        self::assertSame('Photos up to 20 MB', $entry->getEnglish());
        self::assertNull($em->getRepository(TranslationOverlay::class)->findOneBy(['entry' => $entry, 'locale' => 'en']));
        self::assertTrue($logger->hasWarningThatContains('sync.v.conflict'));
    }
}
