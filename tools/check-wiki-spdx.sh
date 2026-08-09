#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# Wiki prose is CC BY-SA 4.0 (README §License, osm-data-architecture.md §3;
# decision 2026-07-17). Every tracked wiki/**/*.md must open with the SPDX
# header so the licence is visible in the repo source — this hook makes
# forgetting it on a new page a failing commit instead of a silent gap.
set -euo pipefail
cd "$(dirname "$0")/.."
missing=0
while IFS= read -r f; do
# REUSE-IgnoreStart — the identifier below is DATA (written into or
# grepped out of generated files), not this file's own licence.
  if ! head -n1 "$f" | grep -qF 'SPDX-License-Identifier: CC-BY-SA-4.0'; then
# REUSE-IgnoreEnd
# REUSE-IgnoreStart — the identifier below is DATA (written into or
# grepped out of generated files), not this file's own licence.
    echo "MISSING/WRONG SPDX (expect first line '<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->'): $f"
# REUSE-IgnoreEnd
    missing=1
  fi
done < <(git ls-files 'wiki/*.md' 'wiki/**/*.md' | sort -u)
exit "$missing"
