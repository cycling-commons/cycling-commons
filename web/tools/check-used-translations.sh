#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
# Fails if a template or PHP file asks for a `messages` key that no catalogue
# holds.
#
# tools/check-translations.sh compares the five catalogues with each other, so
# a key missing from all five passes it, and the page then prints the raw key
# (the 404 page showed "error404.cta_map" on production). This asks the
# translation extractor which keys the code uses and fails on any that en
# lacks; parity then carries the answer to fr/nl/de/es.
#
# Only the `messages` domain is checked: the extractor files form-constraint
# messages under `validators` and dynamic keys under `_undefined`, which are
# translated from `messages` at runtime and would read as false misses here.
set -euo pipefail
cd "$(dirname "$0")/.."

out="$(php -d memory_limit=1G bin/console debug:translation en --only-missing --domain=messages 2>&1 || true)"
missing="$(printf '%s\n' "$out" | awk '$1 == "missing" { print $3 }')"

if [ -n "$missing" ]; then
    echo "Translation keys used in code but missing from messages.en.yaml:" >&2
    printf '  - %s\n' $missing >&2
    exit 1
fi

echo "Every messages key used in code exists in messages.en.yaml."
