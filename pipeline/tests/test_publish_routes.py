# SPDX-License-Identifier: AGPL-3.0-only
"""Publishing the cycle-route network artifact under its own manifest.

Its OWN manifest on purpose: `--routes` and `--surface` are separate
invocations, and a shared manifest would let whichever ran last publish
half-updated URLs for the other's arms. The route-awareness coupling lives in
the surface build's INPUTS (the way-id files), never in serving."""

from __future__ import annotations

import datetime
import json

import pytest

from coverage.publish import (ROUTES_MANIFEST_KEY, SURFACE_MANIFEST_KEY,
                              prune_routes, published_countries, upload_routes)
from fakes import FakeS3

NOW = datetime.datetime(2026, 8, 13, 1, 30, tzinfo=datetime.timezone.utc)


@pytest.fixture
def env(monkeypatch):
    monkeypatch.setenv("COVERAGE_S3_BUCKET", "cc-maps")
    monkeypatch.setenv("COVERAGE_PUBLIC_BASE_URL", "https://tiles.example/cc-maps")


def _artifact(tmp_path):
    p = tmp_path / "routes.pmtiles"
    p.write_bytes(b"routes")
    return p


def test_the_artifact_lands_versioned_and_the_manifest_repoints(tmp_path, env):
    s3 = FakeS3()
    url = upload_routes(_artifact(tmp_path),
                        {"counts": {"ways": 9, "nodes": 4}, "country_codes": ["NL"]},
                        client=s3, now=NOW)
    assert url == "https://tiles.example/cc-maps/routes/20260813-0130/routes.pmtiles"
    doc = json.loads(s3.objects[ROUTES_MANIFEST_KEY])
    assert doc["tiles"] == {"routes": url}
    assert doc["stamp"] == "20260813-0130"
    assert doc["counts"] == {"ways": 9, "nodes": 4}
    assert doc["country_codes"] == ["NL"]
    # Immutable artifact, short-TTL manifest — the same serving contract as
    # every other layer, so a rebuild goes live without a deploy.
    by_key = {p["Key"]: p for p in s3.puts}
    assert "immutable" in by_key[url.split("/cc-maps/")[1]]["CacheControl"]
    assert by_key[ROUTES_MANIFEST_KEY]["CacheControl"] == "public, max-age=300"


def test_prune_keeps_the_newest_three_builds(tmp_path, env):
    s3 = FakeS3(existing=[
        f"routes/2026080{i}-0100/routes.pmtiles" for i in range(1, 6)
    ] + [ROUTES_MANIFEST_KEY, "routes/notes.txt"])
    stale = prune_routes(client=s3)
    assert stale == ["routes/20260802-0100/routes.pmtiles",
                     "routes/20260801-0100/routes.pmtiles"]
    # The manifest and any non-versioned key are never touched.
    assert ROUTES_MANIFEST_KEY in s3.objects and "routes/notes.txt" in s3.objects


def test_published_countries_reads_the_named_manifest(env):
    # The shrink guard must ask the ROUTES manifest, not the surface one: the
    # two builds legitimately cover different country sets while routes roll
    # out, and the default key would veto a correct first publish.
    s3 = FakeS3()
    s3.objects[SURFACE_MANIFEST_KEY] = json.dumps(
        {"country_codes": ["BE", "NL", "DE"]}).encode()
    s3.objects[ROUTES_MANIFEST_KEY] = json.dumps({"country_codes": ["NL"]}).encode()
    assert published_countries(client=s3, manifest_key=ROUTES_MANIFEST_KEY) == {"NL"}
    assert published_countries(client=s3) == {"BE", "NL", "DE"}


def test_no_routes_manifest_means_no_opinion(env):
    assert published_countries(client=FakeS3(), manifest_key=ROUTES_MANIFEST_KEY) is None
