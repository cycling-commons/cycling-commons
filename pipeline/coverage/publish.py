# SPDX-License-Identifier: AGPL-3.0-only
"""Publish the coverage PMTiles artifact + manifest to the CC bucket.

Versioned keys (coverage/<YYYYMMDD-HHMM>.pmtiles) so an open reader mid-pan
never has bytes change underneath it (coverage-provider.md §3 step 8);
the stable manifest key points Symfony's CoverageManifest at the current
artifact. Dev talks to the compose MinIO, prod to the CC Hetzner bucket —
same code, COVERAGE_S3_* env only.
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
_COUNTRY_KEY = re.compile(r"^(coverage|surface|routes)/([a-z]{2}|gaps)/(\d{8}-\d{4})/[a-z]+\.pmtiles$")
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


def read_manifest(family: str, client=None) -> dict:
    """The live v2 manifest. Missing, unreadable or v1 reads as empty, so the
    first v2 publish rebuilds every country it is given."""
    client = client or _client()
    try:
        doc = json.loads(client.get_object(Bucket=_bucket(), Key=FAMILIES[family])["Body"].read())
    except Exception:  # noqa: BLE001 - absent, unreadable, malformed: all "nothing published yet"
        return {"version": 2, "countries": {}}
    if doc.get("version") != 2 or not isinstance(doc.get("countries"), dict):
        return {"version": 2, "countries": {}}
    return doc


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
    client = client or _client()
    bucket = _bucket()
    now = now or datetime.datetime.now(datetime.timezone.utc)
    stamp = now.strftime("%Y%m%d-%H%M")
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
           "counts": manifest["counts"], "regions": manifest["regions"],
           "country_codes": manifest.get("country_codes", [])}
    client.put_object(
        Bucket=bucket, Key=MANIFEST_KEY,
        Body=json.dumps(doc, separators=(",", ":")).encode(),
        ContentType="application/json",
        # short TTL so a new artifact goes live within the hour without a
        # deploy — coverage-provider.md §4 documents Symfony's own
        # CoverageManifest::CACHE_TTL (3600 s) layered on top of this
        CacheControl="public, max-age=300")
    return url


SURFACE_MANIFEST_KEY = "surface/manifest.json"
# surface/<stamp>/<arm>.pmtiles — one folder per build, so pruning a stale build
# is "delete this prefix" rather than three filename patterns kept in step.
_SURFACE_KEY = re.compile(r"^surface/(\d{8}-\d{4})/(classified|todo|gaps)\.pmtiles$")


def published_countries(client=None, manifest_key=None):
    """Country codes named by the manifest currently being served, or None.

    None means "no manifest, or it cannot be read" — a first publish, or a
    bucket that has never had one — and callers must treat that as "no opinion"
    rather than as an empty set, or the very first publish would look like a
    shrink to zero.

    `manifest_key` defaults to the surface manifest; the routes publish passes
    its own. Same guard, same semantics — a narrow build must never silently
    replace a wide one, whichever layer it carries.
    """
    client = client or _client()
    try:
        body = client.get_object(Bucket=_bucket(),
                                 Key=manifest_key or SURFACE_MANIFEST_KEY)["Body"].read()
        codes = json.loads(body).get("country_codes")
    except Exception:  # noqa: BLE001 — absent, unreadable, malformed: all "no opinion"
        return None
    return set(codes) if isinstance(codes, list) else None


def upload_surface(paths, manifest, client=None, now=None):
    """Put the three surface artifacts under one versioned prefix, then repoint
    the stable manifest at them.

    Three artifacts, ONE manifest and ONE stamp, deliberately: they are three
    readings of a single walk over the same ways, and a map showing the
    classified skin of one build against the to-do arm of another would be
    quietly telling riders that roads they just recorded are still missing.
    Publishing them as a set makes that impossible rather than unlikely.

    `paths` maps arm name -> local file; `manifest` carries counts + countries.
    Returns the public URL of each arm.
    """
    client = client or _client()
    bucket = _bucket()
    now = now or datetime.datetime.now(datetime.timezone.utc)
    stamp = now.strftime("%Y%m%d-%H%M")
    base = os.environ["COVERAGE_PUBLIC_BASE_URL"].rstrip("/")
    urls = {}
    for arm in ("classified", "todo", "gaps"):
        path = paths.get(arm)
        if path is None:
            continue
        key = f"surface/{stamp}/{arm}.pmtiles"
        with open(path, "rb") as fh:
            client.put_object(
                Bucket=bucket, Key=key, Body=fh,
                ContentType="application/octet-stream",
                # versioned prefix → immutable forever. A rebuild must never
                # overwrite a live artifact: a rider mid-session is holding an
                # offset into the PMTiles directory, and changing the bytes
                # under them reads as corruption, not as an update.
                CacheControl="public, max-age=31536000, immutable")
        urls[arm] = f"{base}/{key}"
    doc = {"version": 1, "stamp": stamp,
           "built_at": now.isoformat(timespec="seconds"),
           "tiles": urls,
           "counts": manifest.get("counts", {}),
           "country_codes": manifest.get("country_codes", [])}
    client.put_object(
        Bucket=bucket, Key=SURFACE_MANIFEST_KEY,
        Body=json.dumps(doc, separators=(",", ":")).encode(),
        ContentType="application/json",
        # Short TTL for the same reason the coverage manifest has one: a new
        # build goes live within the hour without a deploy or a config change.
        CacheControl="public, max-age=300")
    return urls


ROUTES_MANIFEST_KEY = "routes/manifest.json"
# routes/<stamp>/routes.pmtiles — same versioned-prefix scheme as the surface
# build, one artifact per build instead of three.
_ROUTES_KEY = re.compile(r"^routes/(\d{8}-\d{4})/routes\.pmtiles$")


def upload_routes(pmtiles_path, manifest, client=None, now=None):
    """Put the routes artifact under a versioned prefix, then repoint the
    stable routes manifest at it.

    Its OWN manifest, deliberately separate from the surface one: the two
    builds run as separate invocations (`--routes` vs `--surface`), and a
    shared manifest would let whichever ran last publish half-updated URLs for
    the other's arms. The layers do not need to move together the way the three
    surface arms do — a route corridor from last week beside this week's
    surface skin misleads nobody; the route-AWARENESS coupling lives in the
    surface build's inputs (the way-id set), not in serving.

    `manifest` carries counts + country codes; version/url/built_at are stamped
    here. Returns the public artifact URL.
    """
    client = client or _client()
    bucket = _bucket()
    now = now or datetime.datetime.now(datetime.timezone.utc)
    stamp = now.strftime("%Y%m%d-%H%M")
    key = f"routes/{stamp}/routes.pmtiles"
    with open(pmtiles_path, "rb") as fh:
        client.put_object(
            Bucket=bucket, Key=key, Body=fh,
            ContentType="application/octet-stream",
            # versioned prefix → immutable forever; open readers keep valid
            # bytes (see upload_surface for the mid-session rationale).
            CacheControl="public, max-age=31536000, immutable")
    url = f"{os.environ['COVERAGE_PUBLIC_BASE_URL'].rstrip('/')}/{key}"
    doc = {"version": 1, "stamp": stamp,
           "built_at": now.isoformat(timespec="seconds"),
           "tiles": {"routes": url},
           "counts": manifest.get("counts", {}),
           "country_codes": manifest.get("country_codes", [])}
    client.put_object(
        Bucket=bucket, Key=ROUTES_MANIFEST_KEY,
        Body=json.dumps(doc, separators=(",", ":")).encode(),
        ContentType="application/json",
        # Short TTL for the same reason the other manifests have one: a new
        # build goes live within the hour without a deploy or a config change.
        CacheControl="public, max-age=300")
    return url


def prune_routes(keep=3, client=None):
    """Delete routes builds beyond the newest `keep`, whole prefixes at a time.

    Same shape and same rationale as prune_surface: kept builds are a fast
    rollback, not history.
    """
    client = client or _client()
    bucket = _bucket()
    keys, stamps = [], set()
    token = None
    while True:
        kw = {"Bucket": bucket, "Prefix": "routes/"}
        if token:
            kw["ContinuationToken"] = token
        resp = client.list_objects_v2(**kw)
        for o in resp.get("Contents", []):
            m = _ROUTES_KEY.match(o["Key"])
            if m:
                keys.append(o["Key"])
                stamps.add(m.group(1))
        if not resp.get("IsTruncated"):
            break
        token = resp.get("NextContinuationToken")
    stale_stamps = set(sorted(stamps, reverse=True)[keep:])
    stale = sorted((k for k in keys if _ROUTES_KEY.match(k).group(1) in stale_stamps),
                   reverse=True)
    for key in stale:
        client.delete_object(Bucket=bucket, Key=key)
    return stale


def prune_surface(keep=3, client=None):
    """Delete surface builds beyond the newest `keep`, whole prefixes at a time.

    Fewer kept than coverage's four: a surface build is ~3 GB against coverage's
    ~0.5 GB, and the reason to keep any at all is a fast rollback, not history.
    """
    client = client or _client()
    bucket = _bucket()
    keys, stamps = [], set()
    token = None
    while True:
        kw = {"Bucket": bucket, "Prefix": "surface/"}
        if token:
            kw["ContinuationToken"] = token
        # Paginated: three artifacts per build means the 1000-key page limit is
        # reachable, and a silently truncated listing would prune the wrong set.
        resp = client.list_objects_v2(**kw)
        for o in resp.get("Contents", []):
            m = _SURFACE_KEY.match(o["Key"])
            if m:
                keys.append(o["Key"])
                stamps.add(m.group(1))
        if not resp.get("IsTruncated"):
            break
        token = resp.get("NextContinuationToken")
    stale_stamps = set(sorted(stamps, reverse=True)[keep:])
    stale = sorted((k for k in keys if _SURFACE_KEY.match(k).group(1) in stale_stamps),
                   reverse=True)
    for key in stale:
        client.delete_object(Bucket=bucket, Key=key)
    return stale


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
