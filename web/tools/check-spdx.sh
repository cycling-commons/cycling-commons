#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# The licence gate for the Symfony app. It runs from web/ (the script cds to
# its own parent) and reads the files git tracks there, skipping vendor/ and
# var/, which are not ours.
#
# Two passes, because they answer two different questions.
#
#   Presence, over src/*.php, tests/*.php and templates/*.twig: a new source
#   file has to declare a licence at all.
#
#   Value, over every tracked source file that declares one: the identifier has
#   to name a licence this project actually publishes under. Presence alone is
#   not enough. A relicensing sweep that stops halfway leaves headers that look
#   perfectly well formed while naming a licence the repo has left behind, and
#   a gate that only counts lines waves every one of them through.
set -euo pipefail
cd "$(dirname "$0")/.."

# The identifiers a file here may declare. Keep this list in step with the
# LICENSES/ directory at the repo root: REUSE resolves every identifier used in
# the tree against a text file in there, so an identifier missing from LICENSES/
# names a licence no reader can look up.
ALLOWED_IDENTIFIERS='AGPL-3.0-only BSD-3-Clause CC-BY-4.0 CC-BY-SA-4.0 CC0-1.0 LicenseRef-CyclingCommons-Brand MIT ODbL-1.0 OFL-1.1'

# Extensions that carry an inline header. Everything else under web/ (binaries,
# fonts, the flag SVGs, fixtures) takes its licence from REUSE.toml instead,
# which `reuse lint` checks: `make licenses-check`.
SOURCE_GLOBS=('*.php' '*.twig' '*.js' '*.cjs' '*.mjs' '*.css' '*.scss' '*.json' '*.md' '*.neon' '*.py' '*.sh' '*.sql' '*.svg' '*.xml' '*.yaml' '*.yml')

# Deliberately without the trailing colon: written with one, REUSE would read
# this script's own grep pattern as its licence declaration.
TAG='SPDX-License-Identifier'

status=0

# This gate reads the index, so it needs a repository. Without one, git prints a
# fatal, the pipelines below produce nothing, and the script would exit 0 having
# checked not a single file. A gate that passes because it could not look is
# worse than no gate, so refuse instead. (It bites when the script is run inside
# the app container, whose /app bind mount carries no .git; run it on the host,
# in CI, or from the pre-push hook.)
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "check-spdx: not inside a git repository, so there is no index to read." >&2
  echo "  Run this on the host or in CI, not inside the app container." >&2
  exit 2
fi

# Tracked paths matching the given git pathspecs, NUL separated, minus the two
# directories we never own.
list_tracked() {
  git ls-files -z -- "$@" | { grep -zvE '(^|/)(vendor|var)/' || true; }
}

# Pass 1: presence.
missing="$(list_tracked 'src/*.php' 'tests/*.php' 'templates/*.twig' \
  | xargs -0 -r grep -LZ -m1 -e "$TAG" \
  | tr '\0' '\n')" || true

if [ -n "$missing" ]; then
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    echo "MISSING SPDX: $f"
  done <<< "$missing"
  status=1
fi

# Pass 2: value. One grep over the tree, first header line per file, normalised
# to "path<TAB>identifier".
declared="$(list_tracked "${SOURCE_GLOBS[@]}" \
  | xargs -0 -r grep -H -m1 -oE "${TAG}:[[:space:]]*[A-Za-z0-9.+-]+" \
  | sed -E "s/:${TAG}:[[:space:]]*/	/")" || true

if [ -n "$declared" ]; then
  while IFS=$'\t' read -r f id; do
    [ -n "$f" ] || continue
    case " $ALLOWED_IDENTIFIERS " in
      *" $id "*) continue ;;
    esac
    case "$id" in
      AGPL-3.0|AGPL-3.0+|AGPL-3.0-or-later)
        echo "BAD SPDX ($id): $f"
        echo "  this project is AGPL-3.0-only. Bare AGPL-3.0 is deprecated, and 'or later' was rejected on purpose."
        ;;
      LicenseRef-PolyForm*)
        echo "STALE SPDX ($id): $f"
        echo "  not a licence this project publishes under. The code is AGPL-3.0-only."
        ;;
      *)
        echo "UNKNOWN SPDX ($id): $f"
        echo "  not one of: $ALLOWED_IDENTIFIERS"
        ;;
    esac
    status=1
  done <<< "$declared"
fi

exit "$status"
