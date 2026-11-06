# SPDX-License-Identifier: AGPL-3.0-only
"""python -m onboarding.repair <CC> [<CC> ...]: harvest seeded countries and repair their neighbours' border data.

Several countries onboarded together are repaired in one pass: each extract is
harvested once, so a neighbour two of them share (France for Andorra and
Monaco) is re-harvested once, not once per country.

For a live country with added regions (region.pending), every extract of the
country is re-harvested with full membership: the rows its older regions own
along the new borders must be re-owned too.

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


def plan_steps(own: list[str], neighbour_extracts: list[str], adding: bool = False) -> list[Step]:
    # An extract the new country shares with a neighbour (Ireland's is also
    # Great Britain's) is harvested once, in the neighbours' step: it holds
    # the neighbour's border rows too, so it needs the full membership pass.
    # Regions added to a live country border its own older regions, so then
    # every extract of the country goes through that pass as well.
    if adding:
        neighbour_extracts = list(dict.fromkeys(own + neighbour_extracts))
    only_own = [e for e in own if e not in neighbour_extracts]
    steps = []
    if only_own:
        steps.append(Step("harvest the new country", (*_RUN, "--load-only", "--regions", ",".join(only_own))))
    if neighbour_extracts:
        # Full membership: the neighbour's border rows must be re-owned against the new regions.
        steps.append(Step("re-harvest the neighbours", (*_RUN, "--load-only", "--regions", ",".join(neighbour_extracts)),
                          {"COVERAGE_FULL_MEMBERSHIP": "1"}))
    steps.append(Step("rebuild point tiles", (*_RUN, "--tiles-only")))
    both = ",".join(dict.fromkeys(own + neighbour_extracts))
    steps.append(Step("rebuild route tiles", (*_RUN, "--routes", "--regions", both), {"COVERAGE_FORCE_EXTRACT": "1"}))
    steps.append(Step("rebuild surface tiles", (*_RUN, "--surface", "--regions", both), {"COVERAGE_FORCE_EXTRACT": "1"}))
    return steps


def run_step(step: Step) -> int:
    return subprocess.run(step.argv, env={**os.environ, **step.env}, check=False).returncode


def main(argv=None, *, connect=psycopg.connect, runner=run_step, clock=time.monotonic) -> int:
    # A pipe block-buffers stdout while the children write straight to the fd; keep our lines in order.
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(line_buffering=True)
    ap = argparse.ArgumentParser(description="Harvest seeded countries and repair their neighbours")
    ap.add_argument("countries", nargs="+", metavar="country", help="ISO 3166-1 alpha-2, e.g. DK; several repair together")
    args = ap.parse_args(argv)
    codes = list(dict.fromkeys(c.strip().upper() for c in args.countries))
    if bad := [c for c in codes if not re.fullmatch(r"[A-Z]{2}", c)]:
        print(f"{', '.join(map(repr, bad))} is not an ISO 3166-1 alpha-2 code", file=sys.stderr)
        return 1

    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    added: dict[str, list[str]] = {}
    nbs: list[str] = []
    with connect(dsn) as conn:
        for cc in codes:
            status = country_status(conn, cc)
            pending = [slug for (slug,) in conn.execute(
                "SELECT slug FROM region WHERE country_code = %s AND pending ORDER BY slug", (cc,))]
            if status == "live" and pending:
                added[cc] = pending
            elif status != "seeded":
                print(f"{cc} is {status or 'not planned'}; repair needs a seeded country (app:country:apply {cc} first), "
                      f"or a live one with added regions.", file=sys.stderr)
                return 1
            nbs += neighbours(conn, cc, source="pending" if cc in added else "region")
        # Two countries of this pass that border each other (seeded rows count as neighbours) each hold the
        # other's border rows in their extracts, so those extracts take the full membership pass too.
        touching = sorted(set(nbs) & set(codes))
        nbs = sorted(set(nbs) - set(codes))
        own = extracts_of(conn, codes)
        nb_extracts = sorted(set(extracts_of(conn, nbs)) | set(extracts_of(conn, touching)))
        if missing := [cc for cc in codes if not extracts_of(conn, [cc])]:
            print(f"{', '.join(missing)} has no country_extract row; nothing to harvest.", file=sys.stderr)
            return 1
    adding = bool(added)
    label = ",".join(codes)

    print(f"[repair] {label}: extracts {','.join(own)}; neighbours {','.join(nbs) or 'none'}"
          + (f"; adding {','.join(slug for slugs in added.values() for slug in slugs)}" if added else ""))
    for step in plan_steps(own, nb_extracts, adding):
        print(f"[repair] {step.name}: starting")
        started = clock()
        rc = runner(step)
        print(f"[repair] {step.name}: rc {rc} in {_dur(clock() - started)}")
        if rc == LOCK_HELD:
            print(f"[repair] {step.name}: another coverage, routes or surface run holds its lock; start this again once it has finished",
                  file=sys.stderr)
            return rc
        if rc != 0:
            return rc
    print(f"[repair] {label}: done")
    return 0


if __name__ == "__main__":
    sys.exit(main())
