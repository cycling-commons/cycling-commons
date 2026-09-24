# SPDX-License-Identifier: AGPL-3.0-only
"""Per-country publishing: replace what was built, keep everything else."""

from __future__ import annotations

import contextlib
import datetime
import json

import pytest

from coverage.publish import (CountryBuild, GapsBuild, inputs_fingerprint, prune_family,
                              publish_countries, read_manifest)
from fakes import FakeS3

NOW = datetime.datetime(2026, 9, 24, 3, 12, tzinfo=datetime.timezone.utc)


@pytest.fixture(autouse=True)
def _env(monkeypatch):
    monkeypatch.setenv("COVERAGE_PUBLIC_BASE_URL", "https://tiles.example/cc-maps")


def _build(tmp_path, cc, arms=("classified", "todo")):
    paths = {}
    for arm in arms:
        p = tmp_path / f"{cc}-{arm}.pmtiles"
        p.write_bytes(b"pm")
        paths[arm] = p
    return CountryBuild(paths=paths, inputs="in-" + cc, bounds=[1.0, 2.0, 3.0, 4.0], counts={"classified": 5})


def _manifest(s3, key="surface/manifest.json"):
    return json.loads(s3.objects[key])


def test_fingerprint_follows_content_not_names(tmp_path):
    a, b = tmp_path / "a", tmp_path / "b"
    a.write_text("x")
    b.write_text("x")
    assert inputs_fingerprint([a], "c1") == inputs_fingerprint([b], "c1")
    assert inputs_fingerprint([a], "c1") != inputs_fingerprint([a], "c2")
    b.write_text("y")
    assert inputs_fingerprint([a], "c1") != inputs_fingerprint([b], "c1")


def test_missing_or_v1_manifest_reads_as_empty_v2():
    assert read_manifest("surface", client=FakeS3()) == {"version": 2, "countries": {}}
    s3 = FakeS3()
    s3.objects["surface/manifest.json"] = json.dumps({"version": 1, "tiles": {}}).encode()
    assert read_manifest("surface", client=s3) == {"version": 2, "countries": {}}


def test_publish_keeps_other_countries_and_takes_the_lock(tmp_path):
    s3 = FakeS3()
    s3.objects["surface/manifest.json"] = json.dumps(
        {"version": 2, "countries": {"nl": {"stamp": "20260920-0300", "inputs": "old", "tiles": {"classified": "u"}}}}).encode()
    taken = []

    @contextlib.contextmanager
    def lock(family):
        taken.append(family)
        yield

    doc = publish_countries("surface", {"be": _build(tmp_path, "be")}, client=s3, now=NOW, lock=lock)
    assert taken == ["surface"]
    assert set(doc["countries"]) == {"be", "nl"}
    assert doc["countries"]["nl"]["stamp"] == "20260920-0300"
    be = doc["countries"]["be"]
    assert be["tiles"]["classified"] == "https://tiles.example/cc-maps/surface/be/20260924-0312/classified.pmtiles"
    assert be["inputs"] == "in-be" and be["bounds"] == [1.0, 2.0, 3.0, 4.0]
    assert _manifest(s3) == doc
    put = next(p for p in s3.puts if p["Key"].endswith("classified.pmtiles"))
    assert put["CacheControl"] == "public, max-age=31536000, immutable"


def test_gaps_and_retire(tmp_path):
    s3 = FakeS3()
    s3.objects["surface/manifest.json"] = json.dumps({"version": 2, "countries": {"xx": {"stamp": "s"}}}).encode()
    g = tmp_path / "gaps.pmtiles"
    g.write_bytes(b"pm")
    doc = publish_countries("surface", {}, gaps=GapsBuild(path=g, inputs="gin"), retire=["xx"],
                            client=s3, now=NOW, lock=lambda f: contextlib.nullcontext())
    assert "xx" not in doc["countries"]
    assert doc["gaps"]["url"].endswith("/surface/gaps/20260924-0312/gaps.pmtiles")
    assert doc["gaps"]["inputs"] == "gin"


def test_prune_keeps_newest_three_per_country_and_every_live_stamp():
    keys = [f"surface/be/2026092{d}-0300/classified.pmtiles" for d in range(1, 6)]
    keys += ["surface/nl/20260901-0300/classified.pmtiles", "surface/be/20260901-0300/todo.pmtiles"]
    s3 = FakeS3(existing=keys)
    manifest = {"countries": {"be": {"stamp": "20260925-0300"}, "nl": {"stamp": "20260901-0300"}}}
    gone = prune_family("surface", manifest, keep=3, client=s3)
    assert sorted(gone) == ["surface/be/20260901-0300/todo.pmtiles",
                            "surface/be/20260921-0300/classified.pmtiles",
                            "surface/be/20260922-0300/classified.pmtiles"]
