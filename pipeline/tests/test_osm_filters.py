# SPDX-License-Identifier: AGPL-3.0-only
"""The published filter script gives exactly the pipeline's own filters.

`osm_filters.py` is what the developer wiki tells outside developers to run
(wiki/developers/api/openstreetmap-data.md) to get the same OpenStreetMap
objects the Commons uses. It reads only the contract file, with no pipeline
code, so it can drift from the passes that really run. These tests hold it to
them, pass by pass.
"""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

import pytest

from coverage import extract, roadpieces, routes, surface
from coverage.contract import CONTRACT_PATH, load_contract

SCRIPT = Path(__file__).resolve().parents[1] / "osm_filters.py"


def _published(pass_name: str) -> list[str]:
    out = subprocess.run(
        [sys.executable, str(SCRIPT), pass_name, str(CONTRACT_PATH)],
        capture_output=True, text=True, check=True,
    ).stdout
    return out.split()


def _pipeline(pass_name: str) -> list[str]:
    contract = load_contract()
    return {
        "points": extract.selector_expressions(contract),
        "surface": surface.selector_expressions(contract),
        "routes": routes.selector_expressions(),
        "roadpieces": [roadpieces.SELECTOR],
    }[pass_name]


@pytest.mark.parametrize("pass_name", ["points", "surface", "routes", "roadpieces"])
def test_the_published_script_prints_the_pipelines_filters(pass_name: str) -> None:
    assert _published(pass_name) == _pipeline(pass_name)


def test_an_unknown_pass_is_refused() -> None:
    proc = subprocess.run(
        [sys.executable, str(SCRIPT), "everything", str(CONTRACT_PATH)],
        capture_output=True, text=True,
    )
    assert proc.returncode != 0
    assert "points" in proc.stderr
