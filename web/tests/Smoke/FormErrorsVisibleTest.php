<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Form-LEVEL errors have to reach the page.
 *
 * Found 2026-08-27: every form template rendered `form_errors()` on each field
 * and none on the form itself, so anything Symfony attached to the form root
 * fell on the floor. A rejected CSRF token and the sign-up rate limit both land
 * there, and both came back as the form redrawn with no explanation, which is
 * indistinguishable from the submission being ignored.
 *
 * The scan is the part that matters long-term: a new form added without the
 * include reintroduces the bug silently, and nothing else would catch it.
 */
final class FormErrorsVisibleTest extends WebTestCase
{
    /**
     * Every `form_start($x)` in the templates must be matched by something that
     * renders `$x`'s own errors. Four spellings count, all of them in use:
     * the shared include, `form_errors($x)`, a hand-rolled `$x.vars.errors`
     * loop, or `form($x)` which renders the lot.
     */
    public function testEveryFormRendersItsFormLevelErrors(): void
    {
        $root = \dirname(__DIR__, 2).'/templates';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $offenders = [];
        $formsSeen = 0;

        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with((string) $file, '.twig')) {
                continue;
            }
            $relative = substr((string) $file, \strlen($root) + 1);
            if ('partials/_form_errors.html.twig' === $relative) {
                continue;
            }

            $twig = (string) file_get_contents((string) $file);
            preg_match_all('/form_start\(\s*([a-zA-Z_][a-zA-Z0-9_]*)/', $twig, $matches);

            foreach (array_unique($matches[1]) as $formVariable) {
                ++$formsSeen;
                $quoted = preg_quote($formVariable, '/');
                $rendered = preg_match('/form_errors\(\s*'.$quoted.'\s*\)/', $twig)
                    || preg_match('/\b'.$quoted.'\.vars\.errors/', $twig)
                    || preg_match('/\{\{\s*form\(\s*'.$quoted.'\s*\)/', $twig)
                    || preg_match('/_form_errors\.html\.twig\'\s*with\s*\{\s*form:\s*'.$quoted.'\s*\}/', $twig);

                if (!$rendered) {
                    $offenders[] = $relative.' ('.$formVariable.')';
                }
            }
        }

        // A guard that guards nothing is worse than no guard: if the scan stops
        // finding forms, it stops finding offenders too.
        self::assertGreaterThanOrEqual(10, $formsSeen, 'The template scan found almost no forms, so it is not scanning.');
        self::assertSame([], $offenders, sprintf(
            "These forms drop their form-level errors. Add:\n"
            ."  {%% include 'partials/_form_errors.html.twig' with {form: FORM} %%}\n"
            ."right after form_start(). Offenders:\n  %s",
            implode("\n  ", $offenders)
        ));
    }

    /**
     * The concrete failure the scan exists for: a rejected token used to render
     * a blank page. `csrf_message` is a catalogue key rather than Symfony's
     * English sentence (see `App\Form\Extension\CsrfMessageExtension`), so the
     * assertion is on our copy, in the reader's language.
     */
    public function testARejectedTokenIsExplainedAndNotSwallowed(): void
    {
        $client = static::createClient();
        $client->request('POST', '/register', [
            'registration_form' => [
                'email' => 'token-check@example.test',
                'displayName' => 'TokenCheck',
                'plainPassword' => ['first' => 'Str0ngPassphrase2026', 'second' => 'Str0ngPassphrase2026'],
                'confirmAge' => '1',
                'agreeTerms' => '1',
                '_token' => 'not-a-valid-token',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-errors');
        self::assertSelectorTextContains('.form-errors', 'nothing was sent');
    }

    /**
     * The three error surfaces must take their colour from one place.
     *
     * `.field-errors` and `.alert-error` were copied into
     * `security/_auth_shell_styles.html.twig` and `settings/index.html.twig`,
     * both painted `var(--clay)`, and both measured under the WCAG AA floor on
     * the auth card: 4.12:1 and 4.09:1 at 12.8px and 14.4px. Fixing one copy
     * would have left the other wrong and nobody would have noticed. They live
     * in `atlas.css` now, at `#7d3a20`, measured 5.24 and 5.20 on the same
     * background.
     *
     * This asserts the shape, not the ratio: a real contrast check needs a
     * browser. What it catches is the thing that actually happened, a rule
     * copied back into a page and drifting.
     */
    public function testErrorColoursComeFromTheGlobalSheetOnly(): void
    {
        $root = \dirname(__DIR__, 2);
        $atlas = (string) file_get_contents($root.'/assets/styles/atlas.css');

        foreach (['.field-errors li', '.alert-error{', '.form-errors{'] as $rule) {
            self::assertStringContainsString($rule, $atlas, sprintf('%s should be defined in atlas.css', $rule));
        }
        //  declarations, not every mention: the comment above
        // them names the colour too.
        self::assertSame(
            3,
            preg_match_all('/color:#7d3a20/', $atlas),
            'All three error surfaces should carry the one measured colour.'
        );

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/templates'));
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with((string) $file, '.twig')) {
                continue;
            }
            $twig = (string) file_get_contents((string) $file);
            // A page may still override layout (a margin); redefining the
            // colour is what puts the two copies back out of step.
            if (preg_match('/\.(field-errors|alert-error|form-errors)[^{}]*\{[^}]*color:/', $twig)) {
                $offenders[] = substr((string) $file, \strlen($root) + 11);
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These templates set an error colour of their own. Use the rule in atlas.css:\n  %s",
            implode("\n  ", $offenders)
        ));
    }

    /**
     * Same message, Dutch. The whole point of routing `csrf_message` through the
     * catalogue is that a rider who is not reading English gets told too;
     * Symfony ships its translation in the `validators` domain, which this app
     * does not use (`config/packages/validator.yaml`).
     */
    public function testTheRejectedTokenMessageIsTranslated(): void
    {
        $client = static::createClient();
        $client->request('POST', '/nl/register', [
            'registration_form' => [
                'email' => 'token-check-nl@example.test',
                'displayName' => 'TokenCheckNl',
                'plainPassword' => ['first' => 'Str0ngPassphrase2026', 'second' => 'Str0ngPassphrase2026'],
                'confirmAge' => '1',
                'agreeTerms' => '1',
                '_token' => 'not-a-valid-token',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'er is niets verstuurd');
    }
}
