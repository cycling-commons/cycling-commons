# SPDX-License-Identifier: AGPL-3.0-only
"""The harvester's entry point (data-provider-hierarchy.md §8).

`--out -` is how a refresh hands its file to the ingest in another container
(tools/provider-run.sh): standard output must carry the GeoJSON and nothing
else, or the ingest reads a status line as JSON. No network and no database:
the registry row and the service reply are stubbed.
"""

import json

import pytest

from providers import run

PROVIDER = {
    "key": "test-taps",
    "name": "Test taps",
    "endpoint": "https://example.test/wfs",
    "endpoint_kind": "wfs",
    "field_map": {"_layer": "test:taps", "_id": "id", "name": "naam"},
    "letters": ["B"],
    "country_code": "NL",
    "enabled": True,
}


def reply(*features: dict) -> dict:
    return {"type": "FeatureCollection", "features": list(features)}


def tap(fid: int, lng: float, lat: float) -> dict:
    return {
        "type": "Feature",
        "properties": {"id": fid, "naam": f"Tap {fid}"},
        "geometry": {"type": "Point", "coordinates": [lng, lat]},
    }


@pytest.fixture
def stubbed(monkeypatch):
    monkeypatch.setattr(run, "load_provider", lambda dsn, key: dict(PROVIDER))
    served = {"doc": reply(tap(1, 4.9, 52.37), tap(2, 5.1, 52.09))}
    monkeypatch.setattr(run, "fetch", lambda url: served["doc"])
    return served


def test_standard_output_carries_the_file_and_nothing_else(stubbed, capsysbinary):
    assert run.main(["--key", "test-taps", "--out", "-", "--dsn", "postgresql://stub"]) == 0

    out, err = capsysbinary.readouterr()
    doc = json.loads(out.decode("utf-8"))
    assert doc["type"] == "FeatureCollection"
    assert [f["properties"]["name"] for f in doc["features"]] == ["Tap 1", "Tap 2"]
    assert b"2 features, 0 refused -> standard output" in err


def test_a_file_path_still_writes_a_file(stubbed, tmp_path, capsys):
    target = tmp_path / "taps.json"
    assert run.main(["--key", "test-taps", "--out", str(target), "--dsn", "postgresql://stub"]) == 0

    assert len(json.loads(target.read_text(encoding="utf-8"))["features"]) == 2
    out, err = capsys.readouterr()
    assert out == ""
    assert str(target) in err


def test_an_empty_reply_writes_nothing_to_standard_output(stubbed, capsysbinary):
    """An empty result is a fetch that failed quietly; the ingest must get nothing."""
    stubbed["doc"] = reply()

    with pytest.raises(SystemExit, match="empty harvest"):
        run.main(["--key", "test-taps", "--out", "-", "--dsn", "postgresql://stub"])

    out, _ = capsysbinary.readouterr()
    assert out == b""
