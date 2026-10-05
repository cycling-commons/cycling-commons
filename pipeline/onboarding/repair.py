# SPDX-License-Identifier: AGPL-3.0-only
"""python -m onboarding.repair <CC>: harvest a seeded country and repair its neighbours' border data.

Each step is a subprocess of the existing coverage.run CLI, so behaviour stays
the documented one. Neighbours are recomputed here from region rows, not taken
from the plan. Every step is idempotent: a rerun after a failure is safe."""
from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import time
from dataclasses import dataclass, field

import psycopg

from coverage.run import _dur
from onboarding.neighbours import country_status, extracts_of, neighbours

LOCK_HELD = 2  # coverage.run's exit code when another coverage run holds the advisory lock
_RUN = (sys.executable, "-m", "coverage.run")


@dataclass(frozen=True)
class Step:
    name: str
    argv: tuple[str, ...]
    env: dict[str, str] = field(default_factory=dict)


def plan_steps(own: list[str], neighbour_extracts: list[str]) -> list[Step]:
    steps = [Step("harvest the new country", (*_RUN, "--load-only", "--regions", ",".join(own)))]
    if neighbour_extracts:
        # Full membership: the neighbour's border rows must be re-owned against the new regions.
        steps.append(Step("re-harvest the neighbours", (*_RUN, "--load-only", "--regions", ",".join(neighbour_extracts)),
                          {"COVERAGE_FULL_MEMBERSHIP": "1"}))
    steps.append(Step("rebuild point tiles", (*_RUN, "--tiles-only")))
    both = ",".join(own + neighbour_extracts)
    steps.append(Step("rebuild route tiles", (*_RUN, "--routes", "--regions", both), {"COVERAGE_FORCE_EXTRACT": "1"}))
    steps.append(Step("rebuild surface tiles", (*_RUN, "--surface", "--regions", both), {"COVERAGE_FORCE_EXTRACT": "1"}))
    return steps


def run_step(step: Step) -> int:
    return subprocess.run(step.argv, env={**os.environ, **step.env}, check=False).returncode


def main(argv=None, *, connect=psycopg.connect, runner=run_step, clock=time.monotonic) -> int:
    ap = argparse.ArgumentParser(description="Harvest a seeded country and repair its neighbours")
    ap.add_argument("country", help="ISO 3166-1 alpha-2, e.g. DK")
    args = ap.parse_args(argv)
    cc = args.country.strip().upper()
    if not re.fullmatch(r"[A-Z]{2}", cc):
        print(f"{args.country!r} is not an ISO 3166-1 alpha-2 code", file=sys.stderr)
        return 2

    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    with connect(dsn) as conn:
        status = country_status(conn, cc)
        if status != "seeded":
            print(f"{cc} is {status or 'not planned'}; repair needs a seeded country (app:country:apply {cc} first).",
                  file=sys.stderr)
            return 1
        nbs = neighbours(conn, cc, source="region")
        own = extracts_of(conn, [cc])
        nb_extracts = extracts_of(conn, nbs)
    if not own:
        print(f"{cc} has no country_extract row; nothing to harvest.", file=sys.stderr)
        return 1

    print(f"[repair] {cc}: extracts {','.join(own)}; neighbours {','.join(nbs) or 'none'}")
    for step in plan_steps(own, nb_extracts):
        started = clock()
        rc = runner(step)
        print(f"[repair] {step.name}: rc {rc} in {_dur(clock() - started)}")
        if rc == LOCK_HELD:
            print(f"[repair] {step.name}: another coverage run holds the lock; start this again once it has finished",
                  file=sys.stderr)
            return rc
        if rc != 0:
            return rc
    print(f"[repair] {cc}: done")
    return 0


if __name__ == "__main__":
    sys.exit(main())
