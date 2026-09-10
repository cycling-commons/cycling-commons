# SPDX-License-Identifier: AGPL-3.0-only
"""Fetch one provider's service and write the file the ingest reads.

    python -m providers.run --key rivm-drinkwater --out /tmp/rivm.json

The provider's configuration is read from `data_provider`, not from a flag or
a script: adding a dataset is a row on the curator desk, which is the whole
point of the registry (data-provider-hierarchy.md §3, §5).

**We ask the service for EPSG:4326** rather than reprojecting ourselves. The
publisher's own transform is more authoritative than one applied to their data
from outside, and it keeps a projection library out of this image. Anything
that comes back outside WGS84 bounds is refused rather than written, so a
service that ignores `srsName` fails loudly instead of putting a Dutch tap in
the ocean.

Nothing here writes to the catalogue. The output is a normalised GeoJSON file,
and `app:providers:harvest` decides what becomes of it, dry by default.
"""

from __future__ import annotations

import argparse
import json
import os
import pathlib
import sys
import urllib.parse
import urllib.request

import psycopg

from .normalise import NormaliseError, normalise

# The service call, not the whole run: a slow WFS should fail this job rather
# than hold a weekly timer open all night.
TIMEOUT_S = 120

USER_AGENT = "CyclingCommons provider harvester (+https://cyclingcommons.org)"


def load_provider(dsn: str, key: str) -> dict:
    """The registry row, or a refusal naming what is missing."""
    with psycopg.connect(dsn) as conn, conn.cursor() as cur:
        cur.execute(
            """SELECT provider_key, name, endpoint, endpoint_kind, field_map,
                      letters, country_code, enabled
                 FROM data_provider WHERE provider_key = %s""",
            (key,),
        )
        row = cur.fetchone()

    if row is None:
        raise SystemExit(f"No provider {key!r} in the registry.")

    provider = dict(zip(
        ["key", "name", "endpoint", "endpoint_kind", "field_map",
         "letters", "country_code", "enabled"], row))

    if not provider["enabled"]:
        # Paused means "keep the rows, stop refreshing", so refreshing one is
        # the one thing this must not do.
        raise SystemExit(f"Provider {key!r} is paused. Enable it on the desk first.")
    if not provider["endpoint"]:
        raise SystemExit(f"Provider {key!r} has no endpoint to fetch.")
    if not provider["letters"]:
        raise SystemExit(f"Provider {key!r} fills no catalogue letter.")

    return provider


def service_url(provider: dict) -> str:
    """The request, with WGS84 asked for explicitly where the kind allows it."""
    endpoint = provider["endpoint"]
    kind = (provider["endpoint_kind"] or "").lower()

    if kind != "wfs":
        # geojson / csv endpoints are whatever the publisher serves; the
        # coordinate check downstream is what keeps them honest.
        return endpoint

    layer = (provider["field_map"] or {}).get("_layer")
    if not layer:
        raise SystemExit(
            f"Provider {provider['key']!r} is a WFS but its field map has no _layer."
        )

    query = {
        "service": "WFS",
        "version": "2.0.0",
        "request": "GetFeature",
        "typeNames": layer,
        "outputFormat": "application/json",
        "srsName": "EPSG:4326",
    }
    joiner = "&" if "?" in endpoint else "?"
    return endpoint + joiner + urllib.parse.urlencode(query)


def fetch(url: str) -> dict:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(request, timeout=TIMEOUT_S) as response:  # noqa: S310
        return json.load(response)


def convert(provider: dict, document: dict) -> tuple[list[dict], list[str]]:
    """(features we vouch for, one line per refusal)."""
    field_map = dict(provider["field_map"] or {})
    # Underscore keys configure the fetch rather than name an attribute.
    id_field = field_map.pop("_id", None)
    field_map.pop("_layer", None)

    letter = provider["letters"][0]
    out: list[dict] = []
    refusals: list[str] = []

    for i, feature in enumerate(document.get("features") or []):
        try:
            out.append(normalise(
                feature,
                key=provider["key"],
                letter=letter,
                field_map=field_map,
                id_field=id_field,
                country_code=provider["country_code"],
            ))
        except NormaliseError as exc:
            refusals.append(f"feature {i}: {exc}")

    return out, refusals


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--key", required=True, help="provider_key in data_provider")
    parser.add_argument("--out", required=True, help="where to write the normalised GeoJSON")
    parser.add_argument("--dsn", default=os.environ.get("DATABASE_DSN", ""))
    args = parser.parse_args(argv)

    if not args.dsn:
        raise SystemExit("No database DSN: pass --dsn or set DATABASE_DSN.")

    provider = load_provider(args.dsn, args.key)
    document = fetch(service_url(provider))
    features, refusals = convert(provider, document)

    for line in refusals[:20]:
        print(f"  refused {line}", file=sys.stderr)
    if len(refusals) > 20:
        print(f"  ... and {len(refusals) - 20} more", file=sys.stderr)

    if not features:
        # An empty result is a fetch that failed quietly. Writing it would let
        # the ingest mark every existing row stale.
        raise SystemExit("No usable features. Refusing to write an empty harvest.")

    path = pathlib.Path(args.out)
    path.write_text(
        json.dumps({"type": "FeatureCollection", "features": features}, ensure_ascii=False),
        encoding="utf-8",
    )
    print(f"{provider['key']}: {len(features)} features, {len(refusals)} refused -> {path}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
