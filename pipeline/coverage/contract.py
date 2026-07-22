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
# data and stays out of the coverage artifact; B/F/K are category-3.
LETTERS = frozenset("CDEGHIJ")

# Tag keys tiles.py::_EXTRA_SQL reads back out of `tags` when it builds the
# per-letter tile properties. They must survive the storedTagKeys trim or the
# derived tile column is silently always NULL; load_contract enforces that, and
# test_tiles.py pins this constant to the SQL so the two cannot drift.
TILE_DERIVED_TAG_KEYS = frozenset({"amenity", "drinking_water", "wheelchair"})


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
    # rid/cc region-scoping keys — region-scoping-design.md §6). Per-letter
    # extras live in LetterSpec.tile_props; these are implicit and universal,
    # emitted by tiles.py::_letter_sql for every letter.
    universal_tile_props: list[str]
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
        stored_tag_keys=stored,
    )
