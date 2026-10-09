<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Legal;

use Symfony\Component\Yaml\Yaml;

/**
 * The texts the terms of use include by reference, each pinned by hash to the
 * terms version that last accepted it.
 *
 * The terms rest on these texts as much as on translations/terms.*.yaml: the
 * contributor terms (licenses/COMMONS-TERMS-CLAUSE.md, terms "Your
 * contributions"), the trademark policy (TRADEMARK.md, terms "Our code &
 * brand"), and the licences page the terms link for the licences: its
 * template, its text (translations/licenses.<locale>.yaml, one file per
 * language), and the licence texts it links. A significant change to any of them changes the
 * deal, so it follows the same rule as the terms (LegalVersions): a
 * significant terms version, emailed LegalVersions::NOTICE_DAYS days before it
 * applies with `app:legal:announce terms <version>`, and the changed text
 * lands on or after the day it applies. An editorial change (a typo, a link,
 * markup) is accepted by adding a pin with a written reason. Either way the
 * new pin goes on top of the list for that text, here, where git review sees
 * it. TermsIncludedTextsTest fails while a text differs from its newest pin.
 *
 * Paths are relative to the repository root and a file is hashed whole. A
 * `#key` suffix names one top-level subtree of a YAML catalogue instead,
 * hashed by content so key order does not count.
 *
 * @see docs/specs/translations.md §6.2
 *
 * @api
 */
final class TermsIncludedTexts
{
    /** The text as it stood when the terms version named was published. */
    public const string KIND_ACCEPTED = 'accepted';
    /** A changed text that rests on a significant terms version, announced 30 days ahead. */
    public const string KIND_SIGNIFICANT = 'significant';
    /** A changed text that changes nobody's rights: re-pinned with the reason. */
    public const string KIND_EDITORIAL = 'editorial';

    private const string BASELINE = 'Accepted with terms version 2, the first version that names the texts it includes (2026-10-09).';

    private const string MOVED = 'The licences page text left the site-translatable messages domain for its own git-only file per language, verbatim (owner 2026-10-09); no wording changed. Its keys and values hash to the earlier pin of the licenses subtree of the messages catalogue: ';

    private const string MOVED_TEMPLATE = 'The template reads its text from the licenses domain (trans_default_domain) and says where that text lives, in a comment; no word on the page changed (owner 2026-10-09).';

