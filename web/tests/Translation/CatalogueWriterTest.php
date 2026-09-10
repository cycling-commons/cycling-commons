<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\CatalogueWriter;
use App\Translation\Exception\CatalogueBlockScalarException;
use App\Translation\Exception\CatalogueKeyNotFoundException;
use App\Translation\Exception\CatalogueProtectedKeyException;
use App\Translation\Exception\CatalogueWriteNotOptedInException;
use App\Translation\Exception\CatalogueWriteVerificationException;
use App\Translation\Exception\InvalidLocaleException;
use App\Translation\Exception\NotDevEnvironmentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The surgical single-line catalogue writer (translations.md §7.4).
 *
 * Every test copies the fixture catalogues into a scratch directory first,
 * so a write lands there and never touches the checked-in fixtures under
 * web/tests/Translation/fixtures/, let alone the real
 * web/translations/messages.*.yaml files.
 */
final class CatalogueWriterTest extends TestCase
{
    private const array FIXTURE_LOCALES = ['fr', 'nl'];

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir().'/catalogue-writer-test-'.bin2hex(random_bytes(8));
        mkdir($this->scratchDir);
        foreach (self::FIXTURE_LOCALES as $locale) {
            copy($this->fixturePath($locale), $this->scratchPath($locale));
        }
    }

    protected function tearDown(): void
    {
        foreach (self::FIXTURE_LOCALES as $locale) {
            @unlink($this->scratchPath($locale));
        }
        @unlink($this->scratchEnglishPath());
        rmdir($this->scratchDir);
    }

    private function fixturePath(string $locale): string
    {
        return __DIR__."/fixtures/messages.{$locale}.yaml";
    }

    private function scratchPath(string $locale): string
    {
        return $this->scratchDir."/messages.{$locale}.yaml";
    }

    private function fixtureBytes(string $locale): string
    {
        return (string) file_get_contents($this->fixturePath($locale));
    }

    private function scratchBytes(string $locale): string
    {
        return (string) file_get_contents($this->scratchPath($locale));
    }

    /**
     * A minimal English catalogue for the `allowEnglish` tests, written
     * directly rather than copied from `fixtures/messages.en.yaml`: that
     * fixture is CatalogueSyncTest's, and its `sync()` assertions count the
     * exact keys it holds, so it must stay untouched by this file.
     */
    private function scratchEnglishPath(): string
    {
        return $this->scratchDir.'/messages.en.yaml';
    }

    private function writeEnglishFixture(): void
    {
        file_put_contents($this->scratchEnglishPath(), <<<'YAML'
            # SPDX-License-Identifier: AGPL-3.0-only
            translate:
              consent:
                contract: 'I agree to license this translation under CC BY-SA 4.0 and confirm this is my own work.'
              kicker: 'Translations'
            YAML);
    }

    /**
     * A writer with BOTH gates open unless a test deliberately closes one:
     * the kernel environment, and the CC_CATALOGUE_WRITE opt-in
     * (translations.md §7.1). The opt-in has to be passed explicitly here,
     * because its default is off and off is what every environment that
     * did not ask for it gets.
     */
    private function writer(string $environment = 'dev', string $optIn = '1'): CatalogueWriter
    {
        return new CatalogueWriter($this->scratchDir, $environment, $optIn);
    }

    /**
     * Every line index that differs between the fixture and the scratch
     * copy of $locale, 0-based, in file order.
     *
     * @return list<int>
     */
    private function changedLineIndexes(string $locale): array
    {
        $before = explode("\n", $this->fixtureBytes($locale));
        $after = explode("\n", $this->scratchBytes($locale));

        self::assertSame(\count($before), \count($after), 'a value edit must not add or remove lines');

        $changed = [];
        foreach ($before as $i => $line) {
            if ($line !== $after[$i]) {
                $changed[] = $i;
            }
        }

        return $changed;
    }

    public function testWritesATwoLevelKeyAndLeavesEveryOtherLineByteIdentical(): void
    {
        $this->writer()->write('fr', 'translate.kicker', 'Traductions (brouillon)');

        self::assertSame([27], $this->changedLineIndexes('fr'));
        self::assertSame("  kicker: 'Traductions (brouillon)'", explode("\n", $this->scratchBytes('fr'))[27]);
    }

    public function testWritesAKeyNestedThreeLevelsDeep(): void
    {
        // translate.stale.tag: translate: -> stale: -> tag:, three levels of
        // indentation, the exact shape docs/specs/translations.md §7.4 and
        // the plan both use as the walking example.
        $this->writer()->write('fr', 'translate.stale.tag', 'English changed v%from% -> v%to%');

        self::assertSame([53], $this->changedLineIndexes('fr'));
        self::assertSame(
            "    tag: 'English changed v%from% -> v%to%'",
            explode("\n", $this->scratchBytes('fr'))[53],
        );
    }

    public function testDistinguishesADottedLiteralKeyFromNesting(): void
    {
        // blog.status.draft is stored as one literal key "status.draft"
        // under blog:, not as blog: -> status: -> draft:. Both flatten to
        // the same dotted path, so this proves the walker follows the
        // actual YAML structure rather than guessing from the string.
        $this->writer()->write('fr', 'blog.status.draft', 'Provisional');

        self::assertSame([16], $this->changedLineIndexes('fr'));
        self::assertSame("  status.draft: 'Provisional'", explode("\n", $this->scratchBytes('fr'))[16]);
    }

    public function testFindsTheRightParentWhenTwoSectionsShareALeafName(): void
    {
        // translate.kicker and scout.kicker are both real keys in every
        // catalogue. A regex over the whole file for "  kicker: '...'"
        // would stop at whichever comes first; the indentation walk must
        // not.
        $this->writer()->write('fr', 'scout.kicker', 'Scout, le tagger');

        self::assertSame([121], $this->changedLineIndexes('fr'));
        self::assertSame("  kicker: 'Scout, le tagger'", explode("\n", $this->scratchBytes('fr'))[121]);

        // translate.kicker itself must still read back unchanged.
        self::assertStringContainsString("  kicker: 'Traductions'", $this->scratchBytes('fr'));
    }

    public function testWritesAndReadsBackAValueContainingAnApostrophe(): void
    {
        $this->writer()->write('fr', 'footer.col_atlas', "L'atlas d'aujourd'hui");

        self::assertSame([19], $this->changedLineIndexes('fr'));
        self::assertSame(
            "  col_atlas: 'L''atlas d''aujourd''hui'",
            explode("\n", $this->scratchBytes('fr'))[19],
        );

        // And it parses back to exactly the string that was written, not a
        // mangled one.
        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($this->scratchPath('fr'));
        self::assertSame("L'atlas d'aujourd'hui", $parsed['footer']['col_atlas']);
    }

    public function testWritingTheValueAKeyAlreadyHoldsLeavesTheFileUntouched(): void
    {
        $this->writer()->write('fr', 'translate.kicker', 'Traductions');

        self::assertSame($this->fixtureBytes('fr'), $this->scratchBytes('fr'));
    }

    public function testWritingTheValueADoubleQuotedKeyAlreadyHoldsLeavesTheFileUntouched(): void
    {
        // error404.heading_em is stored double-quoted in the real catalogues
        // (its English contains an apostrophe, so the dumper that produced it
        // chose double quotes rather than escaping). A same-value write must
        // not "fix" that to the single-quoted convention: the byte-identical
        // check on the earlier single-quoted idempotency test would pass even
        // with the early-return in CatalogueWriter::write() deleted, because
        // re-encoding an unchanged single-quoted value reproduces the same
        // bytes either way. A double-quoted line is the case that actually
        // tells the two implementations apart: only the early-return leaves
        // it double-quoted; rewriting it would normalise it to single quotes
        // and this assertion would fail.
        $this->writer()->write('fr', 'error404.heading_em', "n'existe pas");

        self::assertSame($this->fixtureBytes('fr'), $this->scratchBytes('fr'));
    }

    public function testWritingADifferentLocaleTouchesOnlyThatLocalesFile(): void
    {
        $this->writer()->write('nl', 'blog.kicker', 'Uit de Commons (concept)');

        self::assertSame([2], $this->changedLineIndexes('nl'));
        // The fr fixture, untouched by this call, must stay identical.
        self::assertSame($this->fixtureBytes('fr'), $this->scratchBytes('fr'));
    }

    public function testRefusesAnUnknownKey(): void
    {
        $this->expectException(CatalogueKeyNotFoundException::class);
        $this->expectExceptionMessageMatches('/translate\.does_not_exist/');

        $this->writer()->write('fr', 'translate.does_not_exist', 'Anything');
    }

    public function testUnknownKeyRefusalWritesNothing(): void
    {
        try {
            $this->writer()->write('fr', 'translate.does_not_exist', 'Anything');
        } catch (CatalogueKeyNotFoundException) {
            // expected
        }

        self::assertSame($this->fixtureBytes('fr'), $this->scratchBytes('fr'));
    }

    public function testRefusesAKeyThatNamesASectionRatherThanAValue(): void
    {
        $this->expectException(CatalogueKeyNotFoundException::class);
        $this->expectExceptionMessageMatches('/translate\.stale/');

        // translate.stale is a mapping (tag, notice, chip, badge_title live
        // under it); it holds no scalar value of its own to write.
        $this->writer()->write('fr', 'translate.stale', 'Anything');
    }

    public function testRefusesABlockScalar(): void
    {
        $this->expectException(CatalogueBlockScalarException::class);
        $this->expectExceptionMessageMatches('/export\.readme/');

        $this->writer()->write('fr', 'export.readme', 'Anything');
    }

    public function testBlockScalarRefusalWritesNothing(): void
    {
        try {
            $this->writer()->write('fr', 'export.readme', 'Anything');
        } catch (CatalogueBlockScalarException) {
            // expected
        }

        self::assertSame($this->fixtureBytes('fr'), $this->scratchBytes('fr'));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function nonRiderLocaleProvider(): iterable
    {
        yield 'english, the source, never a target (translations.md §7.2)' => ['en'];
        yield 'not a locale this catalogue set carries at all' => ['xx'];
    }

    #[DataProvider('nonRiderLocaleProvider')]
    public function testRefusesANonRiderLocale(string $locale): void
    {
        $this->expectException(InvalidLocaleException::class);
        $this->expectExceptionMessageMatches('/translate\.kicker/');

        $this->writer()->write($locale, 'translate.kicker', 'Anything');
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function nonDevEnvironmentProvider(): iterable
    {
        yield 'test' => ['test'];
        yield 'prod' => ['prod'];
    }

    #[DataProvider('nonDevEnvironmentProvider')]
    public function testRefusesToWriteOutsideTheDevEnvironment(string $environment): void
    {
        $this->expectException(NotDevEnvironmentException::class);
        $this->expectExceptionMessageMatches('/translate\.kicker/');

        $this->writer($environment)->write('fr', 'translate.kicker', 'Anything');
    }

    public function testNonDevRefusalWritesNothingEvenThoughTheKeyIsValid(): void
    {
        try {
            $this->writer('prod')->write('fr', 'translate.kicker', 'Anything');
        } catch (NotDevEnvironmentException) {
            // expected
        }

        self::assertSame($this->fixtureBytes('fr'), $this->scratchBytes('fr'));
    }

    public function testTheEnvironmentCheckComesBeforeTheLocaleCheck(): void
    {
        // Off dev, a non-rider locale must still be reported as the
        // environment refusal, not the locale refusal: on staging or
        // production the feature does not exist at all, regardless of what
        // locale was asked for.
        $this->expectException(NotDevEnvironmentException::class);

        $this->writer('prod')->write('en', 'translate.kicker', 'Anything');
    }

    // --- The CC_CATALOGUE_WRITE opt-in (translations.md §7.1) -------------

    /**
     * @return iterable<string, list<string>>
     */
    public static function optInOffProvider(): iterable
    {
        yield 'unset, which is what the committed web/.env carries' => [''];
        yield 'whitespace only' => ['   '];
        yield 'explicitly off' => ['0'];
        yield 'a value nobody defined: fails closed rather than guessing' => ['maybe'];
    }

    /**
     * The whole point of C1: `dev` alone must not be enough.
     *
     * `web/.env` commits `APP_ENV=dev` and no deployed environment file in
     * this repository overrides it, so a release that lost its server-side
     * override would be "dev" to the application. If that were the only
     * gate, every rider's translation on every non-English locale would land
     * in the shipped catalogue file with no consent record and no proposal
     * row. This test fails the moment the second signal is removed.
     */
    #[DataProvider('optInOffProvider')]
    public function testRefusesToWriteOnDevWithoutTheExplicitOptIn(string $optIn): void
    {
        $this->expectException(CatalogueWriteNotOptedInException::class);
        $this->expectExceptionMessageMatches('/CC_CATALOGUE_WRITE/');

        $this->writer('dev', $optIn)->write('fr', 'translate.kicker', 'Anything');
    }

    #[DataProvider('optInOffProvider')]
    public function testAMissingOptInWritesNothingEvenThoughTheKeyAndLocaleAreValid(string $optIn): void
    {
        try {
            $this->writer('dev', $optIn)->write('fr', 'translate.kicker', 'Anything');
        } catch (CatalogueWriteNotOptedInException) {
            // expected
        }

        self::assertSame($this->fixtureBytes('fr'), $this->scratchBytes('fr'));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function optInOnProvider(): iterable
    {
        yield '1' => ['1'];
        yield 'true' => ['true'];
        yield 'yes, upper case and padded' => ['  YES  '];
        yield 'on' => ['on'];
    }

    #[DataProvider('optInOnProvider')]
    public function testAnExplicitOptInOnDevWrites(string $optIn): void
    {
        $this->writer('dev', $optIn)->write('fr', 'translate.kicker', 'Traductions (brouillon)');

        self::assertSame([27], $this->changedLineIndexes('fr'));
    }

    public function testIsEnabledRequiresBothSignals(): void
    {
        self::assertTrue($this->writer('dev', '1')->isEnabled());
        self::assertFalse($this->writer('dev', '')->isEnabled(), 'dev alone is not enough');
        self::assertFalse($this->writer('prod', '1')->isEnabled(), 'the opt-in alone is not enough');
        self::assertFalse($this->writer('prod', '')->isEnabled());
    }

    public function testTheEnvironmentCheckComesBeforeTheOptInCheck(): void
    {
        // Off dev the feature does not exist at all, so that is what a
        // refusal says, regardless of what the opt-in holds.
        $this->expectException(NotDevEnvironmentException::class);

        $this->writer('prod', '')->write('fr', 'translate.kicker', 'Anything');
    }

    // --- Protected keys (translations.md §4) ------------------------------

    /**
     * @return iterable<string, list<string>>
     */
    public static function protectedKeyProvider(): iterable
    {
        yield 'the translation consent contract' => ['translate.consent.contract'];
        yield 'the media consent contract' => ['media.consent.contract'];
    }

    /**
     * `translate.consent.contract` really is in the fr fixture and really is
     * writable by every other rule this class enforces, so without the
     * ProtectedKeys check this write SUCCEEDS and a machine paraphrase of a
     * binding licence sentence lands in the catalogue, leaving every stored
     * consent record covering words its rider never saw.
     */
    #[DataProvider('protectedKeyProvider')]
    public function testRefusesAProtectedConsentContract(string $messageKey): void
    {
        $this->expectException(CatalogueProtectedKeyException::class);
        $this->expectExceptionMessageMatches('/consent contract/');

        $this->writer()->write('fr', $messageKey, 'Reworded by a machine');
    }

    public function testAProtectedKeyRefusalWritesNothing(): void
    {
        try {
            $this->writer()->write('fr', 'translate.consent.contract', 'Reworded by a machine');
        } catch (CatalogueProtectedKeyException) {
            // expected
        }

        self::assertSame($this->fixtureBytes('fr'), $this->scratchBytes('fr'));
    }

    // --- Hyphenated keys (74 per real catalogue) --------------------------

    public function testWritesAHyphenatedKeysChild(): void
    {
        // regions.limburg-nl.label is the exact shape of 74 lines in every
        // real catalogue. Before the key pattern admitted a hyphen,
        // `limburg-nl:` matched nothing, never joined the indentation stack,
        // and this key was refused as "does not exist", which was false.
        $this->writer()->write('fr', 'regions.limburg-nl.label', 'Limbourg (brouillon)');

        self::assertSame([136], $this->changedLineIndexes('fr'));
        self::assertSame("    label: 'Limbourg (brouillon)'", explode("\n", $this->scratchBytes('fr'))[136]);
    }

    public function testWritesASecondChildOfTheSameHyphenatedKey(): void
    {
        // The sibling below it, so the hyphenated parent is proved to stay
        // on the stack for its whole block rather than for one line.
        $this->writer()->write('fr', 'regions.limburg-nl.blurb', 'Ébauche.');

        self::assertSame([137], $this->changedLineIndexes('fr'));
    }

    public function testAHyphenatedParentDoesNotSwallowTheKeysAroundIt(): void
    {
        // groningen sits before limburg-nl and drenthe after it, both
        // hyphen-free. If a hyphenated line failed to push or failed to pop,
        // one of these would resolve to the wrong line, or to none.
        $this->writer()->write('fr', 'regions.groningen.label', 'Groningue (brouillon)');
        self::assertSame([134], $this->changedLineIndexes('fr'));

        $this->writer()->write('fr', 'regions.drenthe.label', 'Drenthe (brouillon)');
        self::assertSame([134, 141], $this->changedLineIndexes('fr'));

        // And the hyphenated key's own child, between the two, is untouched.
        self::assertStringContainsString("    label: 'Limbourg'", $this->scratchBytes('fr'));
    }

    public function testWritesTheHyphenatedKeyEvenWhenItsLabelIsNotUnique(): void
    {
        // Every region here has a `label:` child. Nothing but the walker's
        // stack tells them apart, so a phantom path would collide silently
        // rather than fail loudly.
        $this->writer()->write('fr', 'regions.noord-brabant.label', 'Brabant (brouillon)');

        self::assertSame([139], $this->changedLineIndexes('fr'));
    }

    // --- The write itself -------------------------------------------------

    public function testAWriteLeavesNoTemporaryFileBehind(): void
    {
        $this->writer()->write('fr', 'translate.kicker', 'Traductions (brouillon)');

        $leftovers = array_values(array_filter(
            scandir($this->scratchDir) ?: [],
            static fn (string $name): bool => str_ends_with($name, '.tmp'),
        ));
        self::assertSame([], $leftovers, 'the atomic write must rename its temporary file, not leave it');
    }

    public function testSelfCheckRefusesAValueThatWouldCorruptTheYamlStructure(): void
    {
        // An honestly-constructed corruption case, not a contrived one: a
        // caller passes a value containing a real newline. Single-quoted
        // encoding only escapes the quote character itself, so the naive
        // line replacement embeds that newline verbatim, splitting one
        // physical line into two. Symfony's YAML parser rejects the result
        // outright (a continuation line has to stay inside the quotes
        // correctly, and this one no longer parses as the same scalar), so
        // the self-check must catch it before anything is written, not
        // discover it only when some other consumer later fails to parse
        // the catalogue.
        $before = $this->fixtureBytes('fr');

        $this->expectException(CatalogueWriteVerificationException::class);
        $this->expectExceptionMessageMatches('/footer\.col_atlas/');

        try {
            $this->writer()->write('fr', 'footer.col_atlas', "Broken\nvalue");
        } finally {
            self::assertSame($before, $this->scratchBytes('fr'), 'a failed self-check must never touch the file');
        }
    }

    // --- English on the dev submit path only, via $allowEnglish
    // (translations.md §7.3) -----------------------------------------------

    public function testRefusesEnglishByDefaultEvenWithBothGatesOpen(): void
    {
        // The ordinary four-locale guard, unchanged: a caller that does not
        // pass $allowEnglish gets exactly the refusal it always got, English
        // included. This is the guard the DeepL draft-all endpoint and any
        // future caller still rely on.
        $this->writeEnglishFixture();

        $this->expectException(InvalidLocaleException::class);
        $this->expectExceptionMessageMatches('/translate\.kicker/');

        $this->writer()->write('en', 'translate.kicker', 'Anything');
    }

    public function testWritesEnglishWhenAllowEnglishIsExplicitlyOn(): void
    {
        $this->writeEnglishFixture();

        $this->writer()->write('en', 'translate.kicker', 'Translations (drafted)', allowEnglish: true);

        self::assertStringContainsString(
            "kicker: 'Translations (drafted)'",
            (string) file_get_contents($this->scratchEnglishPath()),
        );
        // The rider locales this call never touched stay exactly as seeded.
        self::assertSame($this->fixtureBytes('fr'), $this->scratchBytes('fr'));
    }

    public function testAllowEnglishStillRefusesALocaleNoCatalogueSetCarries(): void
    {
        // $allowEnglish widens the guard to the five overlay locales, not to
        // every string: "xx" is neither a rider locale nor English.
        $this->expectException(InvalidLocaleException::class);
        $this->expectExceptionMessageMatches('/translate\.kicker/');

        $this->writer()->write('xx', 'translate.kicker', 'Anything', allowEnglish: true);
    }

    public function testAllowEnglishDoesNotWeakenTheProtectedKeyGuard(): void
    {
        // translate.consent.contract really is in the scratch English file
        // and really is writable by every other rule this class enforces, so
        // without ProtectedKeys this write SUCCEEDS and a machine paraphrase
        // of a binding licence sentence lands in the source catalogue.
        $this->writeEnglishFixture();

        $this->expectException(CatalogueProtectedKeyException::class);
        $this->expectExceptionMessageMatches('/consent contract/');

        $this->writer()->write('en', 'translate.consent.contract', 'Reworded by a machine', allowEnglish: true);
    }

    public function testAllowEnglishStillRequiresDevAndTheOptIn(): void
    {
        // The environment and opt-in gates run before the locale check
        // regardless of $allowEnglish: English is not a backdoor around C1.
        $this->writeEnglishFixture();

        $this->expectException(NotDevEnvironmentException::class);

        $this->writer('prod')->write('en', 'translate.kicker', 'Anything', allowEnglish: true);
    }
}
