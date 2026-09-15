# SPDX-License-Identifier: AGPL-3.0-only
"""Parse a tags-filtered PBF into PoiRow records (coverage-provider.md §3 step 3).

Nodes keep their location; ways are reduced to the mean of their node
coordinates (decision B1: nodes + ways as centroid — multipolygon relations are
a fast-follow). An object matching several letters yields one PoiRow per
letter, mirroring the coverage_poi UNIQUE(ref, letter) rule.

A ferry dock (`amenity=ferry_terminal` node) links the `route=ferry` ways whose
first or last node it is, and a dock with no `bicycle` tag of its own inherits
their bike answer, under the `cc:` keys of contract.ROUTE_INHERITED_TAG_KEYS
(coverage-provider.md §3).
"""

from __future__ import annotations

import dataclasses
import datetime
import sys
from collections import Counter
from collections.abc import Iterator
from dataclasses import dataclass
from pathlib import Path

import osmium

from coverage.contract import DERIVED_TAG_PREFIX, Contract

# OSM `bicycle` values in the order they win at a dock: a route that lets a bike
# on beats one that has you walk it, which beats one that says no. The words a
# rider reads for each live in web/assets/map/osm-tags.js (bikesOnBoard).
_BIKE_CLASS = {"yes": "allowed", "designated": "allowed", "permissive": "allowed",
               "dismount": "dismount", "no": "no"}


@dataclass(frozen=True)
class PoiRow:
    ref: str            # 'node/<id>' | 'way/<id>' — item.source_ref format
    letter: str         # B C D F G O P Q (osm-data-architecture.md §5)
    kind: str | None    # serviceKind for D (shop|station|pump), None otherwise
    name: str | None
    lon: float
    lat: float
    tags: dict[str, str]              # object's tags trimmed to contract.stored_tag_keys
    osm_version: int | None           # upstream version (Plan 3 snapshot source)
    osm_ts: datetime.datetime | None  # upstream last-edit timestamp
    src_region: str
    country_code: str | None


class _Collector(osmium.SimpleHandler):
    """Collects a PoiRow per matching letter for every node/way in the filtered PBF."""

    def __init__(self, contract: Contract, src_region: str, country_code: str | None):
        super().__init__()
        self._contract = contract
        self._src_region = src_region
        self._country_code = country_code
        # Set for O(1) membership in the per-object trim below (hot path: every
        # tag of every matching object in the extract).
        # Keys under DERIVED_TAG_PREFIX are written by this module only.
        self._stored_keys = frozenset(k for k in contract.stored_tag_keys
                                      if not k.startswith(DERIVED_TAG_PREFIX))
        self.rows: list[PoiRow] = []
        # node id -> [(way id, bicycle, bicycle:fee)] of the ferry routes that end there.
        self.ferry_ends: dict[int, list[tuple[int, str, str]]] = {}

    def node(self, n) -> None:
        tags = {t.k: t.v for t in n.tags}
        if not self._contract.letters_for(tags):
            return  # untagged/non-selector nodes (way corners survive tags-filter)
        self._emit(f"node/{n.id}", tags, n.location.lon, n.location.lat, n)

    def way(self, w) -> None:
        tags = {t.k: t.v for t in w.tags}
        if not self._contract.letters_for(tags):
            return
        refs = list(w.nodes)
        if tags.get("route") == "ferry" and refs:
            bike = (tags.get("bicycle", ""), tags.get("bicycle:fee", ""))
            for node_id in {refs[0].ref, refs[-1].ref}:
                self.ferry_ends.setdefault(node_id, []).append((w.id, *bike))
        # Closed way: drop the repeated closing node so it doesn't bias the mean.
        if len(refs) > 1 and refs[0].ref == refs[-1].ref:
            refs = refs[:-1]
        lons = [r.location.lon for r in refs if r.location.valid()]
        lats = [r.location.lat for r in refs if r.location.valid()]
        if not lons:  # node locations missing from the extract — skip
            return
        self._emit(f"way/{w.id}", tags, sum(lons) / len(lons), sum(lats) / len(lats), w)

    def _emit(self, ref: str, tags: dict[str, str], lon: float, lat: float, obj) -> None:
        kind = self._contract.kind_for(tags)
        for letter in sorted(self._contract.letters_for(tags)):
            self.rows.append(PoiRow(
                ref=ref,
                letter=letter,
                kind=kind if letter == "D" else None,
                name=tags.get("name"),
                lon=lon,
                lat=lat,
                # Trimmed to the contract's serve-set. `osmium tags-filter`
                # selects OBJECTS, not keys, so `tags` here is the object's FULL
                # tag dict — a single memorial can arrive with 20 keys. Storing
                # all of them made coverage_poi a bulk OSM copy, which
                # osm-data-architecture.md §1 principle 1 forbids and principle 4
                # replaces with a narrow serving cache; measured at 3 countries,
                # 52 % of the stored tags payload was keys nothing reads
                # (coverage-provider.md §2). `name` is excluded by the contract
                # (promoted to the dedicated column), as is `email` (redundant
                # against website/phone, and often a private mailbox — storing
                # personal data we never serve is the liability without the
                # benefit). Each sibling row still gets its own dict, so a
                # consumer mutating one row's tags can't corrupt another.
                tags={k: v for k, v in tags.items() if k in self._stored_keys},
                osm_version=obj.version or None,
                osm_ts=obj.timestamp if obj.version else None,
                src_region=self._src_region,
                country_code=self._country_code,
            ))


