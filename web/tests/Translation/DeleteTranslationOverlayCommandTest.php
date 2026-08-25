<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\OverlayCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Ops revert-to-YAML: delete one overlay row (translations.md §9).
 */
final class DeleteTranslationOverlayCommandTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDryRunKeepsOverlayAndWriteDeletesReturningYaml(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $em->persist(new TranslationOverlay($entry, 'fr', 'Carte (overlay)', null, null));
        $em->flush();

        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');

        $t = static::getContainer()->get('translator');
        self::assertSame('Carte (overlay)', $t->trans('nav.map', [], 'messages', 'fr'));

        $dry = $this->runCommand(['locale' => 'fr', 'key' => 'nav.map']);
        $dry->assertCommandIsSuccessful();
        self::assertStringContainsString('--write', $dry->getDisplay());

        $em->clear();
        $entryAgain = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => 'nav.map']);
        self::assertNotNull($entryAgain);
        self::assertNotNull(
            $em->getRepository(TranslationOverlay::class)->findOneBy([
                'entry' => $entryAgain,
                'locale' => 'fr',
            ]),
        );
        self::assertSame('Carte (overlay)', $t->trans('nav.map', [], 'messages', 'fr'));

        $write = $this->runCommand(['locale' => 'fr', 'key' => 'nav.map', '--write' => true]);
        $write->assertCommandIsSuccessful();

        $em->clear();
        $entryAgain = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => 'nav.map']);
        self::assertNotNull($entryAgain);
        self::assertNull(
            $em->getRepository(TranslationOverlay::class)->findOneBy([
                'entry' => $entryAgain,
                'locale' => 'fr',
            ]),
        );
        self::assertSame('Carte', $t->trans('nav.map', [], 'messages', 'fr'));
    }

    public function testLocaleEnIsRefused(): void
    {
        $tester = $this->runCommand(['locale' => 'en', 'key' => 'nav.map']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('en', $tester->getDisplay());
    }

    public function testUnknownKeyErrors(): void
    {
        $tester = $this->runCommand(['locale' => 'fr', 'key' => 'does.not.exist.at.all']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testNoOverlayWarnsAndSucceeds(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->findOrCreateEntry($em, 'nav.about', 'About');

        $tester = $this->runCommand(['locale' => 'fr', 'key' => 'nav.about', '--write' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/no overlay|nothing to delete/i', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $args
     */
    private function runCommand(array $args): CommandTester
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:translations:overlay-delete'),
        );
        $tester->execute($args);

        return $tester;
    }
}
