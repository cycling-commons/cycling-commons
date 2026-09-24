# SPDX-License-Identifier: AGPL-3.0-only
"""Publish per-country PMTiles artifacts + manifest to the CC bucket, one
family (coverage points, road surface, cycle routes) at a time.

Each built country uploads under its own versioned prefix
(<family>/<cc>/<YYYYMMDD-HHMMSS>/<arm>.pmtiles) so an open reader mid-pan never
has bytes change underneath it (coverage-provider.md §3 step 8); the stable
per-family manifest key (FAMILIES) points the Symfony *Manifest readers at
each country's current tiles. Dev talks to the compose MinIO, prod to the CC
Hetzner bucket: same code, COVERAGE_S3_* env only.
"""
import contextlib
import datetime
import hashlib
import json
import os
import re
from collections.abc import Iterable
from dataclasses import dataclass
from pathlib import Path

import boto3
import psycopg
from botocore.config import Config
from botocore.exceptions import ClientError

MANIFEST_KEY = "coverage/manifest.json"

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
    credentials never need create/policy rights there. Only a confirmed
    "bucket does not exist" response falls through to create_bucket — an
    auth/permission error (403 Forbidden, wrong/expired credentials) must
    surface as itself instead of being swallowed here and then failing
    confusingly on create_bucket (a create-less credential can't do that
    either, and the resulting error hides the real cause)."""
    client = client or _client()
    bucket = _bucket()
    try:
        client.head_bucket(Bucket=bucket)
        return
    except ClientError as e:
        code = e.response.get("Error", {}).get("Code", "")
        if code not in ("404", "NoSuchBucket"):
            raise
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


FAMILIES = {"coverage": MANIFEST_KEY, "surface": "surface/manifest.json", "routes": "routes/manifest.json"}
# <family>/<cc or gaps>/<stamp>/<arm>.pmtiles: one folder per country build,
# so pruning is per country and never touches a neighbour's files.
# A stamp is YYYYMMDD-HHMMSS; builds published with minute stamps (YYYYMMDD-HHMM)
# still match, and the two forms sort together by time.
_COUNTRY_KEY = re.compile(r"^(coverage|surface|routes)/([a-z]{2}|gaps)/(\d{8}-\d{4}(?:\d{2})?)/[a-z]+\.pmtiles$")
# One advisory-lock key per manifest, distinct from the run lock
# (run.COVERAGE_ADVISORY_LOCK_KEY), which the coverage run holds on its own
# connection while it publishes.
_MANIFEST_LOCK_KEYS = {"coverage": 0xC07E7A70, "surface": 0xC07E7A71, "routes": 0xC07E7A72}


@dataclass(frozen=True)
class CountryBuild:
    """One country's freshly built archives and what they were built from."""

    paths: dict[str, Path]      # arm -> local .pmtiles
    inputs: str                 # inputs_fingerprint of everything the build read
    bounds: list[float]
    counts: dict


@dataclass(frozen=True)
class GapsBuild:
    path: Path
    inputs: str


def inputs_fingerprint(files: Iterable[Path], *extra: str) -> str:
    """Content hash of a build's inputs plus any extra strings (contract, profile).

    Content, not names or mtime: files are sorted by path only so the hash is
    order-independent, but a workdir file's name never enters the digest - the
    coverage export rewrites every file every night under fresh names, and a
    wiped workdir must not look like new data.
    """
    h = hashlib.sha256()
    for path in sorted(Path(p) for p in files):
        with open(path, "rb") as fh:
            for chunk in iter(lambda: fh.read(1 << 20), b""):
                h.update(chunk)
        h.update(b"\0")
    for e in extra:
        h.update(e.encode() + b"\0")
    return h.hexdigest()[:16]


def _empty_manifest() -> dict:
    return {"version": 2, "countries": {}}


def read_live_manifest(family: str, client=None) -> tuple[dict, bool]:
    """The live manifest as (v2 document, whether a v2 document is live).

    Only "no manifest yet" (NoSuchKey / 404) and a v1 or version-less
    document read as the empty v2 skeleton, with False: the first v2 publish
    then rebuilds every country it is given. Every other failure raises, so a
    transient S3 error or a malformed body can never make a merge drop the
    countries it did not rebuild; callers keep the last manifest serving.
    """
    client = client or _client()
    try:
        body = client.get_object(Bucket=_bucket(), Key=FAMILIES[family])["Body"].read()
    except ClientError as e:
        code = e.response.get("Error", {}).get("Code", "")
        if code in ("NoSuchKey", "404"):
            return _empty_manifest(), False
        raise
    doc = json.loads(body)          # json.JSONDecodeError is a ValueError
    if not isinstance(doc, dict):
        raise ValueError(f"{FAMILIES[family]}: not a JSON object")
    if doc.get("version") != 2:
        return _empty_manifest(), False
    if not isinstance(doc.get("countries"), dict):
        raise ValueError(f"{FAMILIES[family]}: v2 manifest without a countries object")
    return doc, True


