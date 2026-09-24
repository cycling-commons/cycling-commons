# SPDX-License-Identifier: AGPL-3.0-only
"""publish.py: dev bucket bootstrap (upload/manifest/prune are covered by
test_publish_countries.py, the per-country v2 API)."""
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


def test_ensure_bucket_reraises_forbidden_instead_of_masking_with_create(env):
    # A 403 (bad/expired creds, or a bucket the batch credential can't even
    # HEAD) must surface as itself — not be swallowed and then fail
    # confusingly on create_bucket, which a create-less prod credential can't
    # do either.
    client, stub = _stubbed_client()
    stub.add_client_error("head_bucket", service_error_code="403", http_status_code=403,
                          expected_params={"Bucket": "cc-maps"})
    with stub:
        with pytest.raises(publish.ClientError) as exc_info:
            publish.ensure_bucket(client=client)
    assert exc_info.value.response["Error"]["Code"] == "403"
    stub.assert_no_pending_responses()   # create_bucket must never be called
