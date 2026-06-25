# SPDX-License-Identifier: Apache-2.0
"""Harvest Wallonia tourist accommodation from the official PIVOT database.

Source: "Les offres touristiques en Wallonie" (Tourisme Wallonie / CGT), published **CC-BY 4.0**
via the Géoportail de la Wallonie ArcGIS REST service. This is the one openly-licensed,
non-OSM stays source for Wallonia — the official complement to the OSM `tourism=*` harvest.
See .github/wiki-prepare/overnight-stay-data-sources.md for the full sourcing rationale.

Output: atlas/demo/stays-pivot.js (window.CC_STAYS_PIVOT), region-balanced across the 5 provinces.
Run:    PYTHONPATH=tools python3 -m wallonia.pivot [--report]

Notes / gotchas (verified against the live service, 2026-06-25):
  - The LON/LAT *attribute* fields are rounded to integers (useless); real coords are in the
    feature geometry when queried with outSR=4326 — we use geometry.x / geometry.y.
  - The dataset has NO "Bienvenue Vélo" (cyclist-welcome) attribute, so we can't flag bike-friendly.
  - PIVOT carries no province field; province is derived from the Belgian postcode.
  - maxRecordCount=2000, supportsPagination=true → page via resultOffset, ordered by OBJECTID.
"""
import sys
import datetime
import pathlib
from collections import defaultdict
from . import overpass
from .harvest_poi import to_fixture_js

ROOT = pathlib.Path(__file__).resolve().parents[2]
DEMO = ROOT / "atlas/demo"
SERVICE = "https://geoservices.wallonie.be/arcgis/rest/services/TOURISME/OFFRES_TOURISTIQUES/MapServer"

# ArcGIS "Hébergements" sub-layer id → English stay type (props.t). Order = demo priority for
# the per-province round-robin (rarer / more cyclist-relevant types picked first).
LAYERS = [
    (5,  "Campsite"),
    (1,  "Hotel"),
    (13, "B&B"),               # Chambres d'hôtes
    (6,  "Budget stay"),       # Budget Holidays
    (3,  "Gîte"),
    (4,  "Furnished rental"),  # Meublés
]
# (7) Villages de vacances is empty; (8) Restaurants, (9) Loisirs, (12) Organismes = other categories.

PROVINCES = ("Brabant wallon", "Hainaut", "Liège", "Luxembourg", "Namur")
PROVINCE_CAP = 30             # per province → ~150 points, type-diverse via round-robin
WALLONIA_BBOX = (2.7, 49.4, 6.5, 50.9)   # (minlon, minlat, maxlon, maxlat) guard against bad geocodes


def _province(postcode):
    """Belgian postcode → Walloon province label (matching the OSM fixtures), or None if outside Wallonia."""
    try:
        p = int(str(postcode).strip())
    except (TypeError, ValueError):
        return None
    if 1300 <= p <= 1499:
        return "Brabant wallon"
    if 4000 <= p <= 4999:
        return "Liège"
    if 5000 <= p <= 5999:
        return "Namur"
    if 6000 <= p <= 6599 or 7000 <= p <= 7999:
        return "Hainaut"
    if 6600 <= p <= 6999:
        return "Luxembourg"
    return None


def _fetch_layer(lid):
    """Page through one ArcGIS sub-layer (offset paging, ordered by OBJECTID for stability)."""
    out, offset = [], 0
    while True:
        url = (f"{SERVICE}/{lid}/query?where=1%3D1"
               "&outFields=NOM,COMMUNE,CODE_POSTAL,SITE_WEB"
               "&orderByFields=OBJECTID&returnGeometry=true&outSR=4326&f=json"
               f"&resultOffset={offset}&resultRecordCount=2000")
        d = overpass.get_json(url) or {}
        feats = d.get("features", [])
        out.extend(feats)
        if not feats or not d.get("exceededTransferLimit"):
            break
        offset += len(feats)
    return out


