<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Command\ExportEnglishOverlaysCommand;
use App\Translation\Entity\TranslationOverlay;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ExportEnglishOverlaysCommandTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    public function testPrintsLiveEnglishOverlaysAsNestedYaml(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $em->persist(new TranslationOverlay($entry, 'en', 'Map view', null, null, 2));
        $em->flush();

        $out = $this->runCommand();
        self::assertStringContainsString("nav:\n", $out);
        self::assertStringContainsString("  map: 'Map view'", $out);
        self::assertStringContainsString('also update the four locale files', $out);
    }

    public function testASingleSegmentKeyIsATopLevelScalar(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'welcome', 'Welcome');
        $em->persist(new TranslationOverlay($entry, 'en', 'Welcome!', null, null, 2));
        $em->flush();

        $out = $this->runCommand();
        self::assertStringContainsString('welcome: Welcome!', $out);
    }

    public function testTwoKeysSharingAPrefixNestAsSiblings(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $map = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $search = $this->findOrCreateEntry($em, 'nav.search', 'Search');
        $em->persist(new TranslationOverlay($map, 'en', 'Map view', null, null, 2));
        $em->persist(new TranslationOverlay($search, 'en', 'Search the site', null, null, 2));
        $em->flush();

        $out = $this->runCommand();
        // Both live under one "nav:" parent, not two, and the earlier assertion
        // pins that when there is only one key under it there is no ambiguity
        // about ordering. This test is the one that would fail if setPath()
        // ever started overwriting a sibling instead of merging into it.
        self::assertSame(1, substr_count($out, "nav:\n"));
        self::assertStringContainsString("  map: 'Map view'", $out);
        self::assertStringContainsString("  search: 'Search the site'", $out);
    }

    /**
     * Finding 1 (task-11 review): a message key that is both a leaf and the
     * prefix of another key must not silently discard one of them. The old
     * by-reference walk this command used before the Psalm rewrite would
     * have hit a PHP fatal error on this shape (a reference into a string
     * offset); the first version of the recursive rewrite instead
     * overwrote the leaf with an empty array with no error at all. Neither
     * is acceptable for an export a developer is about to paste into the
     * catalogue, so the guard must throw.
     *
     * Exercises `setPath()` directly by reflection rather than through two
     * persisted overlays and the command, because `findBy()` in
     * `execute()` carries no explicit ORDER BY and this collision must be
     * pinned in both directions regardless of which row the repository
     * happens to return first.
     */
    public function testALeafThenAPrefixOfItCollide(): void
    {
        $tree = ['a' => 'leaf value'];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"a\.b".*"a".*leaf value/s');
        $this->callSetPath($tree, ['a', 'b'], 'child value', 'a.b');
    }

    public function testAPrefixThenALeafOfItCollide(): void
    {
        $tree = ['a' => ['b' => 'child value']];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"a"/');
        $this->callSetPath($tree, ['a'], 'leaf value', 'a');
    }

    /** @param array<string, mixed> $tree */
    private function callSetPath(array &$tree, array $path, string $value, string $fullKey): void
    {
        $method = new \ReflectionMethod(ExportEnglishOverlaysCommand::class, 'setPath');
        $method->invokeArgs(null, [&$tree, $path, $value, $fullKey]);
    }

    private function runCommand(): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:translations:english-export'));
        $tester->execute([]);

        return $tester->getDisplay();
    }
}
