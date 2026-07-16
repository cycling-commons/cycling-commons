# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""publish.py — versioned artifact upload, manifest repoint, prune, dev bucket bootstrap."""
import datetime

import boto3
import pytest
from botocore.stub import ANY, Stubber

from coverage import publish


@pytest.fixture()
def env(monkeypatch):
    monkeypatch.setenv("COVERAGE_S3_ENDPOINT", "http://minio:9000")
    monkeypatch.setenv("COVERAGE_S3_BUCKET", "cc-maps")
    monkeypatch.setenv("COVERAGE_S3_KEY", "k")
    monkeypatch.setenv("COVERAGE_S3_SECRET", "s")
    monkeypatch.setenv("COVERAGE_PUBLIC_BASE_URL", "http://localhost:9100/cc-maps")


def _stubbed_client():
    client = boto3.client("s3", endpoint_url="http://minio:9000",
                          aws_access_key_id="k", aws_secret_access_key="s",
                          region_name="us-east-1")
    return client, Stubber(client)


def test_upload_versioned_artifact_and_manifest(env, tmp_path):
    art = tmp_path / "coverage.pmtiles"
    art.write_bytes(b"PMTiles-bytes")
    client, stub = _stubbed_client()
    now = datetime.datetime(2026, 7, 16, 4, 30, tzinfo=datetime.timezone.utc)
    stub.add_response("put_object", {}, {
        "Bucket": "cc-maps", "Key": "coverage/20260716-0430.pmtiles", "Body": ANY,
        "ContentType": "application/octet-stream",
        "CacheControl": "public, max-age=31536000, immutable"})
    stub.add_response("put_object", {}, {
        "Bucket": "cc-maps", "Key": "coverage/manifest.json", "Body": ANY,
        "ContentType": "application/json",
        "CacheControl": "public, max-age=300"})
    with stub:
        url = publish.upload(art, {"counts": {"C": 2, "D": 1}, "regions": ["europe/belgium"]},
                             client=client, now=now)
    assert url == "http://localhost:9100/cc-maps/coverage/20260716-0430.pmtiles"
    stub.assert_no_pending_responses()


def test_prune_keeps_newest_four_and_manifest(env):
    client, stub = _stubbed_client()
    contents = [{"Key": k} for k in (
        "coverage/manifest.json",
        "coverage/20260601-0400.pmtiles", "coverage/20260608-0400.pmtiles",
        "coverage/20260615-0400.pmtiles", "coverage/20260622-0400.pmtiles",
        "coverage/20260629-0400.pmtiles", "coverage/20260706-0400.pmtiles")]
    stub.add_response("list_objects_v2", {"Contents": contents, "KeyCount": 7, "IsTruncated": False},
                      {"Bucket": "cc-maps", "Prefix": "coverage/"})
    stub.add_response("delete_object", {}, {"Bucket": "cc-maps", "Key": "coverage/20260608-0400.pmtiles"})
    stub.add_response("delete_object", {}, {"Bucket": "cc-maps", "Key": "coverage/20260601-0400.pmtiles"})
    with stub:
        stale = publish.prune(keep=4, client=client)
    assert stale == ["coverage/20260608-0400.pmtiles", "coverage/20260601-0400.pmtiles"]
    stub.assert_no_pending_responses()


def test_ensure_bucket_bootstraps_missing_bucket(env):
    client, stub = _stubbed_client()
    stub.add_client_error("head_bucket", service_error_code="404", http_status_code=404,
                          expected_params={"Bucket": "cc-maps"})
    stub.add_response("create_bucket", {}, {"Bucket": "cc-maps"})
    stub.add_response("put_bucket_policy", {}, {"Bucket": "cc-maps", "Policy": ANY})
    with stub:
        publish.ensure_bucket(client=client)
    stub.assert_no_pending_responses()


def test_ensure_bucket_noop_when_present(env):
    client, stub = _stubbed_client()
    stub.add_response("head_bucket", {}, {"Bucket": "cc-maps"})
    with stub:
        publish.ensure_bucket(client=client)
    stub.assert_no_pending_responses()
