# SPDX-License-Identifier: AGPL-3.0-only
"""The planner: build from Overture rows, write in one transaction, refuse a
seeded or live country before touching the network, print the table."""
import contextlib
import json
import pathlib
import subprocess
import sys

import psycopg
import pytest

from catalog_rows import add_country, add_region
from onboarding import plan as planner
from onboarding.extracts import load_index
from onboarding.overture import Division

INDEX = load_index(str(pathlib.Path(__file__).parent / "fixtures" / "geofabrik-index.json"))


def square(x0, y0, x1, y1):
    return {"type": "Polygon", "coordinates": [[[x0, y0], [x1, y0], [x1, y1], [x0, y1], [x0, y0]]]}


def division(iso, primary, common, box, area):
    return Division(iso=iso, primary=primary, common=common, geometry=square(*box), area_km2=area)


OUTLINE = division("DK", "Danmark", {"en": "Denmark", "fr": "Danemark", "nl": "Denemarken", "de": "Dänemark", "es": "Dinamarca"},
                   (11.0, 55.0, 13.0, 56.0), 42_900.0)
REGIONS = [
    division("DK-84", "Region Hovedstaden", {"en": "Capital Region of Denmark"}, (12.0, 55.0, 13.0, 56.0), 2_546.0),
    division("DK-85", "Region Sjælland", {"en": "Region Zealand", "de": "Region Seeland"}, (11.0, 55.0, 12.0, 56.0), 7_217.0),
]


class FakeOverture:
    def __init__(self, rows=None, refuse=False):
        self.rows = rows or {"country": [OUTLINE], "region": REGIONS, "county": []}
        self.refuse = refuse
        self.calls = []

    def country_box(self, cc):
        if self.refuse:
            pytest.fail("the planner reached Overture for a country it must refuse")
        self.calls.append("box")
        return (10.9, 54.9, 13.1, 56.1)

    def divisions(self, cc, subtype, box):
        self.calls.append(subtype)
        return self.rows.get(subtype, [])


def test_build_plan_decides_level_slugs_labels_and_extract():
    plan = planner.build_plan("DK", "2026-08-19.0", {"region": REGIONS, "county": []}, OUTLINE, {"limburg"}, INDEX)
    assert plan.subtype == "region"
    assert plan.extract == "europe/denmark"
    assert plan.name == "Denmark"
    assert plan.labels["nl"] == "Heel Denemarken"
    assert [(r.slug, r.admin_level, r.iso_code) for r in plan.regions] == [
        ("hovedstaden", 4, "DK-84"), ("sjaelland", 4, "DK-85"), ("denmark", 2, "DK")]
    zealand = plan.regions[1]
    assert zealand.name == "Region Zealand"
    assert zealand.labels["de"] == "Region Seeland" and zealand.labels["fr"] == "Region Sjælland"
    assert zealand.fallbacks == ["fr", "nl", "es"]


def test_the_country_outline_takes_the_english_slug():
    plan = planner.build_plan("DK", "2026-08-19.0", {"region": REGIONS}, OUTLINE, set(), INDEX)
    assert [(r.slug, r.name) for r in plan.regions if r.admin_level == 2] == [("denmark", "Denmark")]
    bare = division("DK", "Danmark", {}, (11.0, 55.0, 13.0, 56.0), 42_900.0)
    plan = planner.build_plan("DK", "2026-08-19.0", {"region": REGIONS}, bare, set(), INDEX)
    assert [r.slug for r in plan.regions if r.admin_level == 2] == ["danmark"], "no English name: the native one"


def test_a_region_without_an_iso_code_is_skipped_and_reported():
    rows = REGIONS + [division(None, "Plazas de Soberanía", {}, (12.5, 55.5, 12.6, 55.6), 1.0)]
    plan = planner.build_plan("DK", "2026-08-19.0", {"region": rows}, OUTLINE, set(), INDEX)
    assert plan.skipped == ["Plazas de Soberanía (no ISO 3166-2 code)"]


def test_a_small_country_is_one_region_at_level_two():
    lu = division("LU", "Lëtzebuerg", {"en": "Luxembourg"}, (5.7, 49.4, 6.6, 50.2), 2_586.0)
    plan = planner.build_plan("LU", "2026-08-19.0", {"region": [], "county": []}, lu, set(), {"features": []},
                              extract="europe/luxembourg", extract_check=False)
    assert plan.subtype == "country"
    # The one region is the level-2 outline, so its slug is English, like the seeded `luxembourg`.
    assert [(r.slug, r.admin_level, r.iso_code, r.name) for r in plan.regions] == [("luxembourg", 2, "LU", "Luxembourg")]


