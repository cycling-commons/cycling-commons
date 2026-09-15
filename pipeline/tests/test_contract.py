# SPDX-License-Identifier: AGPL-3.0-only
"""load_contract() shape + helper tests: the committed coverage-contract.json
must parse into a valid Contract (coverage-provider.md §7).
The PHP twin (web/tests/Catalog/CoverageContractTest.php) pins the same file to
ServiceKind, so together they make Python/PHP mapping drift impossible."""
import json
import pathlib
import sys

import pytest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

from coverage.contract import CONTRACT_PATH, load_contract  # noqa: E402

# Public toilets (added 2026-07-30 as M) became C in the 2026-08-25 renumbering:
# practical categories A-M, experiential N-Z.
LETTERS = ["B", "C", "D", "F", "G", "O", "P", "Q"]
RAW_PATH = pathlib.Path(__file__).resolve().parents[1] / "contract" / "coverage-contract.json"


def _raw():
    return json.loads(RAW_PATH.read_text(encoding="utf-8"))


def _reload(tmp_path, raw):
    bad = tmp_path / "bad.json"
    bad.write_text(json.dumps(raw), encoding="utf-8")
    return bad


def test_loads_committed_contract_letters():
    contract = load_contract()
    assert sorted(contract.letters) == LETTERS


def test_selectors_carry_tag_key_value_label():
    contract = load_contract()
    for letter, spec in contract.letters.items():
        assert spec.selectors, f"letter {letter} has no selectors"
        for sel in spec.selectors:
            assert sel.tag == f"{sel.key}={sel.value}", (letter, sel)
            assert sel.label, (letter, sel)
        assert isinstance(spec.tile_props, list)


def test_letter_specific_tile_props():
    contract = load_contract()
    # `food` joined `potable` on 2026-09-04: letter B is water AND food, and
    # 44% of its rows are shops and eateries that drew a water drop because a
    # letter was all the tile said.
    # `cd` joined every letter on 2026-09-10: a dated OSM `check_date`
    # is a published witness (data-provider-hierarchy.md §6.7.7, rung 8),
    # and the badge on a coverage disc reads it.
    assert contract.letters["B"].tile_props == ["potable", "food", "cd"]
    assert contract.letters["D"].tile_props == ["kind", "cd"]
    assert contract.letters["O"].tile_props == ["acc", "cd"]
    for letter in ("F", "G", "P", "Q"):
        assert contract.letters[letter].tile_props == ["cd"]


def test_universal_tile_props_carry_the_scope_keys():
    # ref/n/t identity + the ridtok/cctok region-scoping tokens are emitted on
    # EVERY layer (map-and-search.md §4.5): pipe-delimited membership tokens
    # so tippecanoe can union them across a cluster (finding 2). Per-letter
    # tileProps stay extras-only.
    contract = load_contract()
    assert contract.universal_tile_props == ["ref", "n", "t", "ridtok", "cctok"]


def test_universal_tile_props_required_not_optional(tmp_path):
    # A contract missing universalTileProps is an error, not a silent empty list
    # (finding 18: load_contract used to accept the absent key without complaint).
    raw = _raw()
    del raw["universalTileProps"]
    with pytest.raises(ValueError, match="universalTileProps"):
        load_contract(_reload(tmp_path, raw))


def test_service_kind_mapping_matches_php_service_kind_cases():
    contract = load_contract()
    assert contract.service_kind == {
        "shop=bicycle": "shop",
        "amenity=bicycle_repair_station": "station",
        "amenity=compressed_air": "pump",
    }
    assert [s.tag for s in contract.letters["D"].selectors] == list(contract.service_kind)


def test_letters_for_matches_selectors():
    contract = load_contract()
    assert contract.letters_for({"shop": "bicycle"}) == ["D"]
    assert contract.letters_for({"amenity": "drinking_water"}) == ["B"]
    assert contract.letters_for({"tourism": "hotel", "historic": "castle"}) == ["O", "Q"]
    assert contract.letters_for({"amenity": "bench"}) == []


def test_kind_for_matches_service_kind_rules():
    contract = load_contract()
    assert contract.kind_for({"shop": "bicycle"}) == "shop"
    assert contract.kind_for({"amenity": "bicycle_repair_station"}) == "station"
    assert contract.kind_for({"amenity": "compressed_air"}) == "pump"
    assert contract.kind_for({"tourism": "hotel"}) is None


