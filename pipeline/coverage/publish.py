# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Publish the coverage PMTiles artifact + manifest to the CC bucket.

Versioned keys (coverage/<YYYYMMDD-HHMM>.pmtiles) so an open reader mid-pan
never has bytes change underneath it (coverage-provider.md §3 step 8);
the stable manifest key points Symfony's CoverageManifest at the current
artifact. Dev talks to the compose MinIO, prod to the CC Hetzner bucket —
same code, COVERAGE_S3_* env only.
"""
import datetime
import json
import os
import re

import boto3
from botocore.config import Config
from botocore.exceptions import ClientError

MANIFEST_KEY = "coverage/manifest.json"
_VERSIONED_KEY = re.compile(r"^coverage/\d{8}-\d{4}\.pmtiles$")

# Prod-sized artifact uploads over object storage: retry transient S3/network
# errors instead of failing the whole weekly batch, and never hang a timer run
# on a dead connection.
_BOTO_CONFIG = Config(
    retries={"max_attempts": 5, "mode": "standard"},
    connect_timeout=10,
    read_timeout=120,
)


def _client():
    return boto3.client(
        "s3",
        endpoint_url=os.environ["COVERAGE_S3_ENDPOINT"],
        aws_access_key_id=os.environ["COVERAGE_S3_KEY"],
        aws_secret_access_key=os.environ["COVERAGE_S3_SECRET"],
        # signing region only; MinIO ignores it, Hetzner accepts a matching one
        region_name=os.environ.get("COVERAGE_S3_REGION", "us-east-1"),
        config=_BOTO_CONFIG,
    )


def _bucket():
    return os.environ.get("COVERAGE_S3_BUCKET", "cc-maps")


def ensure_bucket(client=None):
    """Dev bootstrap: create the bucket (anonymous-read) when it does not exist.

    Prod buckets pre-exist, so head_bucket short-circuits and the batch
    credentials never need create/policy rights there."""
    client = client or _client()
    bucket = _bucket()
    try:
        client.head_bucket(Bucket=bucket)
        return
    except ClientError:
        pass
    client.create_bucket(Bucket=bucket)
    client.put_bucket_policy(Bucket=bucket, Policy=json.dumps({
        "Version": "2012-10-17",
        "Statement": [{
            "Effect": "Allow",
            "Principal": {"AWS": ["*"]},
            "Action": ["s3:GetObject"],
            "Resource": [f"arn:aws:s3:::{bucket}/*"],
        }],
    }))


def upload(pmtiles_path, manifest, client=None, now=None):
    """Put the artifact under a versioned key, then repoint the stable manifest.

    `manifest` carries counts + regions; version/url/built_at are stamped here.
    Returns the public artifact URL (what CoverageManifest::currentTileUrl serves)."""
    client = client or _client()
    bucket = _bucket()
    now = now or datetime.datetime.now(datetime.timezone.utc)
    key = f"coverage/{now.strftime('%Y%m%d-%H%M')}.pmtiles"
    with open(pmtiles_path, "rb") as fh:
        client.put_object(
            Bucket=bucket, Key=key, Body=fh,
            ContentType="application/octet-stream",
            # versioned key → immutable forever; open readers keep valid bytes
            CacheControl="public, max-age=31536000, immutable")
    url = f"{os.environ['COVERAGE_PUBLIC_BASE_URL'].rstrip('/')}/{key}"
    doc = {"version": 1, "url": url,
           "built_at": now.isoformat(timespec="seconds"),
           "counts": manifest["counts"], "regions": manifest["regions"]}
    client.put_object(
        Bucket=bucket, Key=MANIFEST_KEY,
        Body=json.dumps(doc, separators=(",", ":")).encode(),
        ContentType="application/json",
        # short TTL so a new artifact goes live within the hour without a
        # deploy — coverage-provider.md §4 documents Symfony's own
        # CoverageManifest::CACHE_TTL (3600 s) layered on top of this
        CacheControl="public, max-age=300")
    return url


def prune(keep=4, client=None):
    """Delete versioned artifacts beyond the newest `keep` (coverage-provider.md §3 step 8).

    The manifest and any non-versioned keys are never touched. Returns the
    deleted keys, oldest last."""
    client = client or _client()
    bucket = _bucket()
    resp = client.list_objects_v2(Bucket=bucket, Prefix="coverage/")
    keys = sorted((o["Key"] for o in resp.get("Contents", [])
                   if _VERSIONED_KEY.match(o["Key"])), reverse=True)
    stale = keys[keep:]
    for key in stale:
        client.delete_object(Bucket=bucket, Key=key)
    return stale