def test_a_county_or_lower_region_has_no_iso_code_but_the_outline_keeps_its_own():
    rows = [division(None, "Hamlet One", {}, (11.0, 55.0, 11.5, 55.5), 50.0),
            division(None, "Hamlet Two", {}, (11.5, 55.0, 12.0, 55.5), 50.0)]
    plan = planner.build_plan("DK", "2026-08-19.0", {"region": REGIONS, "localadmin": rows}, OUTLINE, set(), INDEX,
                              level="localadmin")
    assert [(r.slug, r.iso_code, r.admin_level) for r in plan.regions] == [
        ("hamlet-one", None, 8), ("hamlet-two", None, 8), ("denmark", "DK", 2)]


def test_write_plan_replaces_an_earlier_plan_in_one_transaction(catalog):
    plan = planner.build_plan("DK", "2026-08-19.0", {"region": REGIONS}, OUTLINE, set(), INDEX)
    planner.write_plan(catalog, plan)
    planner.write_plan(catalog, plan)
    assert catalog.execute("SELECT status, subtype, overture_release FROM country WHERE code = 'DK'").fetchone() == (
        "planned", "region", "2026-08-19.0")
    assert catalog.execute("SELECT count(*) FROM country_plan_region WHERE country_code = 'DK'").fetchone()[0] == 3
    assert catalog.execute("SELECT slug FROM country_extract WHERE country_code = 'DK'").fetchall() == [("europe/denmark",)]
    assert catalog.execute(
        "SELECT fallback_locales FROM country_plan_region WHERE slug = 'sjaelland'").fetchone()[0] == ["fr", "nl", "es"]


def test_an_extract_owned_by_another_country_stops_the_write(catalog):
    add_country(catalog, "XB", "live", ["europe/denmark"])
    plan = planner.build_plan("DK", "2026-08-19.0", {"region": REGIONS}, OUTLINE, set(), INDEX)
    with pytest.raises(planner.PlanError, match="europe/denmark already belongs to XB"):
        planner.write_plan(catalog, plan)


def test_a_write_failing_partway_leaves_nothing(catalog):
    plan = planner.build_plan("DK", "2026-08-19.0", {"region": REGIONS}, OUTLINE, set(), INDEX)
    plan.regions[1].geometry = {"type": "Polygon", "coordinates": "not coordinates"}
    with pytest.raises(psycopg.Error):
        planner.write_plan(catalog, plan)
    assert catalog.execute("SELECT count(*) FROM country").fetchone()[0] == 0
    assert catalog.execute("SELECT count(*) FROM country_plan_region").fetchone()[0] == 0
    assert catalog.execute("SELECT count(*) FROM country_extract").fetchone()[0] == 0


def test_main_refuses_a_live_country_before_any_network(catalog, monkeypatch, capsys):
    add_country(catalog, "DK", "live", ["europe/denmark"])
    rc = planner.main(["DK"], source=FakeOverture(refuse=True),
                      connect=lambda dsn: contextlib.nullcontext(catalog), index_loader=lambda src: INDEX)
    assert rc == 1
    assert "DK is live" in capsys.readouterr().err


def test_main_writes_the_plan_and_prints_neighbours_and_fallbacks(catalog, capsys):
    add_country(catalog, "XB", "live", ["europe/xb"])
    add_region(catalog, 1, "XB", 4, "POLYGON((13.05 55,14 55,14 56,13.05 56,13.05 55))", slug="xb-west")
    source = FakeOverture()
    rc = planner.main(["dk"], source=source, connect=lambda dsn: contextlib.nullcontext(catalog),
                      index_loader=lambda src: INDEX)
    out = capsys.readouterr().out
    assert rc == 0
    assert source.calls[:2] == ["box", "country"]
    assert "europe/denmark" in out and "Neighbours  XB" in out
    assert "Region Sjælland*" in out
    assert catalog.execute("SELECT status FROM country WHERE code = 'DK'").fetchone() == ("planned",)


