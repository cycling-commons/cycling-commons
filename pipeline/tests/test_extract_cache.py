# SPDX-License-Identifier: AGPL-3.0-only
"""The per-country extract cache — what may be reused, and what must not be.

Adding a country to the surface artifact re-tiles the whole set (PMTiles cannot
be appended to), so the per-country GeoJSONL is the only part worth caching. It
is also the expensive part: a full node-location pass over a national PBF,
against a tiling run that reads files already on disk.

The rule these tests pin down is the conservative one. A false "stale" costs one
extract; a false "current" silently ships last month's roads.
"""

from __future__ import annotations

import os

import pytest

from coverage.run import _extract_is_current


def _touch(path, mtime):
    path.write_text("{}\n", encoding="utf-8")
    os.utime(path, (mtime, mtime))
    return path


def test_a_fresh_extract_is_reused(tmp_path):
    pbf = _touch(tmp_path / "be.osm.pbf", 1000)
    contract = _touch(tmp_path / "contract.json", 1000)
    extract = _touch(tmp_path / "surface_be.geojsonl", 2000)
    assert _extract_is_current(extract, pbf, contract) is True


def test_a_newer_pbf_invalidates_it(tmp_path):
    # The country was re-downloaded: the roads may have changed underneath.
    pbf = _touch(tmp_path / "be.osm.pbf", 3000)
    contract = _touch(tmp_path / "contract.json", 1000)
    extract = _touch(tmp_path / "surface_be.geojsonl", 2000)
    assert _extract_is_current(extract, pbf, contract) is False


def test_a_newer_CONTRACT_invalidates_it_too(tmp_path):
    # The trap this exists for: adding a highway type or a surface class changes
    # what SHOULD be in the extract while leaving the PBF untouched. Without
    # this check the stale file would be tiled as if it were current, and the
    # new class would simply never appear.
    pbf = _touch(tmp_path / "be.osm.pbf", 1000)
    contract = _touch(tmp_path / "contract.json", 3000)
    extract = _touch(tmp_path / "surface_be.geojsonl", 2000)
    assert _extract_is_current(extract, pbf, contract) is False


def test_an_empty_extract_is_never_reused(tmp_path):
    # A zero-byte file is what a killed run leaves behind. Reusing it publishes
    # a country with no roads and no error.
    pbf = _touch(tmp_path / "be.osm.pbf", 1000)
    contract = _touch(tmp_path / "contract.json", 1000)
    extract = tmp_path / "surface_be.geojsonl"
    extract.write_text("", encoding="utf-8")
    os.utime(extract, (2000, 2000))
    assert _extract_is_current(extract, pbf, contract) is False


def test_a_missing_extract_is_not_current(tmp_path):
    pbf = _touch(tmp_path / "be.osm.pbf", 1000)
    contract = _touch(tmp_path / "contract.json", 1000)
    assert _extract_is_current(tmp_path / "nothing.geojsonl", pbf, contract) is False


def test_the_force_hatch_skips_the_cache(tmp_path, monkeypatch):
    # For a pipeline change whose effect no timestamp can show.
    pbf = _touch(tmp_path / "be.osm.pbf", 1000)
    contract = _touch(tmp_path / "contract.json", 1000)
    extract = _touch(tmp_path / "surface_be.geojsonl", 2000)
    monkeypatch.setenv("COVERAGE_FORCE_EXTRACT", "1")
    assert _extract_is_current(extract, pbf, contract) is False


def test_a_touched_but_unchanged_contract_does_NOT_invalidate(tmp_path):
    """The 2026-08-12 waste: the contract was touched, not edited, between the
    extraction and the tiling pass, and three countries were re-extracted for
    nothing. In production it is worse — a deploy sets every file's mtime to
    now, so an mtime rule re-extracts every onboarded country on every release.
    """
    from coverage.run import contract_fingerprint

    pbf = _touch(tmp_path / "be.osm.pbf", 1000)
    contract = _touch(tmp_path / "contract.json", 1000)
    extract = _touch(tmp_path / "surface_be.geojsonl", 2000)
    stamp = tmp_path / "surface_be.stamp"
    stamp.write_text(contract_fingerprint(contract), encoding="utf-8")

    os.utime(contract, (5000, 5000))          # touched, byte-identical
    assert _extract_is_current(extract, pbf, contract, stamp) is True


def test_an_edited_contract_still_invalidates(tmp_path):
    # The protection the mtime rule existed for has to survive the fix: adding a
    # highway class changes what SHOULD be in the extract while leaving the PBF
    # untouched, and a stale file would be tiled as if it were current.
    from coverage.run import contract_fingerprint

    pbf = _touch(tmp_path / "be.osm.pbf", 1000)
    contract = _touch(tmp_path / "contract.json", 1000)
    extract = _touch(tmp_path / "surface_be.geojsonl", 2000)
    stamp = tmp_path / "surface_be.stamp"
    stamp.write_text(contract_fingerprint(contract), encoding="utf-8")

    contract.write_text('{"surface": "now different"}\n', encoding="utf-8")
    assert _extract_is_current(extract, pbf, contract, stamp) is False


def test_a_missing_stamp_is_stale(tmp_path):
    # Written before stamps existed, or left by a run killed mid-extract. One
    # extract is a cheap price for not guessing what shaped a file.
    pbf = _touch(tmp_path / "be.osm.pbf", 1000)
    contract = _touch(tmp_path / "contract.json", 1000)
    extract = _touch(tmp_path / "surface_be.geojsonl", 2000)
    assert _extract_is_current(extract, pbf, contract, tmp_path / "gone.stamp") is False
