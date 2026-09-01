#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# pre-push: the whole `make app-test` gate over web/ before code leaves the
# machine. Same commands, same flags, so the hook and the Makefile cannot
# disagree; only the order differs, cheapest first, so a formatting slip
# fails in seconds instead of after the four-minute suite.
#
# ci-app.yml runs the same gate, but staging/symfony-base pushes deploy
# immediately, so the push is the last moment to catch a red suite.
#
# Every run is full-project, never just the outgoing files: an untouched
# test breaks when a src/ signature changes in the pushed commits, and only
# a full re-check sees it.
#
# Bypass: git push --no-verify (the next red CI run is the price).
set -euo pipefail

cd "$(git rev-parse --show-toplevel)/web"

for bin in phpstan psalm php-cs-fixer; do
    if [ ! -x "vendor/bin/${bin}" ]; then
        echo "pre-push: web/vendor is missing or incomplete (vendor/bin/${bin})." >&2
        echo "  Install with: make app-install" >&2
        echo "  Bypass once:  git push --no-verify" >&2
        exit 1
    fi
done

echo "1/8 php-cs-fixer..."
vendor/bin/php-cs-fixer fix --dry-run --diff

echo "2/8 SPDX headers..."
./tools/check-spdx.sh

echo "3/8 licences..."
./tools/check-licenses.sh

echo "4/8 translation parity..."
./tools/check-translations.sh

echo "5/8 no |trans|raw..."
./tools/check-raw-translations.sh

echo "6/8 PHPStan..."
vendor/bin/phpstan analyse --no-progress

echo "7/8 Psalm..."
vendor/bin/psalm --no-cache

echo "8/8 PHPUnit (full suite, a few minutes)..."
if ! php bin/phpunit; then
    echo >&2
    echo "pre-push: the test suite failed." >&2
    echo "  A green local run against an empty test database proves less than CI:" >&2
    echo "  CI seeds translation_entry with the whole catalogue first." >&2
    echo "  Match it with: make test-db-reset && php bin/console app:translations:sync --env=test" >&2
    exit 1
fi

echo "Full app gate passed, pushing."
