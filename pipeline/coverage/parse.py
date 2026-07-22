# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Parse a tags-filtered PBF into PoiRow records (coverage-provider.md §3 step 3).

Nodes keep their location; ways are reduced to the mean of their node
coordinates (decision B1: nodes + ways as centroid — multipolygon relations are
a fast-follow). An object matching several letters yields one PoiRow per
letter, mirroring the coverage_poi UNIQUE(ref, letter) rule.
"""

from __future__ import annotations

import datetime
from collections.abc import Iterator
from dataclasses import dataclass
from pathlib import Path

import osmium

from coverage.contract import Contract


@dataclass(frozen=True)
class PoiRow:
    ref: str            # 'node/<id>' | 'way/<id>' — item.source_ref format
    letter: str         # C D E G H I J (osm-data-architecture.md §5)
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
        self._stored_keys = frozenset(contract.stored_tag_keys)
        self.rows: list[PoiRow] = []

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
    yield from handler.rows
