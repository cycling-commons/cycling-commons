# SPDX-License-Identifier: AGPL-3.0-only
"""Per-country publishing: replace what was built, keep everything else."""

from __future__ import annotations

import contextlib
import datetime
import json

import pytest

from botocore.exceptions import ClientError

from coverage.publish import (CountryBuild, GapsBuild, inputs_fingerprint, prune_family,
                              publish_countries, read_live_manifest, read_manifest)
from fakes import FakeS3

NOW = datetime.datetime(2026, 9, 24, 3, 12, 5, tzinfo=datetime.timezone.utc)


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
    assert read_live_manifest("surface", client=FakeS3()) == ({"version": 2, "countries": {}}, False)
    s3 = FakeS3()
    s3.objects["surface/manifest.json"] = json.dumps({"version": 1, "tiles": {}}).encode()
    assert read_manifest("surface", client=s3) == {"version": 2, "countries": {}}
    assert read_live_manifest("surface", client=s3)[1] is False
    s3.objects["surface/manifest.json"] = json.dumps({"tiles": {}}).encode()
    assert read_live_manifest("surface", client=s3) == ({"version": 2, "countries": {}}, False)
    s3.objects["surface/manifest.json"] = json.dumps({"version": 2, "countries": {"be": {}}}).encode()
    assert read_live_manifest("surface", client=s3) == ({"version": 2, "countries": {"be": {}}}, True)


class _FailingS3(FakeS3):
    """An S3 that answers the manifest read with a server error."""

    def get_object(self, **kw):
        raise ClientError({"Error": {"Code": "InternalError", "Message": "boom"},
                           "ResponseMetadata": {"HTTPStatusCode": 500}}, "GetObject")


def test_a_failed_manifest_read_raises_instead_of_reading_empty():
    with pytest.raises(ClientError):
        read_manifest("surface", client=_FailingS3())


@pytest.mark.parametrize("body", [b"not json", b'{"version": 2, "countries": []}', b"[]"])
def test_a_malformed_v2_manifest_raises(body):
    s3 = FakeS3()
    s3.objects["surface/manifest.json"] = body
    with pytest.raises(ValueError):
        read_manifest("surface", client=s3)


def test_publish_does_not_write_the_manifest_when_the_read_fails(tmp_path):
    s3 = _FailingS3()
    with pytest.raises(ClientError):
        publish_countries("surface", {"be": _build(tmp_path, "be")}, client=s3, now=NOW,
                          lock=lambda f: contextlib.nullcontext())
    assert "surface/manifest.json" not in s3.objects


def test_a_country_both_built_and_retired_is_refused(tmp_path):
    s3 = FakeS3()
    with pytest.raises(ValueError):
        publish_countries("surface", {"BE": _build(tmp_path, "be")}, retire=["be"], client=s3, now=NOW,
                          lock=lambda f: contextlib.nullcontext())
    assert s3.puts == []


def test_publish_keeps_other_countries_and_takes_the_lock(tmp_path):
    s3 = FakeS3()
    s3.objects["surface/manifest.json"] = json.dumps(
        {"version": 2, "countries": {"nl": {"stamp": "20260920-0300", "inputs": "old", "tiles": {"classified": "u"}}}}).encode()
    taken = []

    @contextlib.contextmanager
    def lock(family):
        # A publish that finished while this one waited for the lock: the
        # manifest must be read after the lock is taken, or FR is lost.
        taken.append(family)
        doc = json.loads(s3.objects["surface/manifest.json"])
        doc["countries"]["fr"] = {"stamp": "20260924-031000", "inputs": "fr", "tiles": {}}
        s3.objects["surface/manifest.json"] = json.dumps(doc).encode()
        yield

    doc = publish_countries("surface", {"be": _build(tmp_path, "be")}, client=s3, now=NOW, lock=lock)
    assert taken == ["surface"]
    assert set(doc["countries"]) == {"be", "fr", "nl"}
    assert doc["countries"]["nl"]["stamp"] == "20260920-0300"
    be = doc["countries"]["be"]
    assert be["stamp"] == "20260924-031205"
    assert be["tiles"]["classified"] == "https://tiles.example/cc-maps/surface/be/20260924-031205/classified.pmtiles"
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
    assert doc["gaps"]["url"].endswith("/surface/gaps/20260924-031205/gaps.pmtiles")
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


def test_prune_reads_minute_and_second_stamps_alike():
    # Builds published before second-resolution stamps sort among the new ones.
    keys = ["surface/be/20260920-0300/classified.pmtiles",
            "surface/be/20260924-031200/classified.pmtiles",
            "surface/be/20260924-031205/classified.pmtiles",
            "surface/be/20260924-0313/classified.pmtiles"]
    s3 = FakeS3(existing=keys)
    gone = prune_family("surface", {"countries": {"be": {"stamp": "20260924-031205"}}}, keep=2, client=s3)
    assert sorted(gone) == ["surface/be/20260920-0300/classified.pmtiles",
                            "surface/be/20260924-031200/classified.pmtiles"]
