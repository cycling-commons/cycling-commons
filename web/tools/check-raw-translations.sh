#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
# Fails if any tracked Twig template pipes a translation straight into |raw.
#
# security-architecture.md §3: "|trans|raw is banned." security-architecture.md
# §4.1 permits exactly three |raw categories: |rich output, json_encode(...)|raw
# into a nonced inline script, and escaped catalogue text with a template-built
# element substituted in (the one site: web/templates/translate/_form.html.twig).
# Anything else reaching |raw from a translation is a finding.
#
# What this catches (grep-level, single Twig statement):
#   - '<key>'|trans(...)|raw            (filter form)
#   - trans('<key>', ...)|raw           (function form)
#   - {% apply raw %}{% trans %}...{% endtrans %}{% endapply %}  (block form)
# and it does NOT flag the chain when |rich or json_encode sits between the
# translation and |raw. Those are two of the three §4.1 sanctioned categories;
# the third (escaped catalogue text with a template-built element substituted
# in) never reaches |raw in the same statement, so it is out of this script's
# reach by construction, not by an allowlist entry.
#
# What this deliberately does NOT catch: the indirect, cross-statement shape
# where a translation is escaped into a variable on one line and that variable
# is |raw'd on another (or built via {% set x %}...{% endset %} and |raw'd
# later). That needs dataflow analysis, not grep, and grep-matching it would
# also flag the one reviewed, deliberately safe site that uses exactly this
# shape: web/templates/translate/_form.html.twig sets `standing_text` from an
# escaped translation with a placeholder standing in for %date%, then
# substitutes its own <time> markup for that placeholder before |raw'ing the
# assembled string (security-architecture.md §4.1, category 3). A human
# reviewer, not this script, is what verifies a new indirect site is safe.
set -euo pipefail
cd "$(dirname "$0")/.."

hits=0

# --- 1. Single-statement filter/function form: |trans(...)|raw, trans(...)|raw ---
# Lazily scan from "{{" to "|trans"/"trans(", then lazily onward to "|raw",
# refusing to cross a "}}", a "|rich", or a "json_encode" on the way there.
FILTER_PATTERN='\{\{(?:(?!\}\}).)*?(?:\|trans\b|\btrans\s*\()(?:(?!\}\}|\|rich\b|json_encode\b).)*\|raw\b'

while IFS= read -r f; do
  case "$f" in
    */vendor/*|*/var/*) continue ;;
  esac
  while IFS=: read -r line rest; do
    [ -z "${line:-}" ] && continue
    echo "RAW TRANSLATION: $f:$line: $rest"
    hits=1
  done < <(grep -nP "$FILTER_PATTERN" "$f")
done < <(git ls-files 'templates/*.twig')

# --- 2. Block form: {% apply raw %} ... {% trans %} ... {% endapply %} ---
# Line-based state machine (an awk one-liner would lose the file name across
# files, so this loop keeps one file per pass).
while IFS= read -r f; do
  case "$f" in
    */vendor/*|*/var/*) continue ;;
  esac
  if ! awk -v fname="$f" '
    BEGIN { in_raw_apply = 0; start = 0; saw_trans = 0; found = 0 }
    /\{%-?[ \t]*apply[ \t]+[^%]*\braw\b[^%]*-?%\}/ {
      in_raw_apply = 1; start = NR; saw_trans = 0; startline = $0
    }
    in_raw_apply && /\{%-?[ \t]*trans\b/ {
      saw_trans = 1; transline = $0
    }
    in_raw_apply && /\{%-?[ \t]*endapply[ \t]*-?%\}/ {
      if (saw_trans) {
        printf "RAW TRANSLATION (block form): %s:%d: %s\n", fname, start, startline
        printf "  ...reaches %s:%d: %s\n", fname, NR, transline
        found = 1
      }
      in_raw_apply = 0
    }
    END { exit (found ? 1 : 0) }
  ' "$f"; then
    hits=1
  fi
done < <(git ls-files 'templates/*.twig')

if [ "$hits" -eq 0 ]; then
  echo "no |trans|raw occurrences (security-architecture.md §3, §4.1)"
fi

exit "$hits"
