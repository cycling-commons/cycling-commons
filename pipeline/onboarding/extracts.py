# SPDX-License-Identifier: AGPL-3.0-only
"""Which Geofabrik extract a country is harvested from, decided from Geofabrik's index."""
from __future__ import annotations

import json
import re
import urllib.request

GEOFABRIK_INDEX = "https://download.geofabrik.de/index-v1-nogeom.json"
_PBF = re.compile(r"^https://download\.geofabrik\.de/(?P<slug>.+)-latest\.osm\.pbf$")
_UA = "CyclingCommons-onboarding/1.0 (https://cyclingcommons.org)"


class ExtractUndecided(ValueError):
    """No single extract matches; the message names the candidates and --extract."""


def load_index(source: str) -> dict:
    if source.startswith(("http://", "https://")):
        req = urllib.request.Request(source, headers={"User-Agent": _UA})
        with urllib.request.urlopen(req, timeout=60) as resp:
            return json.load(resp)
    with open(source, encoding="utf-8") as fh:
        return json.load(fh)


def _slug(props: dict) -> str | None:
    m = _PBF.match((props.get("urls") or {}).get("pbf", ""))
    return m.group("slug") if m else None


def extract_slugs(index: dict) -> list[str]:
    return sorted(s for f in index.get("features", []) if (s := _slug(f.get("properties", {}))))


def candidates(index: dict, cc: str) -> list[str]:
    """Extracts whose iso3166-1:alpha2 is exactly [cc], shallowest first."""
    found = [s for f in index.get("features", [])
             if (p := f.get("properties", {})).get("iso3166-1:alpha2") == [cc] and (s := _slug(p))]
    return sorted(found, key=lambda s: (s.count("/"), s))


def choose_extract(index: dict, cc: str, override: str | None = None) -> str:
    if override:
        if override not in extract_slugs(index):
            raise ExtractUndecided(f"--extract {override}: not an extract in Geofabrik's index")
        return override
    found = candidates(index, cc)
    if not found:
        shared = sorted(s for f in index.get("features", [])
                        if cc in ((p := f.get("properties", {})).get("iso3166-1:alpha2") or []) and (s := _slug(p)))
        hint = f"; extracts that include {cc}: {', '.join(shared)}" if shared else ""
        raise ExtractUndecided(
            f"no Geofabrik extract carries iso3166-1:alpha2 = [{cc}]{hint}; rerun with --extract <slug>")
    shallowest = [s for s in found if s.count("/") == found[0].count("/")]
    if len(shallowest) > 1:
        raise ExtractUndecided(f"several extracts at the same depth: {', '.join(shallowest)}; rerun with --extract <slug>")
    return shallowest[0]
