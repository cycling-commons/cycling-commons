# SPDX-License-Identifier: Apache-2.0
"""Resolve the five Walloon province areas for region-balanced harvesting."""
from . import overpass

PROVINCES = ["Brabant wallon", "Hainaut", "Liège", "Luxembourg", "Namur"]

_QL = """
[out:json][timeout:120];
area["admin_level"="4"]["name"="Wallonie"]->.wal;
relation["admin_level"="6"]["boundary"="administrative"](area.wal);
out tags;
"""


def province_areas():
    """Return {province_name: overpass_area_id}. Area id = 3600000000 + relation id."""
    res = overpass.query(_QL)
    out = {}
    for el in res.get("elements", []):
        name = el.get("tags", {}).get("name")
        if name in PROVINCES:
            out[name] = 3600000000 + el["id"]
    missing = set(PROVINCES) - set(out)
    if missing:
        raise RuntimeError(f"Missing Walloon provinces from Overpass: {sorted(missing)}")
    return out