def test_main_json_output(catalog, capsys):
    rc = planner.main(["DK", "--json"], source=FakeOverture(), connect=lambda dsn: contextlib.nullcontext(catalog),
                      index_loader=lambda src: INDEX)
    doc = json.loads(capsys.readouterr().out)
    assert rc == 0
    assert doc["country"] == "DK" and doc["level"] == "region" and doc["extract"] == "europe/denmark"
    assert doc["regions"][1] == {"slug": "sjaelland", "iso_code": "DK-85", "admin_level": 4, "area_km2": 7217.0,
                                 "labels": {"en": "Region Zealand", "fr": "Region Sjælland", "nl": "Region Sjælland",
                                            "de": "Region Seeland", "es": "Region Sjælland"},
                                 "fallback_locales": ["fr", "nl", "es"]}


def test_the_overture_connection_keeps_stdout_clean_for_json():
    # A subprocess: DuckDB turns its stdout progress bar off by itself under pytest, not under the CLI.
    probe = ("from onboarding.overture import Overture; print(Overture('2026-08-19.0').con.execute("
             "\"SELECT value FROM duckdb_settings() WHERE name = 'enable_progress_bar'\").fetchone()[0])")
    out = subprocess.run([sys.executable, "-c", probe], cwd=pathlib.Path(__file__).parent.parent,
                         capture_output=True, text=True, check=True).stdout
    assert out.strip() == "false"


def test_main_stops_with_the_reason_when_no_level_fits(catalog, capsys):
    tiny = [division("XA-1", "Tiny", {}, (11.0, 55.0, 11.1, 55.1), 50.0)]
    source = FakeOverture({"country": [OUTLINE], "region": tiny, "county": []})
    rc = planner.main(["DK"], source=source, connect=lambda dsn: contextlib.nullcontext(catalog),
                      index_loader=lambda src: INDEX)
    assert rc == 1
    assert "no level fits; rerun with --level <subtype>" in capsys.readouterr().err
    assert catalog.execute("SELECT count(*) FROM country").fetchone()[0] == 0


class BuggyOverture(FakeOverture):
    def divisions(self, cc, subtype, box):
        raise KeyError("names")


def test_a_bug_inside_the_planner_is_a_traceback_not_a_reason(catalog):
    with pytest.raises(KeyError):
        planner.main(["DK"], source=BuggyOverture(), connect=lambda dsn: contextlib.nullcontext(catalog),
                     index_loader=lambda src: INDEX)


def test_a_broken_geofabrik_index_says_where_it_came_from(catalog, capsys):
    def broken(src):
        return json.loads("<html>")
    rc = planner.main(["DK"], source=FakeOverture(), connect=lambda dsn: contextlib.nullcontext(catalog),
                      index_loader=broken)
    assert rc == 1
    assert "Geofabrik index" in capsys.readouterr().err
    assert catalog.execute("SELECT count(*) FROM country").fetchone()[0] == 0


def test_a_bad_release_is_a_one_line_reason(catalog, capsys):
    rc = planner.main(["DK", "--release", "latest"], connect=lambda dsn: contextlib.nullcontext(catalog),
                      index_loader=lambda src: INDEX)
    assert rc == 1
    assert "Not an Overture release id: 'latest'" in capsys.readouterr().err


def test_an_unknown_level_is_refused_by_the_command_line(catalog, capsys):
    with pytest.raises(SystemExit) as exc:
        planner.main(["DK", "--level", "duchy"], source=FakeOverture(refuse=True),
                     connect=lambda dsn: contextlib.nullcontext(catalog), index_loader=lambda src: INDEX)
    assert exc.value.code == 2
    assert "invalid choice: 'duchy'" in capsys.readouterr().err


@pytest.mark.skipif(__import__("os").environ.get("RUN_LIVE_OVERTURE") != "1", reason="hits Overture S3 and Geofabrik")
def test_live_denmark_plan_without_writing():
    from onboarding.overture import Overture
    from divisions import config
    overture = Overture(config.OVERTURE_RELEASE)
    box = overture.country_box("DK")
    outline = overture.divisions("DK", "country", box)[0]
    rows = {st: overture.divisions("DK", st, box) for st in ("region", "county")}
    plan = planner.build_plan("DK", config.OVERTURE_RELEASE, rows, outline, set(),
                              load_index("https://download.geofabrik.de/index-v1-nogeom.json"))
    assert plan.subtype == "region" and plan.extract == "europe/denmark"
    assert len([r for r in plan.regions if r.admin_level == 4]) == 5
