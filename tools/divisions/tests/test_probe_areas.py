# SPDX-License-Identifier: Apache-2.0
"""Offline tests for the onboarding area probe (tools/divisions/README.md step 1).

Pure helpers + file emission via a stub DuckDB connection; the live Overture
query is skip-gated like the exporter's (RUN_LIVE_OVERTURE=1)."""
import json
import os

import pytest

from divisions.probe_areas import (
    BAND_KM2, bbox_union, candidate_report, geom_bounds, probe_country,
)

SQUARE = {
    "type": "Polygon",
    "coordinates": [[[4.0, 50.0], [5.0, 50.0], [5.0, 51.0], [4.0, 51.0], [4.0, 50.0]]],
}
MULTI = {"type": "MultiPolygon", "coordinates": [SQUARE["coordinates"]]}


def test_geom_bounds_polygon_and_multipolygon():
    assert geom_bounds(SQUARE) == (4.0, 50.0, 5.0, 51.0)
    assert geom_bounds(MULTI) == (4.0, 50.0, 5.0, 51.0)


def test_bbox_union_pads_and_rounds():
    got = bbox_union([(4.0, 50.0, 5.0, 51.0), (4.5, 49.5, 6.0, 50.5)])
    assert got == [3.9, 49.4, 6.1, 51.1]  # min/max of both, padded 0.1, 2 decimals


def test_candidate_report_flags_band_position():
    md = candidate_report("NL", {"region": [
        {"iso": "NL-DR", "name": "Drenthe", "area_km2": 2680},
        {"iso": "XX-BIG", "name": "Big", "area_km2": 20000},
        {"iso": "XX-HUGE", "name": "Huge", "area_km2": 30000},
    ]})
    assert "subtype=region" in md and "| NL-DR |" in md
    assert "below" in md and "in band" in md and "above" in md
    assert str(BAND_KM2[0]) not in md  # band rendered with thousands separators
    assert "13,520" in md
    assert "moderation composes upward" in md  # tools/divisions/README.md advice


class StubCon:
    """Mimics duckdb: execute(sql, params) -> self; fetchall() -> rows."""

    def __init__(self, rows):
        self.rows = rows

    def execute(self, sql, params=None):
        return self

    def fetchall(self):
        return self.rows


def test_probe_country_writes_areas_md_and_probe_json(tmp_path):
    rows = [("NL-DR", "Drenthe", json.dumps(SQUARE)),
            ("NL-NH", "Noord-Holland", json.dumps(MULTI))]
    out = probe_country("NL", tmp_path, subtypes=("region",), con=StubCon(rows))
    assert out == tmp_path / "nl"
    probe = json.loads((out / "probe.json").read_text())
    assert probe["country"] == "NL"
    got = probe["subtypes"]["region"]
    assert [r["iso"] for r in got["rows"]] == ["NL-DR", "NL-NH"]
    assert all(isinstance(r["area_km2"], int) for r in got["rows"])
    assert got["bbox"] == [3.9, 49.9, 5.1, 51.1]
    md = (out / "areas.md").read_text()
    assert "NL-DR" in md and "Drenthe" in md


@pytest.mark.skipif(os.environ.get("RUN_LIVE_OVERTURE") != "1",
                    reason="live Overture probe (network) — set RUN_LIVE_OVERTURE=1")
def test_live_overture_probe_nl(tmp_path):
    out = probe_country("NL", tmp_path, subtypes=("region",))
    probe = json.loads((out / "probe.json").read_text())
    isos = {r["iso"] for r in probe["subtypes"]["region"]["rows"]}
    assert {"NL-DR", "NL-NH", "NL-ZH"} <= isos
