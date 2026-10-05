# SPDX-License-Identifier: AGPL-3.0-only
"""Repair: the documented coverage.run steps, in order, for the new country and its neighbours."""
import contextlib
import sys

from catalog_rows import add_country, add_region
from onboarding import repair

RUN = (sys.executable, "-m", "coverage.run")


def _world(conn, dk_status="seeded"):
    add_country(conn, "DK", dk_status, ["europe/denmark"])
    add_country(conn, "DE", "live", ["europe/germany"])
    add_country(conn, "JP", "live", ["asia/japan"])
    add_region(conn, 1, "DK", 4, "POLYGON((9 55,10 55,10 56,9 56,9 55))", slug="syddanmark")
    add_region(conn, 2, "DE", 4, "POLYGON((9 54,10 54,10 54.98,9 54.98,9 54))", slug="schleswig-holstein")
    add_region(conn, 3, "JP", 4, "POLYGON((139 35,140 35,140 36,139 36,139 35))", slug="tokyo")


def _main(conn, runner):
    return repair.main(["DK"], connect=lambda dsn: contextlib.nullcontext(conn), runner=runner, clock=lambda: 0.0)


def test_the_steps_run_in_the_documented_order(catalog, capsys):
    _world(catalog)
    ran = []
    assert _main(catalog, lambda step: ran.append(step) or 0) == 0
    assert [(s.argv[3:], s.env) for s in ran] == [
        (("--load-only", "--regions", "europe/denmark"), {}),
        (("--load-only", "--regions", "europe/germany"), {"COVERAGE_FULL_MEMBERSHIP": "1"}),
        (("--tiles-only",), {}),
        (("--routes", "--regions", "europe/denmark,europe/germany"), {"COVERAGE_FORCE_EXTRACT": "1"}),
        (("--surface", "--regions", "europe/denmark,europe/germany"), {"COVERAGE_FORCE_EXTRACT": "1"}),
    ]
    assert all(s.argv[:3] == RUN for s in ran)
    out = capsys.readouterr().out
    assert "neighbours DE" in out and out.count("[repair] ") >= 6


def test_a_failed_step_stops_the_rest(catalog):
    _world(catalog)
    ran = []

    def runner(step):
        ran.append(step)
        return 1 if "--tiles-only" in step.argv else 0

    assert _main(catalog, runner) == 1
    assert len(ran) == 3


def test_a_held_coverage_lock_stops_with_a_rerun_hint(catalog, capsys):
    _world(catalog)
    assert _main(catalog, lambda step: repair.LOCK_HELD) == repair.LOCK_HELD
    assert "another coverage run holds the lock; start this again once it has finished" in capsys.readouterr().err


def test_repair_needs_a_seeded_country(catalog, capsys):
    _world(catalog, dk_status="planned")
    assert _main(catalog, lambda step: 0) == 1
    assert "DK is planned; repair needs a seeded country" in capsys.readouterr().err


def test_no_neighbours_means_no_neighbour_harvest():
    steps = repair.plan_steps(["asia/japan"], [])
    assert [s.name for s in steps] == ["harvest the new country", "rebuild point tiles",
                                      "rebuild route tiles", "rebuild surface tiles"]
