<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\LegalPageView;
use App\Legal\LegalVersions;
use App\Legal\PrivacyNoticeVersions;
use App\Legal\TermsVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which text a legal page shows (docs/specs/translations.md §6.2). The text
 * in translations/<page>_next.<locale>.yaml is shown only as the announced
 * text of an upcoming version, behind `?v=next`. A `_next` file with no
 * upcoming version is never shown: neither one dropped in without its
 * version entry nor one left behind after its version came into force.
 */
final class LegalPageViewTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/legal-view-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testANextFileWithoutAnUpcomingVersionIsNeverShown(): void
    {
        $this->writeNext(LegalPageView::LOCALES);
        $view = new LegalPageView($this->dir);
        $afterEveryVersion = new \DateTimeImmutable('2026-12-01');

        foreach ([false, true] as $wantNext) {
            $params = $view->params(self::versions(), $wantNext, $afterEveryVersion);
            self::assertSame('terms', $params['text_domain'], $wantNext ? 'asked for ?v=next' : 'the plain page');
            self::assertFalse($params['preview']);
            self::assertFalse($params['next_available']);
        }
    }

    public function testTheAnnouncedTextIsShownOnlyBehindVNextWhileItsVersionIsUpcoming(): void
    {
        $this->writeNext(LegalPageView::LOCALES);
        $view = new LegalPageView($this->dir);
        $announced = new \DateTimeImmutable('2026-11-15');

        $plain = $view->params(self::versions(), false, $announced);
        self::assertSame('terms', $plain['text_domain']);
        self::assertTrue($plain['next_available']);

        $next = $view->params(self::versions(), true, $announced);
        self::assertSame('terms_next', $next['text_domain']);
        self::assertTrue($next['preview']);
    }

    public function testMissingNextTextsAreNamedPerLanguage(): void
    {
        $this->writeNext(['en', 'nl']);

        self::assertSame(['fr', 'de', 'es'], (new LegalPageView($this->dir))->missingNextTexts('terms'));
    }

    /** @return iterable<string, array{class-string<LegalVersions>}> */
    public static function pages(): iterable
    {
        yield 'privacy' => [PrivacyNoticeVersions::class];
        yield 'terms' => [TermsVersions::class];
    }

    /**
     * A `_next` file in the repository belongs to an announced version. Once
     * that version applies, its text has to move over the main file; until
     * it does, the page shows the old text and this test fails.
     *
     * @param class-string<LegalVersions> $versions
     */
    #[DataProvider('pages')]
    public function testANextFileInTheRepositoryBelongsToAnUpcomingVersion(string $versions): void
    {
        $page = $versions::PAGE;
        $files = glob(\dirname(__DIR__, 2)."/translations/{$page}_next.*.yaml") ?: [];
        if ([] === $files) {
            self::assertTrue(true, 'no announced text in the repository');

            return;
        }

        self::assertNotNull(
            $versions::upcoming(new \DateTimeImmutable()),
            "{$page}_next.*.yaml exists but no {$page} version is announced: move the text over {$page}.<locale>.yaml if its version applies, or add the version entry",
        );
    }

    /** @param list<string> $locales */
    private function writeNext(array $locales): void
    {
        foreach ($locales as $locale) {
            file_put_contents("{$this->dir}/terms_next.{$locale}.yaml", "terms: {}\n");
        }
    }

    /** @return class-string<LegalVersions> */
    private static function versions(): string
    {
        $versions = new class extends LegalVersions {
            public const string PAGE = 'terms';

            #[\Override]
            protected static function entries(): array
            {
                return [
                    ['number' => 4, 'date' => '2026-11-01', 'effective' => '2026-12-01', 'significant' => true, 'changes' => []],
                    ['number' => 3, 'date' => '2026-10-01', 'effective' => '2026-10-01', 'significant' => false, 'changes' => []],
                ];
            }
        };

        return $versions::class;
    }
}
