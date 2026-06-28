#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
# Fails if any installed Composer dependency carries a copyleft licence that
# would conflict with the Shield-licensed top-level code. Heuristic guard:
# scans `composer licenses` JSON for copyleft SPDX tokens.
set -euo pipefail
cd "$(dirname "$0")/.."
bad=$(composer licenses --no-dev --format=json 2>/dev/null \
  | grep -oE '"(A?GPL|LGPL|MPL|EUPL|OSL|CDDL|SSPL)[^"]*"' | sort -u || true)
if [ -n "$bad" ]; then
  echo "COPYLEFT / non-permissive dependency licence(s) detected — review before shipping:"
  echo "$bad"
  exit 1
fi
echo "licences OK (no copyleft deps)"