def test_stored_tag_keys_are_sorted_unique_and_exclude_name():
    # Sorted + unique keeps contract diffs reviewable. `name` must never appear:
    # it is promoted to the dedicated coverage_poi.name column and stripped from
    # tags (commit d2ce930) — listing it here would silently reintroduce the
    # duplication on every named row.
    keys = load_contract().stored_tag_keys
    assert keys == sorted(set(keys))
    assert "name" not in keys
    # Deliberate exclusions, not oversights (coverage-provider.md §2): email is
    # ~99 % redundant against website/phone and is often a private mailbox.
    for excluded in ("email", "contact:email", "addr:postcode"):
        assert excluded not in keys


def test_stored_tag_keys_cover_every_selector_key():
    # A selector key that isn't stored would classify the row at parse time and
    # then vanish, breaking tiles.py::_label_case on the next tile build.
    contract = load_contract()
    selector_keys = {
        sel.key for spec in contract.letters.values() for sel in spec.selectors
    }
    assert selector_keys <= set(contract.stored_tag_keys)


def test_stored_tag_keys_cover_the_tile_derived_keys():
    # tiles.py::_EXTRA_SQL reads these back out of tags when building the
    # per-letter tile properties; test_tiles.py pins the constant to _EXTRA_SQL.
    from coverage.contract import TILE_DERIVED_TAG_KEYS
    assert TILE_DERIVED_TAG_KEYS <= set(load_contract().stored_tag_keys)


def test_rejects_contract_missing_stored_tag_keys(tmp_path):
    raw = _raw()
    del raw["storedTagKeys"]
    with pytest.raises(ValueError, match="storedTagKeys"):
        load_contract(_reload(tmp_path, raw))


def test_rejects_stored_tag_keys_dropping_a_selector_key(tmp_path):
    raw = _raw()
    raw["storedTagKeys"] = [k for k in raw["storedTagKeys"] if k != "historic"]
    with pytest.raises(ValueError, match="selector key"):
        load_contract(_reload(tmp_path, raw))


def test_rejects_stored_tag_keys_dropping_a_tile_derived_key(tmp_path):
    raw = _raw()
    raw["storedTagKeys"] = [k for k in raw["storedTagKeys"] if k != "wheelchair"]
    with pytest.raises(ValueError, match="tile"):
        load_contract(_reload(tmp_path, raw))


def test_rejects_unsorted_stored_tag_keys(tmp_path):
    raw = _raw()
    raw["storedTagKeys"] = list(reversed(raw["storedTagKeys"]))
    with pytest.raises(ValueError, match="sorted"):
        load_contract(_reload(tmp_path, raw))


def test_rejects_stored_tag_keys_carrying_name(tmp_path):
    raw = _raw()
    raw["storedTagKeys"] = sorted([*raw["storedTagKeys"], "name"])
    with pytest.raises(ValueError, match="name"):
        load_contract(_reload(tmp_path, raw))


def test_rejects_contract_missing_a_catalogue_letter(tmp_path):
    raw = _raw()
    del raw["letters"]["Q"]
    with pytest.raises(ValueError, match="letters"):
        load_contract(_reload(tmp_path, raw))


def test_rejects_malformed_selector_entry(tmp_path):
    raw = _raw()
    raw["letters"]["B"]["selectors"][0] = {"tag": "no-equals-sign", "label": "Broken"}
    with pytest.raises(ValueError, match="selector"):
        load_contract(_reload(tmp_path, raw))


def test_rejects_contract_missing_letters_key(tmp_path):
    raw = _raw()
    del raw["letters"]
    with pytest.raises(ValueError, match="letters"):
        load_contract(_reload(tmp_path, raw))


def test_rejects_contract_missing_service_kind_key(tmp_path):
    raw = _raw()
    del raw["serviceKind"]
    with pytest.raises(ValueError, match="serviceKind"):
        load_contract(_reload(tmp_path, raw))


def test_every_tile_prop_has_its_sql_fragment():
    # tiles.py::_letter_sql looks each per-letter extra up in _EXTRA_SQL; a
    # prop the contract lists and the SQL does not know would KeyError at
    # export time, after the harvest.
    from coverage.tiles import _EXTRA_SQL
    from coverage.contract import TILE_DERIVED_TAG_KEYS
    contract = load_contract()
    for letter, spec in contract.letters.items():
        for prop in spec.tile_props:
            assert prop in _EXTRA_SQL, f"{letter}: tile prop {prop!r} has no SQL fragment"
    # The witness date is read out of `tags`, so the trim must keep it.
    assert "check_date" in TILE_DERIVED_TAG_KEYS
    assert "check_date" in contract.stored_tag_keys