def read_manifest(family: str, client=None) -> dict:
    """The live v2 manifest (see read_live_manifest)."""
    return read_live_manifest(family, client)[0]


@contextlib.contextmanager
def manifest_lock(family: str):
    """Serialise read-modify-write of one manifest across processes."""
    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    with psycopg.connect(dsn, autocommit=True) as conn:
        conn.execute("SET lock_timeout = '120s'")
        conn.execute("SELECT pg_advisory_lock(%s)", (_MANIFEST_LOCK_KEYS[family],))
        try:
            yield
        finally:
            conn.execute("SELECT pg_advisory_unlock(%s)", (_MANIFEST_LOCK_KEYS[family],))


def publish_countries(family, built, *, gaps=None, retire=(), client=None, now=None, lock=None) -> dict:
    """Upload each built country under its own versioned prefix, then merge the
    entries into the live manifest. Countries not in `built` keep their entry;
    only `retire` removes one. Returns the manifest as written."""
    both = {cc.lower() for cc in built} & {cc.lower() for cc in retire}
    if both:
        raise ValueError(f"{family}: {', '.join(sorted(both))} both built and retired")
    client = client or _client()
    bucket = _bucket()
    now = now or datetime.datetime.now(datetime.timezone.utc)
    stamp = now.strftime("%Y%m%d-%H%M%S")
    built_at = now.isoformat(timespec="seconds")
    base = os.environ["COVERAGE_PUBLIC_BASE_URL"].rstrip("/")

    def put(key, path):
        with open(path, "rb") as fh:
            client.put_object(Bucket=bucket, Key=key, Body=fh, ContentType="application/octet-stream",
                              # versioned key: a rider mid-session holds offsets into these bytes
                              CacheControl="public, max-age=31536000, immutable")
        return f"{base}/{key}"

    entries = {}
    for cc, b in built.items():
        cc = cc.lower()
        tiles = {arm: put(f"{family}/{cc}/{stamp}/{arm}.pmtiles", path) for arm, path in sorted(b.paths.items())}
        entries[cc] = {"stamp": stamp, "built_at": built_at, "inputs": b.inputs,
                       "bounds": b.bounds, "counts": b.counts, "tiles": tiles}
    gaps_entry = None
    if gaps is not None:
        gaps_entry = {"stamp": stamp, "built_at": built_at, "inputs": gaps.inputs,
                      "url": put(f"{family}/gaps/{stamp}/gaps.pmtiles", gaps.path)}

    with (lock or manifest_lock)(family):
        doc = read_manifest(family, client)
        doc["countries"].update(entries)
        for cc in retire:
            doc["countries"].pop(cc.lower(), None)
        if gaps_entry is not None:
            doc["gaps"] = gaps_entry
        doc["updated_at"] = built_at
        client.put_object(Bucket=bucket, Key=FAMILIES[family],
                          Body=json.dumps(doc, separators=(",", ":")).encode(),
                          ContentType="application/json",
                          # short TTL: a rebuild goes live within the hour, no deploy
                          CacheControl="public, max-age=300")
    return doc


def prune_family(family: str, manifest: dict, keep: int = 3, client=None) -> list[str]:
    """Delete builds beyond the newest `keep` per country, never a live stamp."""
    client = client or _client()
    bucket = _bucket()
    by_cc: dict[str, dict[str, list[str]]] = {}
    token = None
    while True:
        kw = {"Bucket": bucket, "Prefix": f"{family}/"}
        if token:
            kw["ContinuationToken"] = token
        resp = client.list_objects_v2(**kw)
        for o in resp.get("Contents", []):
            m = _COUNTRY_KEY.match(o["Key"])
            if m:
                by_cc.setdefault(m.group(2), {}).setdefault(m.group(3), []).append(o["Key"])
        if not resp.get("IsTruncated"):
            break
        token = resp.get("NextContinuationToken")
    live = {cc: e.get("stamp") for cc, e in manifest.get("countries", {}).items()}
    if manifest.get("gaps"):
        live["gaps"] = manifest["gaps"].get("stamp")
    stale = []
    for cc, stamps in by_cc.items():
        keepers = set(sorted(stamps, reverse=True)[:keep]) | {live.get(cc)}
        stale += [k for s, ks in stamps.items() if s not in keepers for k in ks]
    for key in sorted(stale, reverse=True):
        client.delete_object(Bucket=bucket, Key=key)
    return stale