def harvest(report=False):
    # 1) gather every usable accommodation record across the stay sub-layers
    rows = []
    for lid, label in LAYERS:
        kept = 0
        for f in _fetch_layer(lid):
            a, g = f.get("attributes", {}) or {}, f.get("geometry") or {}
            lon, lat = g.get("x"), g.get("y")
            nom = (a.get("NOM") or "").strip()
            prov = _province(a.get("CODE_POSTAL"))
            if not nom or prov is None or lon is None or lat is None:
                continue
            x0, y0, x1, y1 = WALLONIA_BBOX
            if not (x0 < lon < x1 and y0 < lat < y1):
                continue
            rows.append({"type": label, "prov": prov, "nom": nom,
                         "commune": (a.get("COMMUNE") or "").strip(),
                         "web": (a.get("SITE_WEB") or "").strip(),
                         "lon": round(lon, 5), "lat": round(lat, 5)})
            kept += 1
        print(f"  [{lid}] {label}: {kept} usable")

    # 2) dedupe by (name, commune)
    seen, uniq = set(), []
    for r in rows:
        k = (r["nom"].lower(), r["commune"].lower())
        if k not in seen:
            seen.add(k)
            uniq.append(r)

    # 3) region-balance: round-robin by type within each province (stable: sorted by name)
    by_pt = defaultdict(list)
    for r in uniq:
        by_pt[(r["prov"], r["type"])].append(r)
    for v in by_pt.values():
        v.sort(key=lambda r: r["nom"])
    order = [lbl for _, lbl in LAYERS]
    chosen = []
    for prov in PROVINCES:
        idx, taken = defaultdict(int), 0
        while taken < PROVINCE_CAP:
            advanced = False
            for t in order:
                lst = by_pt[(prov, t)]
                if idx[t] < len(lst):
                    chosen.append(lst[idx[t]])
                    idx[t] += 1
                    taken += 1
                    advanced = True
                    if taken >= PROVINCE_CAP:
                        break
            if not advanced:
                break

    # 4) GeoJSON features → fixture
    feats = []
    for r in chosen:
        # official registry entry → a verified spot (large pin); `c` is the verification label.
        props = {"t": r["type"], "n": r["nom"], "town": r["commune"], "prov": r["prov"],
                 "c": "Official listing"}
        if r["web"].startswith("http"):
            props["web"] = r["web"]
        feats.append({"type": "Feature", "properties": props,
                      "geometry": {"type": "Point", "coordinates": [r["lon"], r["lat"]]}})
    today = datetime.date.today().isoformat()
    header = (
        "// SPDX-License-Identifier: CC-BY-4.0\n"
        "// Wallonia tourist accommodation © Tourisme Wallonie (TW) — CC-BY 4.0.\n"
        "// Source: PIVOT \"Les offres touristiques en Wallonie\" via Géoportail de la Wallonie\n"
        "//   (geoservices.wallonie.be/arcgis/rest/services/TOURISME/OFFRES_TOURISTIQUES). NOT OSM.\n"
        f"// Region-balanced across the 5 Walloon provinces. Harvested {today} · {len(feats)} points.\n"
        "// Official registry entries → flagged verified (c='Official listing'), render as confirmed pins.\n"
        "// Attribute as: \"Source : Tourisme Wallonie (TW)\". {t:type, n:name, town, prov:province, c, web:site}.\n")
    (DEMO / "stays-pivot.js").write_text(to_fixture_js(feats, "CC_STAYS_PIVOT", header), encoding="utf-8")
    print(f"pivot stays: {len(feats)} points -> atlas/demo/stays-pivot.js")
    if report:
        cnt = defaultdict(lambda: defaultdict(int))
        for r in chosen:
            cnt[r["prov"]][r["type"]] += 1
        for prov in PROVINCES:
            print(f"    {prov:16} {dict(cnt[prov])}")


if __name__ == "__main__":
    harvest(report="--report" in sys.argv)
