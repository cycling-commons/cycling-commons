# SPDX-License-Identifier: AGPL-3.0-only
"""Where the raw Geofabrik downloads live.

COVERAGE_PBF_DIR lets CC staging and production share one copy of each
<region>-latest.osm.pbf; unset or empty it is the workdir, as before.
Everything derived from a PBF stays in the workdir."""
import os
import pathlib


def pbf_dir(workdir: pathlib.Path) -> pathlib.Path:
    d = os.environ.get("COVERAGE_PBF_DIR", "").strip()
    return pathlib.Path(d) if d else workdir


def pbf_path(workdir: pathlib.Path, region: str) -> pathlib.Path:
    """Accepts "europe/belgium" or "europe-belgium"."""
    return pbf_dir(workdir) / (region.replace("/", "-") + "-latest.osm.pbf")
