#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
# Translation completeness gate.
#
# Fails if any enabled non-default locale's catalog is missing keys (or has
# extra keys) compared to the default locale (en). This keeps every user-facing
# string translated across en/fr/nl/de, so an untranslated key can never ship
# silently. Run as part of `make app-test` / CI.
set -euo pipefail
cd "$(dirname "$0")/.."

php <<'PHP'
<?php
require 'vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$dir = 'translations';
$default = 'en';
$locales = ['fr', 'nl', 'de'];

function flat(array $a, string $p = ''): array
{
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : "$p.$k";
        if (is_array($v)) {
            $o += flat($v, $key);
        } else {
            $o[$key] = 1;
        }
    }
    return $o;
}

$base = flat(Yaml::parseFile("$dir/messages.$default.yaml"));
$fail = 0;

foreach ($locales as $l) {
    $t = flat(Yaml::parseFile("$dir/messages.$l.yaml"));
    $missing = array_diff_key($base, $t);
    $extra = array_diff_key($t, $base);

    if ($missing || $extra) {
        $fail = 1;
        fwrite(STDERR, sprintf("[%s] FAIL — %d missing, %d extra vs %s\n", $l, count($missing), count($extra), $default));
        foreach (array_keys($missing) as $k) {
            fwrite(STDERR, "  - missing: $k\n");
        }
        foreach (array_keys($extra) as $k) {
            fwrite(STDERR, "  + extra:   $k\n");
        }
    } else {
        printf("[%s] OK — %d keys, in parity with %s\n", $l, count($t), $default);
    }
}

exit($fail);
PHP
