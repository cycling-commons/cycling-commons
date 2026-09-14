# SPDX-License-Identifier: AGPL-3.0-only
"""osmium tags-filter step (coverage-provider.md §3 step 2).

Reduces a Geofabrik region PBF to the osm-data-architecture.md §5 selector
subset — a few-MB PBF the pyosmium parse step then walks. tags-filter keeps
the untagged member nodes of matched ways, so way centroids stay computable.
"""

from __future__ import annotations

import json
import subprocess
import tempfile
from collections.abc import Iterable, Iterator
from pathlib import Path

from coverage.contract import Contract, NearWay


def selector_expressions(contract: Contract) -> list[str]:
    """osmium tags-filter expressions, nodes + ways (decision B1), deduped in order."""
    exprs: list[str] = []
    for spec in contract.letters.values():
        for sel in spec.selectors:
            expr = f"nw/{sel.tag}"   # Selector.tag is already "key=value"
            if expr not in exprs:
                exprs.append(expr)
    return exprs


def run_filter(pbf_path: Path, out_path: Path, expressions: list[str]) -> Path:
    """Run `osmium tags-filter` with arbitrary expressions.

    Factored out of run_extract so the road-surface LINE pass (surface.py) can
    reuse it: same tool, same failure handling, a different selector set — and
    a second pass on purpose, so the point subset stays a few MB.
    """
    cmd = [
        "osmium", "tags-filter",
        "--overwrite",
        "-o", str(out_path),
        str(pbf_path),
        *expressions,
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError(
            f"osmium tags-filter failed ({proc.returncode}): {proc.stderr.strip()}"
        )
    return out_path


def run_extract(pbf_path: Path, out_path: Path, contract: Contract) -> Path:
    """Filter `pbf_path` down to the contract POINT selectors, writing `out_path`."""
    return run_filter(pbf_path, out_path, selector_expressions(contract))


def run_way_filter(pbf_path: Path, out_path: Path, rule: NearWay) -> Path:
    """The ways a near-way rule may count, as their own small PBF (docs/specs/scenic-views.md).

    A third pass on purpose, like the surface pass: the point subset stays a few
    MB, and this one holds only highways the rule names or that carry a bicycle
    tag it accepts. `rideable_lines` applies the rest (bicycle=no, a bicycle tag
    on a highway type the rule does not name).
    """
    return run_filter(pbf_path, out_path, [
        f"w/highway={','.join(rule.highways)}",
        f"w/bicycle={','.join(rule.bicycle_tags)}",
    ])


def export_lines(pbf_path: Path) -> Iterator[str]:
    """`osmium export` of a way PBF as GeoJSON text sequence, streamed line by line.

    The node location index lives in a temporary file, not in memory: a
    country's bike ways hold tens of millions of nodes, and the in-memory index
    for Germany alone would take several GB on a machine that runs the rest of
    the stack beside it.
    """
    with tempfile.TemporaryDirectory(prefix="coverage-bikeways-") as tmp:
        proc = subprocess.Popen(
            ["osmium", "export", str(pbf_path), "-f", "geojsonseq", "--geometry-types=linestring",
             "-i", f"sparse_file_array,{tmp}/locations.idx", "-o", "-"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )
        assert proc.stdout is not None
        yield from proc.stdout
        if proc.wait() != 0:
            raise RuntimeError(f"osmium export failed ({proc.returncode}): {proc.stderr.read().strip()}")


def rideable_lines(text_lines: Iterable[str], rule: NearWay) -> Iterator[list[tuple[float, float]]]:
    """(lon, lat) vertex lists of the ways a bike may ride, from GeoJSON text sequence lines."""
    for line in text_lines:
        line = line.lstrip("\x1e").strip()
        if not line:
            continue
        try:
            feature = json.loads(line)
        except json.JSONDecodeError:
            continue
        geom = feature.get("geometry") or {}
        if geom.get("type") != "LineString" or not rule.rideable(feature.get("properties") or {}):
            continue
        yield [(float(c[0]), float(c[1])) for c in geom["coordinates"]]
