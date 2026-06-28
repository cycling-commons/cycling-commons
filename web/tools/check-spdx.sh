#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
# Fails if any tracked PHP/Twig file under api/ lacks an SPDX-License-Identifier.
set -euo pipefail
cd "$(dirname "$0")/.."
missing=0
while IFS= read -r f; do
  case "$f" in
    */vendor/*|*/var/*) continue ;;
  esac
  if ! grep -qm1 'SPDX-License-Identifier' "$f"; then
    echo "MISSING SPDX: $f"
    missing=1
  fi
done < <(git ls-files 'src/*.php' 'tests/*.php' 'templates/*.twig')
exit "$missing"
