#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# pre-push: the deploy's `composer audit` before code leaves the machine.
# Dockerfile.worker runs the same audit while it builds the image, so an
# advisory on a locked package fails the deploy after the push. A new advisory
# needs no change of ours, so this runs on every push, whatever it carries.
#
# Dev packages included: the staging image installs them, so a dev-only
# advisory (PHPUnit) fails staging as well.
#
# Fix: `composer update <package> --with-dependencies` in the app container,
# then commit web/composer.lock. Bypass once: git push --no-verify.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)/web"

if ! command -v composer >/dev/null 2>&1; then
    echo "pre-push: composer is not on PATH, so the advisories were not checked." >&2
    echo "  The deploy audits web/composer.lock and fails on any advisory." >&2
    echo "  Bypass once: git push --no-verify" >&2
    exit 1
fi

if ! composer audit --locked --abandoned=ignore; then
    echo >&2
    echo "pre-push: a locked package has a security advisory; the deploy would fail on it." >&2
    echo "  Update it in the app container:" >&2
    echo "    docker exec cycling-commons-dev-app-1 composer update <package> --with-dependencies" >&2
    echo "  then commit web/composer.lock." >&2
    exit 1
fi
