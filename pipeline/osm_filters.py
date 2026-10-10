#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
"""Print the osmium tags-filter expressions the Cycling Commons pipeline uses.

For developers outside the project: run it on our contract file and hand the
output to `osmium tags-filter`, and you hold the same OpenStreetMap objects our
map is built from (wiki/developers/api/openstreetmap-data.md). Standard library
only. tests/test_osm_filters.py holds it to the passes that really run.

    python3 osm_filters.py points coverage-contract.json

Passes:
    points      places: water, food, repair, sleep, climbs and the rest (nodes and ways)
    surface     the roads whose surface the map colours (ways)
    routes      signed cycle routes and their junction numbers (relations, nodes)
    roadpieces  every road, for matching a ride to the roads it used (ways)
"""

from __future__ import annotations

import json
import sys


def points(contract: dict) -> list[str]:
    exprs: list[str] = []
    for spec in contract["letters"].values():
        for selector in spec["selectors"]:
            expr = "nw/" + selector["tag"]
            if expr not in exprs:
                exprs.append(expr)
    return exprs


def surface(contract: dict) -> list[str]:
    return ["w/highway=" + highway for highway in contract["surface"]["highways"]]


def routes(contract: dict) -> list[str]:
    return ["r/route=bicycle,mtb", "n/rcn_ref", "n/lcn_ref"]


def roadpieces(contract: dict) -> list[str]:
    return ["w/highway"]


PASSES = {"points": points, "surface": surface, "routes": routes, "roadpieces": roadpieces}


def main(argv: list[str]) -> int:
    if len(argv) != 3 or argv[1] not in PASSES:
        print(f"usage: {argv[0]} {{{'|'.join(PASSES)}}} coverage-contract.json", file=sys.stderr)
        return 2
    with open(argv[2], encoding="utf-8") as handle:
        contract = json.load(handle)
    print("\n".join(PASSES[argv[1]](contract)))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