    /**
     * Newest pin first, per text.
     *
     * @var array<string, non-empty-list<array{sha256: string, terms: int, date: string, kind: string, reason: string}>>
     */
    public const array PINS = [
        'licenses/COMMONS-TERMS-CLAUSE.md' => [
            ['sha256' => '0c18cf063507bd06c764fe77ea84edd9233965ec4c4ea5ec34586d9deeb1ccab', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'TRADEMARK.md' => [
            ['sha256' => '3f993694717599c88e280b7a2f43625497edf98a7d0e54be454567859e033eb4', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'LICENSE' => [
            ['sha256' => 'd8a6cc31abc16b6748c7a21f21611f5a1ec33f67d22ca23d7da1c19b95496bee', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'licenses/COMMONS-DATA-LICENSE.md' => [
            ['sha256' => '849b5280105e84f1c7a340832dcedfb469bad853314b3e2c8c6c0f12045e9c48', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'licenses/COMMONS-MEDIA-LICENSE.md' => [
            ['sha256' => '8581d038631af7891331d33af652e4ce3fee714821951679bf12cbc574c45841', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'licenses/COMMONS-TRANSLATIONS-LICENSE.md' => [
            ['sha256' => '065f2557ac55049c31a03f34e5bbe0d574b2578b73d4dcbf8c1abb392be7c6ef', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'licenses/ODbL-1.0.txt' => [
            ['sha256' => '77d2692c3d64efdd4db18dce2407699baf62930e2b50d6e5ed90b48acf16b7c1', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'licenses/DbCL-1.0.txt' => [
            ['sha256' => 'bb5717df1c1a83174c641a5a376d7005bcb81cd727ae61d7bfc32bf8de753ae3', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'licenses/CC-BY-SA-4.0.txt' => [
            ['sha256' => 'cde7883b9050a1104f4ac19a1572aafd6e5d7323b68351aaf51fbf4beba54966', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'web/templates/pages/licenses.html.twig' => [
            ['sha256' => 'fce6dc284db76f61afabd1e10f65908cdd5777e7f5cce072038511ec4bad4209', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => self::MOVED_TEMPLATE],
            ['sha256' => '8b313f0cc784122c89c29a4295056b2cc5f54b4a0ac26ada2fe4f8aae2403cc9', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_ACCEPTED, 'reason' => self::BASELINE],
        ],
        'web/translations/licenses.en.yaml' => [
            ['sha256' => '14b7e88e5ee8e8d688a191c164c303621259cf6829df982ab9b9ee764de51a21', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => 'Punctuation only: dashes became commas or a hyphen (no em-dashes in our copy); no word, right or licence changed.'],
            ['sha256' => '23790edeb79a19f4148558758dba99168eb8945e499a6c4d9646830ded950336', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => self::MOVED.'827baa999e470f1ad8dbe4dcae14172dabab41c8c7b9b4b9f07efd0e00bcea44.'],
        ],
        'web/translations/licenses.fr.yaml' => [
            ['sha256' => 'f03b3780ab1ea8e80f1d4b4783ac8aa29948e591df2ddb08b8f980362ffe1573', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => 'Punctuation only: dashes became commas or a hyphen (no em-dashes in our copy); no word, right or licence changed.'],
            ['sha256' => '73efb5a7632ca57aa79f113c98aeb39b9a3cafc41cd327252c573a9da3da356f', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => self::MOVED.'589b3d2cb92ab9fa9bae14812615e59702cbe4fc030c56e05adce4cdf8f4f5bb.'],
        ],
        'web/translations/licenses.nl.yaml' => [
            ['sha256' => 'ad70f54069bf68320f75c14d716e8b07745bc0a7600021b8dfd25401ded93e55', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => 'Punctuation only: dashes became commas or a hyphen (no em-dashes in our copy); no word, right or licence changed.'],
            ['sha256' => '0188ae7dd42d14fb057491f109967bc746e6b4c03ba072797abf74df5ae544b6', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => self::MOVED.'823737552f26094269157af2fc274359a0daaac3f7bab886da00370e7824fe05.'],
        ],
        'web/translations/licenses.de.yaml' => [
            ['sha256' => 'd8109fdb0f468447c35d612fb02f5d6beb61203f1af80bc16e1acded4aa3d3a2', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => 'Punctuation only: dashes became commas or a hyphen (no em-dashes in our copy); no word, right or licence changed.'],
            ['sha256' => 'ec3b33d79972cc4a528e75e818bf91f0356fa63cc8160e79c78fb9ec7c151be8', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => self::MOVED.'a34a02e545ad4524a06e7189fadc1b7b677d8247e7ed8daa4754680e8855cbd6.'],
        ],
        'web/translations/licenses.es.yaml' => [
            ['sha256' => '9819e56704cf2cc7f690692aa789aa7cbb11c7cddc4730a787a9a3cc0d986385', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => 'Punctuation only: dashes became commas or a hyphen (no em-dashes in our copy); no word, right or licence changed.'],
            ['sha256' => '9b3da4979cf6a590b80f13b27eb60274a3a3483226d8a4bde56a5e02a79ee82e', 'terms' => 2, 'date' => '2026-10-09', 'kind' => self::KIND_EDITORIAL, 'reason' => self::MOVED.'1d2ad2f2fa78c4e78d60528c51af31009db3ec6218562d0f3e3857be8c85e8e3.'],
        ],
    ];

    /** The text's hash as it is now, or null when its file is not in this checkout. */
    public static function currentHash(string $text): ?string
    {
        [$path, $subtree] = array_pad(explode('#', $text, 2), 2, null);
        $file = self::resolve($path);
        if (!is_file($file)) {
            return null;
        }
        if (null !== $subtree) {
            $catalogue = Yaml::parseFile($file);
            $node = \is_array($catalogue) ? ($catalogue[$subtree] ?? null) : null;

            return self::subtreeHash(\is_array($node) ? $node : []);
        }

        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($file)));
    }

    /**
     * A catalogue subtree's hash: its keys and values, in key order.
     *
     * @param array<array-key, mixed> $node
     */
    public static function subtreeHash(array $node): string
    {
        return hash('sha256', json_encode(self::sorted($node), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    /** What to do when a text no longer matches its newest pin. */
    public static function changedMessage(string $text, string $current): string
    {
        $terms = TermsVersions::latest()['number'];
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $days = LegalVersions::NOTICE_DAYS;

        return <<<TXT
            {$text} changed, and the terms include it by reference (docs/specs/translations.md §6.2).
            A significant change (a new rule, a changed right or licence, anything a contributor or reuser would decide differently on) needs a new significant terms version in TermsVersions, announced at least {$days} days before it applies with `bin/console app:legal:announce terms <version>`; the changed text lands on or after the day that version applies, with a pin of kind 'significant' naming it.
            A purely editorial change (a typo, a broken link, markup) may be accepted by adding a pin on top of this text's list in App\\Legal\\TermsIncludedTexts, with the reason written out:
                ['sha256' => '{$current}', 'terms' => {$terms}, 'date' => '{$today}', 'kind' => self::KIND_EDITORIAL, 'reason' => '<why this changes nobody\\'s rights>'],
            TXT;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private static function sorted(array $node): array
    {
        ksort($node, \SORT_STRING);
        foreach ($node as $key => $value) {
            if (\is_array($value)) {
                $node[$key] = self::sorted($value);
            }
        }

        return $node;
    }

    private static function resolve(string $path): string
    {
        // web/ is this application; everything else sits beside it at the repository root.
        return str_starts_with($path, 'web/')
            ? \dirname(__DIR__, 2).'/'.substr($path, 4)
            : \dirname(__DIR__, 3).'/'.$path;
    }
}
