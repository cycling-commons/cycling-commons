#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# pre-push: full PHPStan + Psalm over web/ before code leaves the machine.
# ci-app.yml runs the same two analyzers, but staging/symfony-base pushes
# deploy immediately — this hook shifts the catch left of the push, so a
# red analysis never reaches the servers in the first place.
#
# Full-project runs on purpose (not just the outgoing files): an unstaged
# test can break because a src/ signature changed in the pushed commits,
# and PHPStan only sees that when it re-checks everything. Same flags as
# `make app-test`, so the hook and the Makefile cannot disagree.
#
# Bypass: git push --no-verify (the next red CI run is the price).
set -euo pipefail

cd "$(git rev-parse --show-toplevel)/web"

if [ ! -x vendor/bin/phpstan ] || [ ! -x vendor/bin/psalm ]; then
    echo "pre-push: web/vendor is missing or incomplete." >&2
    echo "  Install with: make app-install" >&2
    echo "  Bypass once:  git push --no-verify" >&2
    exit 1
fi

echo "1/2 PHPStan..."
vendor/bin/phpstan analyse --no-progress

echo "2/2 Psalm..."
vendor/bin/psalm --no-cache

echo "PHPStan + Psalm passed — pushing."
