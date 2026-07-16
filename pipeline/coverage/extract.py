# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""osmium tags-filter step (coverage-provider.md §3 step 2).

Reduces a Geofabrik region PBF to the osm-data-architecture.md §5 selector
subset — a few-MB PBF the pyosmium parse step then walks. tags-filter keeps
the untagged member nodes of matched ways, so way centroids stay computable.
"""

from __future__ import annotations

import subprocess
from pathlib import Path

from coverage.contract import Contract


def selector_expressions(contract: Contract) -> list[str]:
    """osmium tags-filter expressions, nodes + ways (decision B1), deduped in order."""
    exprs: list[str] = []
    for spec in contract.letters.values():
        for sel in spec.selectors:
            expr = f"nw/{sel.tag}"   # Selector.tag is already "key=value"
            if expr not in exprs:
                exprs.append(expr)
    return exprs


def run_extract(pbf_path: Path, out_path: Path, contract: Contract) -> Path:
    """Filter `pbf_path` down to the contract selectors, writing `out_path`."""
    cmd = [
        "osmium", "tags-filter",
        "--overwrite",
        "-o", str(out_path),
        str(pbf_path),
        *selector_expressions(contract),
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError(
            f"osmium tags-filter failed ({proc.returncode}): {proc.stderr.strip()}"
        )
    return out_path