def inherit_from_ferry_routes(routes: list[tuple[int, str, str]]) -> dict[str, str]:
    """The `cc:` tags a dock takes from the ferry routes that end at it.

    `routes` is (way id, bicycle, bicycle:fee) per route. Any route that lets a
    bike on gives "allowed" (its own value, the lowest way id first); else any
    that has you walk it gives "dismount"; else "no" when every route that has
    a `bicycle` tag says no. A route with no `bicycle` tag adds nothing, and a
    value we cannot word keeps the dock from a "no". The fee comes along only
    when every route that gave the answer says `bicycle:fee=yes`.
    """
    tagged = [(way_id, bike.strip().lower(), fee.strip().lower())
              for way_id, bike, fee in sorted(routes) if bike.strip()]
    for cls in ("allowed", "dismount"):
        winners = [r for r in tagged if _BIKE_CLASS.get(r[1]) == cls]
        if winners:
            out = {"cc:bicycle_from_route": winners[0][1],
                   "cc:ferry_route": ";".join(f"way/{way_id}" for way_id, _b, _f in winners)}
            if all(fee == "yes" for _w, _b, fee in winners):
                out["cc:bicycle:fee_from_route"] = "yes"
            return out
    if tagged and all(bike == "no" for _w, bike, _f in tagged):
        return {"cc:bicycle_from_route": "no",
                "cc:ferry_route": ";".join(f"way/{way_id}" for way_id, _b, _f in tagged)}
    return {}


def dock_route_tags(own_bicycle: bool, routes: list[tuple[int, str, str]]) -> dict[str, str]:
    """The `cc:` tags a dock gets from the ferry routes that end at it.

    A dock with no `bicycle` tag of its own takes their bike answer
    (inherit_from_ferry_routes), and `cc:ferry_route` names the routes that
    gave it. Otherwise, with its own tag or when no route gives an answer, the
    dock takes no answer and `cc:ferry_route` names every route that ends at
    it, so the drawer can still show the crossing facts of its one route.
    """
    inherited = {} if own_bicycle else inherit_from_ferry_routes(routes)
    if inherited or not routes:
        return inherited
    return {"cc:ferry_route": ";".join(f"way/{way_id}" for way_id in sorted({r[0] for r in routes}))}


def _with_inherited_bike_access(rows: list[PoiRow], ferry_ends: dict[int, list[tuple[int, str, str]]],
                                src_region: str) -> list[PoiRow]:
    """Rows with each ferry dock's route `cc:` tags added (letter F only)."""
    counts: Counter[str] = Counter()
    linked = 0
    out = []
    for row in rows:
        if row.letter == "F" and row.ref.startswith("node/") and row.tags.get("amenity") == "ferry_terminal":
            derived = dock_route_tags("bicycle" in row.tags, ferry_ends.get(int(row.ref[5:]), []))
            if derived:
                linked += 1
                if "cc:bicycle_from_route" in derived:
                    counts[derived["cc:bicycle_from_route"]] += 1
                row = dataclasses.replace(row, tags={**row.tags, **derived})
        out.append(row)
    if linked:
        print(f"[coverage] {src_region}: ferry docks linked to their routes: {linked}; inherit bike access: "
              + (", ".join(f"{value} {n}" for value, n in sorted(counts.items())) or "none"), file=sys.stderr)
    return out


def parse_pois(
    filtered_pbf: Path,
    contract: Contract,
    src_region: str,
    country_code: str | None,
) -> Iterator[PoiRow]:
    """Yield PoiRows from a tags-filtered PBF (one row per matching letter)."""
    handler = _Collector(contract, src_region, country_code)
    # locations=True + flex_mem index: way-node coordinates for the centroid.
    handler.apply_file(str(filtered_pbf), locations=True, idx="flex_mem")
    yield from _with_inherited_bike_access(handler.rows, handler.ferry_ends, src_region)
