# SPDX-License-Identifier: AGPL-3.0-only
"""coverage.scenic_review: how far each catalog scenic item is from a bike way."""

import json
import subprocess

from coverage import scenic_review
from coverage.scenic_review import measure, nearest_m, search_box


def test_nearest_m_measures_to_the_segment():
    line = [(4.85, 50.46), (4.87, 50.46)]
    assert 50 < nearest_m((4.86, 50.4605), [line]) < 62
    assert nearest_m((4.86, 50.46), []) is None


def test_the_search_box_reaches_past_the_rule_in_every_direction():
    w, s, e, n = search_box((7.8, 46.0), 250)
    # 250 m of latitude is 0.00225 deg; of longitude at 46 N, 0.00324 deg.
    assert n - 46.0 > 0.00225 and 46.0 - s > 0.00225
    assert e - 7.8 > 0.00324 and 7.8 - w > 0.00324


def test_measure_reports_distance_and_whether_any_extract_covers_the_item():
    items = [{"id": 1, "lat": 50.4605, "lng": 4.86}, {"id": 2, "lat": 36.1, "lng": -115.1}]
    extracts = [("europe-belgium", (2.3, 49.4, 6.5, 51.6))]

    def lines_for(name, box):
        assert name == "europe-belgium"
        return [[(4.85, 50.46), (4.87, 50.46)]]

    got = {r["id"]: r for r in measure(items, extracts, lines_for, within_m=250)}
    assert got[1]["covered"] is True and 50 < got[1]["nearest_bikeway_m"] < 62
    assert got[2] == {"id": 2, "covered": False, "nearest_bikeway_m": None}
    json.dumps(list(got.values()))


def test_main_reads_pbfs_from_the_shared_dir(monkeypatch, tmp_path):
    work, shared = tmp_path / "work", tmp_path / "pbf"
    work.mkdir()
    shared.mkdir()
    (shared / "europe-belgium-latest.osm.pbf").write_bytes(b"pbf")
    monkeypatch.setenv("COVERAGE_WORKDIR", str(work))
    monkeypatch.setenv("COVERAGE_PBF_DIR", str(shared))
    items = tmp_path / "items.json"
    items.write_text(json.dumps([{"id": 1, "lat": 50.46, "lng": 4.86}]))
    filtered = []

    def fake_filter(src, dst, rule):
        filtered.append(src)
        dst.write_bytes(b"")

    def fake_run(cmd, **kw):
        out = "(2.3,49.4,6.5,51.6)" if cmd[1] == "fileinfo" else ""
        return subprocess.CompletedProcess(cmd, 0, stdout=out, stderr="")

    monkeypatch.setattr(scenic_review, "run_way_filter", fake_filter)
    monkeypatch.setattr(scenic_review.subprocess, "run", fake_run)
    out = tmp_path / "out.json"
    assert scenic_review.main(["--items", str(items), "--out", str(out)]) == 0
    assert filtered == [shared / "europe-belgium-latest.osm.pbf"]
    assert json.loads(out.read_text())["items"][0]["covered"] is True
    # Derived file stays per environment.
    assert (work / "europe-belgium-bikeways.osm.pbf").exists()
