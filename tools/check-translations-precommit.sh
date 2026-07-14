#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# Pre-commit wrapper around web/tools/check-translations.sh (the locale-parity
# gate: every key in messages.en.yaml must exist in fr/nl/de and vice versa).
# Triggered only when files under web/translations/ are staged — see
# .pre-commit-config.yaml. The same check runs in `make app-test` / CI, so a
# skip here is never a hole, just a later failure.
#
# Skips (exit 0, with a notice) when the PHP toolchain isn't available: the
# checker needs php + web/vendor (composer install), and non-PHP contributors
# — docs, tools/, data work — must not be blocked by it.
set -euo pipefail
cd "$(dirname "$0")/.."

if ! command -v php >/dev/null 2>&1; then
    echo "check-translations: skipped (no php on PATH — CI will enforce parity)"
    exit 0
fi
if [ ! -f web/vendor/autoload.php ]; then
    echo "check-translations: skipped (web/vendor missing — run 'composer install' in web/; CI will enforce parity)"
    exit 0
fi

exec web/tools/check-translations.sh