# --- scenic views sit along a bike way (docs/specs/scenic-views.md) -----------

def test_scenic_selects_no_peaks():
    """A peak's point is its summit, where no rider is; measured 2026-09-14,
    at most 5 of 207 Valais peaks sat within 200 m of a bike way."""
    tags = [s.tag for s in load_contract().letters["P"].selectors]
    assert "natural=peak" not in tags
    assert tags == ["tourism=viewpoint", "waterway=waterfall"]


def test_scenic_carries_a_near_way_rule_and_no_other_letter_does():
    letters = load_contract().letters
    assert letters["P"].near_way is not None
    assert letters["P"].near_way.within_m == 250
    assert all(spec.near_way is None for letter, spec in letters.items() if letter != "P")


def test_near_way_rideable_is_a_road_a_cycleway_or_a_bike_tagged_path():
    rule = load_contract().letters["P"].near_way
    assert rule.rideable({"highway": "tertiary"})
    assert rule.rideable({"highway": "unclassified", "surface": "gravel"}), "gravel roads count"
    assert rule.rideable({"highway": "cycleway"})
    assert rule.rideable({"highway": "track", "bicycle": "designated"})
    assert rule.rideable({"highway": "path", "bicycle": "yes"})
    assert not rule.rideable({"highway": "path"}), "a hiking path is not a bike way"
    assert not rule.rideable({"highway": "footway"})
    assert not rule.rideable({"highway": "track"})
    assert not rule.rideable({"highway": "motorway"})
    assert not rule.rideable({"highway": "tertiary", "bicycle": "no"})
    assert not rule.rideable({"bicycle": "yes"}), "a bicycle tag without a highway is not a way"


def test_rejects_a_malformed_near_way(tmp_path):
    raw = json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))
    raw["letters"]["P"]["nearWay"] = {"withinM": 100}
    bad = tmp_path / "c.json"
    bad.write_text(json.dumps(raw), encoding="utf-8")
    with pytest.raises(ValueError, match="nearWay"):
        load_contract(bad)


def test_scenic_requires_a_name_or_a_photo_link():
    """A bare tourism=viewpoint says someone found a view; it does not say what
    you see or show it. 2026-09-14: 3 in 4 scenic points in NL and CH had
    neither a name nor a photo link."""
    letters = load_contract().letters
    assert letters["P"].name_or_tags == ["image", "wikidata", "wikimedia_commons"]
    assert all(spec.name_or_tags is None for letter, spec in letters.items() if letter not in {"P", "Q"})


def test_history_requires_a_name_or_a_photo_link_and_drops_small_memorials():
    """History & culture is the biggest layer: 478,720 points on 2026-09-15.
    In NL an unnamed memorial or burial mound, a Stolperstein or a plaque is
    not a place to ride to (docs/specs/coverage-provider.md §3)."""
    letters = load_contract().letters
    assert letters["Q"].name_or_tags == ["image", "wikidata", "wikimedia_commons"]
    assert letters["Q"].exclude_tag_values == {
        "memorial": ["bench", "blue_plaque", "ghost_bike", "grave", "plaque", "stolperstein", "tomb"]}
    assert all(spec.exclude_tag_values is None for letter, spec in letters.items() if letter != "Q")
    assert "memorial" in load_contract().stored_tag_keys


def test_rejects_an_exclude_tag_values_key_not_in_the_stored_tags(tmp_path):
    raw = json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))
    raw["letters"]["Q"]["excludeTagValues"] = {"not_stored": ["x"]}
    bad = tmp_path / "c.json"
    bad.write_text(json.dumps(raw), encoding="utf-8")
    with pytest.raises(ValueError, match="excludeTagValues"):
        load_contract(bad)


def test_rejects_a_malformed_exclude_tag_values(tmp_path):
    raw = json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))
    raw["letters"]["Q"]["excludeTagValues"] = {"memorial": []}
    bad = tmp_path / "c.json"
    bad.write_text(json.dumps(raw), encoding="utf-8")
    with pytest.raises(ValueError, match="excludeTagValues"):
        load_contract(bad)


def test_rejects_a_name_or_tags_key_not_in_the_stored_tags(tmp_path):
    raw = json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))
    raw["letters"]["P"]["nameOrTags"] = ["image", "not_stored"]
    bad = tmp_path / "c.json"
    bad.write_text(json.dumps(raw), encoding="utf-8")
    with pytest.raises(ValueError, match="nameOrTags"):
        load_contract(bad)
