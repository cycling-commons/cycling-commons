# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Publishing the three surface artifacts as ONE versioned build."""

from __future__ import annotations

import datetime
import io
import json

import pytest

from coverage.publish import SURFACE_MANIFEST_KEY, prune_surface, upload_surface


class FakeS3:
    """Enough of boto3's S3 client to assert what a publish actually does."""

    def __init__(self, existing=()):
        self.objects = {k: b"" for k in existing}
        self.puts = []
        self.deleted = []

    def put_object(self, **kw):
        body = kw["Body"]
        self.objects[kw["Key"]] = body.read() if hasattr(body, "read") else body
        self.puts.append(kw)

    def get_object(self, **kw):
        key = kw["Key"]
        if key not in self.objects:
            raise KeyError(key)          # boto raises NoSuchKey; any raise is "absent"
        return {"Body": io.BytesIO(self.objects[key])}

    def list_objects_v2(self, **kw):
        prefix = kw.get("Prefix", "")
        return {"Contents": [{"Key": k} for k in sorted(self.objects) if k.startswith(prefix)],
                "IsTruncated": False}

    def delete_object(self, **kw):
        self.deleted.append(kw["Key"])
        self.objects.pop(kw["Key"], None)


@pytest.fixture
def env(monkeypatch):
    monkeypatch.setenv("COVERAGE_S3_BUCKET", "cc-maps")
    monkeypatch.setenv("COVERAGE_PUBLIC_BASE_URL", "https://tiles.example/cc-maps")


NOW = datetime.datetime(2026, 8, 12, 20, 15, tzinfo=datetime.timezone.utc)


def _artifacts(tmp_path):
    paths = {}
    for arm in ("classified", "todo", "gaps"):
        p = tmp_path / f"{arm}.pmtiles"
        p.write_bytes(arm.encode())
        paths[arm] = p
    return paths


def test_all_three_arms_share_one_stamp(tmp_path, env):
    # The point of publishing them as a set: a map showing one build's
    # classified skin against another's to-do arm would tell riders that roads
    # they have just recorded are still missing.
    s3 = FakeS3()
    urls = upload_surface(_artifacts(tmp_path), {"counts": {}, "country_codes": ["BE"]},
                          client=s3, now=NOW)
    assert urls == {
        "classified": "https://tiles.example/cc-maps/surface/20260812-2015/classified.pmtiles",
        "todo": "https://tiles.example/cc-maps/surface/20260812-2015/todo.pmtiles",
        "gaps": "https://tiles.example/cc-maps/surface/20260812-2015/gaps.pmtiles",
    }


def test_the_manifest_points_at_that_build(tmp_path, env):
    s3 = FakeS3()
    upload_surface(_artifacts(tmp_path),
                   {"counts": {"classified": 7, "todo": 3, "cells": 2}, "country_codes": ["BE", "NL"]},
                   client=s3, now=NOW)
    doc = json.loads(s3.objects[SURFACE_MANIFEST_KEY])
    assert doc["stamp"] == "20260812-2015"
    assert doc["country_codes"] == ["BE", "NL"]
    assert doc["counts"]["classified"] == 7
    assert set(doc["tiles"]) == {"classified", "todo", "gaps"}


def test_artifacts_are_immutable_and_the_manifest_is_not(tmp_path, env):
    # A rebuild must never change bytes under an open reader, so the artifact
    # keys are versioned and cached forever; the manifest is the one mutable
    # thing and carries a short TTL so a new build goes live without a deploy.
    s3 = FakeS3()
    upload_surface(_artifacts(tmp_path), {}, client=s3, now=NOW)
    by_key = {p["Key"]: p["CacheControl"] for p in s3.puts}
    for arm in ("classified", "todo", "gaps"):
        assert "immutable" in by_key[f"surface/20260812-2015/{arm}.pmtiles"]
    assert by_key[SURFACE_MANIFEST_KEY] == "public, max-age=300"


def test_prune_keeps_whole_builds_and_never_the_manifest(env):
    stamps = ["20260810-1000", "20260811-1000", "20260812-1000", "20260812-2015"]
    existing = [f"surface/{s}/{arm}.pmtiles" for s in stamps
                for arm in ("classified", "todo", "gaps")] + [SURFACE_MANIFEST_KEY]
    s3 = FakeS3(existing)
    stale = prune_surface(keep=3, client=s3)
    # The oldest build goes as a unit — never one arm of it, which would leave a
    # manifest pointing at a build that is missing a layer.
    assert sorted(stale) == sorted(f"surface/20260810-1000/{arm}.pmtiles"
                                   for arm in ("classified", "todo", "gaps"))
    assert SURFACE_MANIFEST_KEY in s3.objects


def test_prune_ignores_keys_that_are_not_builds(env):
    # The hand-uploaded generations from before this existed live at
    # surface/<name>.pmtiles. Pruning must not touch what it does not understand.
    s3 = FakeS3(["surface/benllu-20260812-1000.pmtiles", "surface/20260812-0000.pmtiles",
                 SURFACE_MANIFEST_KEY])
    assert prune_surface(keep=1, client=s3) == []
    assert len(s3.objects) == 3


def test_a_narrow_build_does_not_silently_replace_a_wide_one(tmp_path, env, monkeypatch, capsys):
    """The foot-gun this guard exists for, observed on the first real publish.

    Tiling builds from exactly the regions it was given, so a one-country run
    produces a one-country archive. Publishing it took eleven countries off the
    map with a zero exit and nothing in the log.
    """
    import json as _json

    from coverage import run as run_mod
    from coverage.publish import SURFACE_MANIFEST_KEY

    s3 = FakeS3()
    s3.objects[SURFACE_MANIFEST_KEY] = _json.dumps(
        {"country_codes": ["BE", "DE", "FR", "LU"]}).encode()

    from coverage.publish import published_countries
    assert published_countries(client=s3) == {"BE", "DE", "FR", "LU"}


def test_a_first_publish_is_not_a_shrink(env):
    # No manifest yet means "no opinion", not "zero countries" — otherwise the
    # very first publish would refuse itself.
    from coverage.publish import published_countries

    assert published_countries(client=FakeS3()) is None
