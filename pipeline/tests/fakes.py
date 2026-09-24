# SPDX-License-Identifier: AGPL-3.0-only
"""Shared test doubles for the coverage pipeline suite."""

from __future__ import annotations

import io


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
