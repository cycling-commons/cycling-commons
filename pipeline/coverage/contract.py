# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Shared Python/PHP coverage contract (coverage-provider.md §7).

Loads pipeline/contract/coverage-contract.json — the single source of truth for
the OSM selectors (osm-data-architecture.md §5 catalogue), the per-letter tile
properties, and the serviceKind tag mapping mirrored by
web/src/Catalog/ServiceKind.php. Both sides pin this file in their test suites
(pipeline/tests/test_contract.py, web/tests/Catalog/CoverageContractTest.php).
"""
from __future__ import annotations

import json
import pathlib
from dataclasses import dataclass

CONTRACT_PATH = pathlib.Path(__file__).resolve().parents[1] / "contract" / "coverage-contract.json"

# osm-data-architecture.md §5 point catalogue. A (road surface) is corridor
# data and stays out of the coverage artifact; E/N/R are category-3.
LETTERS = frozenset("BCDFGOPQ")

# Tag keys tiles.py::_EXTRA_SQL reads back out of `tags` when it builds the
# per-letter tile properties. They must survive the storedTagKeys trim or the
# derived tile column is silently always NULL; load_contract enforces that, and
# test_tiles.py pins this constant to the SQL so the two cannot drift.
TILE_DERIVED_TAG_KEYS = frozenset({"amenity", "drinking_water", "shop", "wheelchair"})


@dataclass(frozen=True)
class Selector:
    """One OSM selector: tag is the "key=value" rule; label feeds the tile `t`."""

    tag: str    # "shop=bicycle" — exactly as stored in the contract JSON
    key: str    # derived from tag by the loader
    value: str  # derived from tag by the loader
    label: str


@dataclass(frozen=True)
class LetterSpec:
    selectors: list[Selector]
    tile_props: list[str]  # per-letter extras only; ref/n/t are implicit


@dataclass(frozen=True)
class Contract:
    letters: dict[str, LetterSpec]
    service_kind: dict[str, str]  # "key=value" rule -> shop|station|pump (D only)
    # Props carried on EVERY tile layer (ref/n/t identity + t label, plus the
    # rid/cc region-scoping keys — map-and-search.md §4.5). Per-letter
    # extras live in LetterSpec.tile_props; these are implicit and universal,
    # emitted by tiles.py::_letter_sql for every letter.
    universal_tile_props: list[str]
    # The road-surface LINE layer's selectors and class vocabulary
    # (Dated/2026-08-09-surface-line-tiles-design.md). Kept as the raw mapping
    # rather than a typed spec: it is a value table, not a selector list, and
    # the shape it must agree with is the CLIENT's SURFACE_STYLE, which the
    # cross-language test pins directly.
    surface: dict
    # The cycle-route NETWORK layer (route=bicycle/mtb relations + knooppunt
    # nodes — docs/plans/handoffs/2026-08-12-routes-layer-and-surface-quality.md).
    # Raw mapping for the same reason as `surface`: a value table whose real
    # counterpart is the client's network styling, pinned cross-language by
    # routes-zooms.test.cjs.
    routes: dict
    # The ONLY tag keys parse.py writes into coverage_poi.tags — the serve-set
    # of the narrow serving cache (osm-data-architecture.md §1 principle 4,
    # coverage-provider.md §2). `osmium tags-filter` selects OBJECTS, not keys,
    # so a matching object arrives with every tag it carries; without this trim
    # the cache stores ~4,200 distinct keys of which nothing reads more than 27.
    # Three groups: selector keys (classification + tiles.py label), the drawer's
    # TAG_WHITELIST, and the provisional media/reference group. `name` is absent
    # by design — it is promoted to the coverage_poi.name column.
    stored_tag_keys: list[str]

    def letters_for(self, tags: dict) -> list[str]:
        """Letters whose selectors match `tags`, in catalogue order (one object
        may carry several letters — mirrors coverage_poi UNIQUE(ref, letter))."""
        out: list[str] = []
        for letter, spec in self.letters.items():
            if any(tags.get(sel.key) == sel.value for sel in spec.selectors):
                out.append(letter)
        return out

    def kind_for(self, tags: dict) -> str | None:
        """serviceKind (shop|station|pump) per the contract tag rules, else None.

        Same mapping as App\\Catalog\\ServiceKind::fromOsmTags() — kept in sync
        by the cross-language contract tests (coverage-provider.md §7).
        """
        for rule, kind in self.service_kind.items():
            key, _, value = rule.partition("=")
            if tags.get(key) == value:
                return kind
        return None


def _selector(letter: str, entry: object) -> Selector:
    if not isinstance(entry, dict) or set(entry) != {"tag", "label"}:
        raise ValueError(
            f"letter {letter}: selector entries must be {{tag, label}} objects, got {entry!r}")
    tag, label = str(entry["tag"]), str(entry["label"])
    key, eq, value = tag.partition("=")
    if not key or not eq or not value or not label:
        raise ValueError(
            f"letter {letter}: malformed selector {entry!r} (tag must be key=value, label non-empty)")
    return Selector(tag=tag, key=key, value=value, label=label)


def load_contract(path: pathlib.Path = CONTRACT_PATH) -> Contract:
    """Parse + validate the contract file; raises ValueError on any drift."""
    raw = json.loads(path.read_text(encoding="utf-8"))
    if raw.get("version") != 1:
        raise ValueError(f"unsupported contract version: {raw.get('version')!r}")

    if "letters" not in raw:
        raise ValueError("contract missing top-level \"letters\" key")

    letters = {
        letter: LetterSpec(
            selectors=[_selector(letter, entry) for entry in spec["selectors"]],
            tile_props=list(spec.get("tileProps", [])),
        )
        for letter, spec in raw["letters"].items()
    }
    if set(letters) != LETTERS:
        raise ValueError(
            f"contract letters {sorted(letters)} != osm-data-architecture.md §5 "
            f"set {sorted(LETTERS)}")
    for letter, spec in letters.items():
        if not spec.selectors:
            raise ValueError(f"letter {letter} has no selectors")

    if "surface" not in raw:
        raise ValueError("contract missing top-level \"surface\" key")
    surface = raw["surface"]
    # `cyclewayClass` is gone: road type is not a colour in the surface scale
    # (owner 2026-08-12) — see surface._roadTypeChannel in the contract.
    for required in ("highways", "classes", "untaggedClass", "minZoom", "maxZoom"):
        if required not in surface:
            raise ValueError(f"contract surface section missing {required!r}")
    # A value may not sit in two classes: the first match would win silently and
    # the same road would be gravel on one build and dirt on the next reorder.
    seen: dict[str, str] = {}
    for cls, values in surface["classes"].items():
        for v in values:
            if v in seen:
                raise ValueError(f"surface value {v!r} is in both {seen[v]!r} and {cls!r}")
            seen[v] = cls
    # A gate can only gate a highway that is actually selected.
    for gated in surface.get("gatedHighways", {}):
        if gated not in surface["highways"]:
            raise ValueError(f"gatedHighways names {gated!r}, which is not in highways")

    # The to-do arm is a SUBSET of the selected network, never a second
    # selector: it is drawn from the same extract pass, so a highway nobody
    # extracts can never appear in it, and naming one here would silently
    # promise a layer that is always empty.
    for required in ("highways", "minZoom", "maxZoom"):
        if required not in surface.get("todo", {}):
            raise ValueError(f"contract surface.todo section missing {required!r}")
    for hw in surface["todo"]["highways"]:
        if hw not in surface["highways"]:
            raise ValueError(
                f"surface.todo.highways names {hw!r}, which is not in surface.highways — "
                "the to-do arm is filtered out of the same extract, not selected separately")

    # The quality channel rides the classified arm as `sm`/`mtb` (owner shape,
    # 2026-08-12): the value list is the gate — an OSM smoothness value not
    # named here is DROPPED at extract time rather than shipped for the client
    # to guess a colour for, so the list and the client's tone map have to be
    # the same set (surface-quality.test.cjs pins them).
    for required in ("tag", "values", "minZoom"):
        if required not in surface.get("quality", {}):
            raise ValueError(f"contract surface.quality section missing {required!r}")
    if not surface["quality"]["values"]:
        raise ValueError("contract surface.quality.values must name at least one value")
    for prop in ("sm", "mtb"):
        if prop not in surface.get("tileProps", []):
            raise ValueError(
                f"surface.tileProps must carry {prop!r} — the quality channel rides the "
                "classified arm, and a promise the extractor does not emit is a client "
                "reading absent data as 'nobody has said'")

    for required in ("cellZoom", "minZoom", "maxZoom", "tileProps"):
        if required not in surface.get("gaps", {}):
            raise ValueError(f"contract surface.gaps section missing {required!r}")
    # The grid and the lines are one legend row shown at two resolutions, so
    # the handover has to be exact: a gap between them is a zoom level where a
    # rider who asked "what needs recording?" is shown nothing at all, and an
    # overlap draws squares on top of the roads they summarise.
    if surface["gaps"]["maxZoom"] != surface["todo"]["minZoom"]:
        raise ValueError(
            f"surface.gaps.maxZoom ({surface['gaps']['maxZoom']}) must equal "
            f"surface.todo.minZoom ({surface['todo']['minZoom']}) — the grid hands over "
            "to the lines at exactly one zoom, with no gap and no overlap")

    if "routes" not in raw:
        raise ValueError("contract missing top-level \"routes\" key")
    routes = raw["routes"]
    for required in ("networks", "minZoom", "maxZoom", "tileProps"):
        if required not in routes:
            raise ValueError(f"contract routes section missing {required!r}")
    if not routes["networks"]:
        raise ValueError("contract routes.networks must name at least one network")
    for required in ("minZoom", "tileProps"):
        if required not in routes.get("nodes", {}):
            raise ValueError(f"contract routes.nodes section missing {required!r}")
    # The knooppunt numbers live INSIDE the routes artifact (a per-feature
    # tippecanoe minzoom, not a second archive), so their floor must sit within
    # the archive's zoom span or tippecanoe silently clamps and the client's
    # pinned handover zoom stops being true.
    if not routes["minZoom"] <= routes["nodes"]["minZoom"] <= routes["maxZoom"]:
        raise ValueError(
            f"routes.nodes.minZoom ({routes['nodes']['minZoom']}) must sit within the "
            f"artifact's z{routes['minZoom']}-{routes['maxZoom']} span")

    if "serviceKind" not in raw:
        raise ValueError("contract missing top-level \"serviceKind\" key")

    service_kind = dict(raw["serviceKind"])
    d_rules = [sel.tag for sel in letters["D"].selectors]
    if list(service_kind) != d_rules:
        raise ValueError("serviceKind rules must be exactly the D letter selectors")

    if "universalTileProps" not in raw:
        raise ValueError("contract missing top-level \"universalTileProps\" key")
    universal = list(raw["universalTileProps"])
    if universal != ["ref", "n", "t", "ridtok", "cctok"]:
        raise ValueError(
            f"universalTileProps must be [ref, n, t, ridtok, cctok] (got {universal}) — "
            "tiles.py::_letter_sql emits exactly these on every layer, and "
            "_universal_props asserts equality so the two never drift")

    if "storedTagKeys" not in raw:
        raise ValueError("contract missing top-level \"storedTagKeys\" key")
    stored = list(raw["storedTagKeys"])
    if stored != sorted(set(stored)):
        raise ValueError("storedTagKeys must be sorted and unique (reviewable diffs)")
    if "name" in stored:
        raise ValueError(
            "storedTagKeys must not list \"name\": it is promoted to the "
            "coverage_poi.name column and stripped from tags (coverage-provider.md §2)")
    selector_keys = {sel.key for spec in letters.values() for sel in spec.selectors}
    if missing := sorted(selector_keys - set(stored)):
        raise ValueError(
            f"storedTagKeys drops selector key(s) {missing} — a row classified by a "
            "key that is not stored loses it before tiles.py::_label_case runs")
    if missing := sorted(TILE_DERIVED_TAG_KEYS - set(stored)):
        raise ValueError(
            f"storedTagKeys drops tile-derived key(s) {missing} — tiles.py::_EXTRA_SQL "
            "reads them back out of tags, so the derived property would always be NULL")

    return Contract(
        letters=letters,
        service_kind=service_kind,
        universal_tile_props=universal,
        surface=surface,
        routes=routes,
        stored_tag_keys=stored,
    )
