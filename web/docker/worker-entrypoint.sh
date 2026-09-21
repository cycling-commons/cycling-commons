#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-only
#
# Entrypoint of the worker image (Dockerfile.worker). /app/var is an empty
# tmpfs on every container start; seed the Symfony cache from the build-time
# stash so the consumer does not cold-compile the container each restart.
set -e

if [ -d /app/.warm-cache ] && [ -z "$(ls -A /app/var/cache 2>/dev/null)" ]; then
    mkdir -p /app/var/cache
    cp -a /app/.warm-cache/. /app/var/cache/ 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"
