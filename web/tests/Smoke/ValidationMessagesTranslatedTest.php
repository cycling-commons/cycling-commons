<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Validation messages have to be catalogue keys, not English sentences.
 *
 * Found 2026-08-27: every constraint in `src/Form/` and `src/Entity/` carried
 * its message as a literal English sentence, and none of them had a catalogue
 * entry. `config/packages/validator.yaml` points validation at the `messages`
 * domain, so a key translates with no further wiring - but a sentence is looked
 * up, missed, and returned unchanged. A Dutch rider who mistyped their email was
 * told why in English, on the second page of the site they ever saw.
 *
 * The scan is what keeps it fixed. A new constraint written the old way is a
 * one-line change that nothing else would catch.
 */
final class ValidationMessagesTranslatedTest extends KernelTestCase
{
    /** Every option Symfony treats as a translatable violation message. */
    private const string MESSAGE_OPTIONS = '[a-zA-Z_]*[Mm]essage[a-zA-Z_]*';

    /**
     * A catalogue key here looks like `form.error_email_invalid` or
     * `contribute.error.name_required`: lower case, dotted, no spaces. Anything
     * with a space in it is a sentence somebody typed.
     */
    public function testNoConstraintCarriesAnEnglishSentence(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFiles() as $relative => $php) {
            preg_match_all(
                '/(?:'.self::MESSAGE_OPTIONS.')\s*(?::|=>)\s*\'([^\']+)\'/',
                $php,
                $matches,
                \PREG_OFFSET_CAPTURE
            );

            foreach ($matches[1] as $match) {
                [$message, $offset] = $match;
                ++$scanned;
                if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/', $message)) {
                    continue;
                }
                $line = substr_count(substr($php, 0, $offset), "\n") + 1;
                $offenders[] = sprintf('%s:%d  %s', $relative, $line, $message);
            }
        }

        // A scan that stops finding messages stops finding offenders too.
        self::assertGreaterThanOrEqual(30, $scanned, 'The scan found almost no constraint messages, so it is not scanning.');
        self::assertSame([], $offenders, sprintf(
            "These validation messages are English sentences, so they are shown untranslated in\n"
            ."every other locale. Make each one a catalogue key under `form:` in\n"
            ."translations/messages.*.yaml, five languages, then run app:translations:sync.\n"
            ."Offenders:\n  %s",
            implode("\n  ", $offenders)
        ));
    }

    /**
     * The same mistake in a different spelling: a controller adding an error by
     * hand. `RegistrationController` carried two, the rate limit and the
     * duplicate-address message.
     */
    public function testNoControllerAddsAnEnglishFormError(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $relative => $php) {
            preg_match_all('/new FormError\(\s*\'([^\']+)\'/', $php, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$message, $offset]) {
                if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/', $message)) {
                    continue;
                }
                $line = substr_count(substr($php, 0, $offset), "\n") + 1;
                $offenders[] = sprintf('%s:%d  %s', $relative, $line, $message);
            }
        }

        self::assertSame([], $offenders, "FormError messages must be catalogue keys too:\n  ".implode("\n  ", $offenders));
    }

    /**
     * A key that nothing translates is no better than a sentence: it renders as
     * the key itself, which is worse, because it looks broken rather than
     * merely foreign. English is the source catalogue, so a miss there is a miss
     * everywhere; `LocalizedRoutingTest` and the YAML parity check cover the
     * other four.
     */
    public function testEveryFormErrorKeyExistsInEnglish(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');

        $used = [];
        foreach ($this->phpFiles() as $php) {
            preg_match_all('/\'(form\.error_[a-z0-9_]+)\'/', $php, $m);
            $used = array_merge($used, $m[1]);
        }
        $used = array_values(array_unique($used));
        self::assertNotEmpty($used, 'No form.error_* keys found, so this test proves nothing.');

        $missing = array_values(array_filter(
            $used,
            static fn (string $key): bool => $key === $translator->trans($key, [], null, 'en')
        ));

        self::assertSame([], $missing, "These keys are used but not in messages.en.yaml:\n  ".implode("\n  ", $missing));
    }

    /**
     * @return iterable<string, string> relative path => file contents
     */
    private function phpFiles(): iterable
    {
        $root = \dirname(__DIR__, 2).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with((string) $file, '.php')) {
                yield substr((string) $file, \strlen($root) + 1) => (string) file_get_contents((string) $file);
            }
        }
    }
}
