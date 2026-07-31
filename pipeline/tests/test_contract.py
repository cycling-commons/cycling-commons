# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""load_contract() shape + helper tests: the committed coverage-contract.json
must parse into a valid Contract (coverage-provider.md §7).
The PHP twin (web/tests/Catalog/CoverageContractTest.php) pins the same file to
ServiceKind, so together they make Python/PHP mapping drift impossible."""
import json
import pathlib
import sys

import pytest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

from coverage.contract import load_contract  # noqa: E402

# M · Public toilets (added 2026-07-30) skips over L, which stays reserved.
LETTERS = ["C", "D", "E", "G", "H", "I", "J", "M"]
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
    assert contract.letters["C"].tile_props == ["potable"]
    assert contract.letters["D"].tile_props == ["kind"]
    assert contract.letters["E"].tile_props == ["acc"]
    for letter in ("G", "H", "I", "J"):
        assert contract.letters[letter].tile_props == []


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
    assert contract.letters_for({"amenity": "drinking_water"}) == ["C"]
    assert contract.letters_for({"tourism": "hotel", "historic": "castle"}) == ["E", "J"]
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
    del raw["letters"]["J"]
    with pytest.raises(ValueError, match="letters"):
        load_contract(_reload(tmp_path, raw))


def test_rejects_malformed_selector_entry(tmp_path):
    raw = _raw()
    raw["letters"]["C"]["selectors"][0] = {"tag": "no-equals-sign", "label": "Broken"}
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
