# SPDX-License-Identifier: Apache-2.0
"""Curated legendary Wallonia climbs → atlas/demo/climbs-data.js.

Locations come from **Wikidata** (P625 coordinates) — accurate, not guessed — and each climb is
enriched with an English description + a free-licence Wikimedia Commons photo (same pipeline as the
POI layers). Climbs without a Wikidata item, or whose coordinates fall outside Wallonia, are skipped.
Excludes the five climbs already curated in map.html (Redoute, Mur de Huy, Stockeu, Roche-aux-Faucons,
Hockai).
"""
import re
import math
import json
import bisect
import pathlib
import urllib.parse
from . import overpass, enrich

# Climb feet (bottom) supplied by hand — the one thing no open dataset has. {name: [lat, lon]}
FEET = {
    "Côte de Bohissau": [50.494791, 5.111816],
    "Côte d'Ereffe": [50.478930, 5.266503],
    "Côte de Cherave": [50.510257, 5.220367],
}


def _hav(a, b):
    """Metres between two [lon, lat] points."""
    R = 6371000.0
    la1, lo1, la2, lo2 = map(math.radians, [a[1], a[0], b[1], b[0]])
    h = math.sin((la2 - la1) / 2) ** 2 + math.cos(la1) * math.cos(la2) * math.sin((lo2 - lo1) / 2) ** 2
    return 2 * R * math.asin(math.sqrt(h))


def _at(cum, coords, elev, d):
    """Interpolate (elevation, [lon, lat]) at cumulative distance d along the track."""
    i = bisect.bisect_left(cum, d)
    if i <= 0:
        return elev[0], coords[0][:2]
    if i >= len(cum):
        return elev[-1], coords[-1][:2]
    t = (d - cum[i - 1]) / (cum[i] - cum[i - 1]) if cum[i] > cum[i - 1] else 0
    e = elev[i - 1] + t * (elev[i] - elev[i - 1])
    lon = coords[i - 1][0] + t * (coords[i][0] - coords[i - 1][0])
    lat = coords[i - 1][1] + t * (coords[i][1] - coords[i - 1][1])
    return e, [lon, lat]


def _trace_climb(foot, top):
    """BRouter foot→top → {route, grad(12 seg), steep, km, avg, max} from real geometry + elevation."""
    ll = f"{foot[1]:.6f},{foot[0]:.6f}|{top[1]:.6f},{top[0]:.6f}"
    gj = overpass.get_json("https://brouter.de/brouter?lonlats=" + ll
                           + "&profile=trekking&alternativeidx=0&format=geojson")
    if not gj or not gj.get("features"):
        return None
    coords = gj["features"][0]["geometry"]["coordinates"]
    if len(coords) < 3:
        return None
    elev = [c[2] if len(c) > 2 else 0 for c in coords]
    cum = [0.0]
    for i in range(1, len(coords)):
        cum.append(cum[-1] + _hav(coords[i - 1], coords[i]))
    total = cum[-1]
    if total < 200:
        return None
    route = [[round(c[1], 5), round(c[0], 5)] for c in coords]
    avg = round(100 * (elev[-1] - elev[0]) / total, 1)
    if avg < 1.5:                       # foot→top doesn't ascend → the top coord is wrong (e.g. bad Wikidata point)
        return None
    N = 12
    grad, mids = [], []
    for s in range(N):
        e0, _ = _at(cum, coords, elev, total * s / N)
        e1, _ = _at(cum, coords, elev, total * (s + 1) / N)
        grad.append(max(0, round(100 * (e1 - e0) / (total / N))))
        _, pm = _at(cum, coords, elev, total * (s + 0.5) / N)
        mids.append(pm)
    mx = max(grad)
    si = grad.index(mx)
    return {"route": route, "grad": grad, "km": round(total / 1000, 1), "avg": avg, "max": mx,
            "steep": {"at": [round(mids[si][1], 5), round(mids[si][0], 5)], "pct": f"~{mx}%"}}


def _stats_from_desc(desc):
    """Pull (length_km, avg_pct) out of a Wikipedia blurb like 'a 2,200 m climb with an average of 7.6%'."""
    if not desc:
        return None, None
    km = None
    m = re.search(r'([\d][\d.,]*)\s*m\b', desc)
    if m:
        try:
            metres = int(float(m.group(1).replace(",", "").replace(" ", "")))
            if metres >= 200:                 # a real climb length, not a stray '1 m'
                km = round(metres / 1000, 1)
        except ValueError:
            pass
    p = re.search(r'(\d+[.,]?\d*)\s*%', desc)
    avg = float(p.group(1).replace(",", ".")) if p else None
    return km, avg

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / "atlas/demo/climbs-data.js"
BBOX = (2.84, 49.45, 6.41, 50.85)

# name, race heritage, length km (optional), average % (optional). Stats only where well-documented.
SEED = [
    ("Côte de Wanne", "Liège–Bastogne–Liège", 2.6, 7.5),
    ("Col du Rosier", "Liège–Bastogne–Liège", 4.4, 5.9),
    ("Côte de la Haute-Levée", "Liège–Bastogne–Liège", 3.6, 5.6),
    ("Côte de Desnié", "Liège–Bastogne–Liège", 1.7, 8.0),
    ("Côte des Forges", "Liège–Bastogne–Liège", 1.3, 7.8),
    ("Côte de Cherave", "La Flèche Wallonne", 1.3, 8.1),
    ("Côte de la Vecquée", "Liège–Bastogne–Liège", None, None),
    ("Côte de Bellevaux", "Liège–Bastogne–Liège", None, None),
    ("Côte de Trasenster", "Liège–Bastogne–Liège", None, None),
    ("Côte de Mont-le-Soie", "Liège–Bastogne–Liège", None, None),
    ("Mur de Thuin", "GP de Wallonie / cyclo-cross", None, None),
    ("Côte d'Ereffe", "La Flèche Wallonne", None, None),
    ("Côte de Bohissau", "La Flèche Wallonne", None, None),
    ("Côte de Ny", "Liège–Bastogne–Liège", None, None),
    ("Côte de la Ferme Libert", "Ardennes", None, None),
]


def _search_qid(name):
    url = ("https://www.wikidata.org/w/api.php?action=wbsearchentities&language=fr&format=json&limit=1&search="
           + urllib.parse.quote(name))
    hits = (overpass.get_json(url) or {}).get("search", [])
    return hits[0]["id"] if hits else None


def build():
    # 1. resolve each seed name to a Wikidata id + coordinates
    feats, meta = [], {}
    for name, race, km, avg in SEED:
        qid = _search_qid(name)
        if not qid:
            print(f"  skip (no wikidata): {name}")
            continue
        ent = overpass.get_json(
            "https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&props=claims&ids=" + qid)
        p625 = ((ent or {}).get("entities", {}).get(qid, {}).get("claims", {}).get("P625"))
        if not p625:
            print(f"  skip (no coords): {name}")
            continue
        v = p625[0]["mainsnak"].get("datavalue", {}).get("value", {})
        lat, lon = round(v.get("latitude", 0), 5), round(v.get("longitude", 0), 5)
        if not (BBOX[0] <= lon <= BBOX[2] and BBOX[1] <= lat <= BBOX[3]):
            print(f"  skip (outside Wallonia): {name} {(lat, lon)}")
            continue
        meta[qid] = {"name": name, "race": race, "km": km, "avg": avg, "ll": [lat, lon]}
        feats.append({"properties": {"t": "Climb", "n": name},
                      "geometry": {"type": "Point", "coordinates": [lon, lat]},
                      "_tags": {"wikidata": qid}})

    # 2. reuse the enrichment pipeline (English desc + free-licence Commons photo)
    enrich.enrich(feats)

    # 3. assemble climb records in the map's climb shape
    climbs = []
    for qid, f in zip([x["_tags"]["wikidata"] for x in feats], feats):
        m = meta[qid]
        p = f["properties"]
        traced = _trace_climb(FEET[m["name"]], m["ll"]) if m["name"] in FEET else None
        if m["name"] in FEET and not traced:
            print(f"  ! {m['name']}: foot given but trace invalid — the TOP coord looks wrong (Wikidata point bad?)")
        if traced:                                       # BRouter geometry is authoritative
            km, avg = traced["km"], traced["avg"]
        else:
            dkm, davg = _stats_from_desc(p.get("desc"))  # else Wikipedia stats (over my seed estimate)
            km, avg = (dkm or m["km"]), (davg or m["avg"])
        headline = f"{km} km · {avg}% avg" if km and avg else "Legendary Ardennes climb"
        record = []
        if km:
            record.append({"label": "Length", "value": f"{km} km"})
        if avg:
            record.append({"label": "Average gradient", "value": f"{avg}%"})
        if traced:
            record.append({"label": "Max gradient", "value": f"~{traced['max']}% (steepest ramp)"})
        record.append({"label": "Famous for", "value": m["race"]})
        record.append({"label": "Surface", "value": "Asphalt", "method": "OSM"})
        climb = {"name": m["name"], "headline": headline, "cur": True, "sq": "Good", "tr": "Quiet",
                 "geom": {"ll": m["ll"]}, "record": record,
                 "source": "Wikidata (P625) · OpenStreetMap" + (" · BRouter" if traced else "")}
        if traced:
            climb["route"], climb["grad"], climb["steep"] = traced["route"], traced["grad"], traced["steep"]
        if p.get("desc"):
            # "côte" mistranslates to "coast"; in a climb context it's always a climb/hill
            climb["desc"] = p["desc"].replace("Coast", "Climb").replace("coast", "climb")
        if p.get("descTr"):
            climb["descTr"] = 1
        if p.get("photo"):
            climb["photo"] = p["photo"]
        climbs.append(climb)

    hdr = ("// SPDX-License-Identifier: ODbL-1.0\n"
           "// Legendary Wallonia climbs. Coordinates © Wikidata (CC0); descriptions © Wikipedia (CC BY-SA 4.0);\n"
           "// photos © Wikimedia Commons (per-file licence in photo.license). Curated set, appended to the B layer.\n")
    OUT.write_text(hdr + "window.CC_CLIMBS=" + json.dumps(climbs, ensure_ascii=False) + ";\n", encoding="utf-8")
    withphoto = sum(1 for c in climbs if c.get("photo"))
    print(f"climbs: {len(climbs)} curated ({withphoto} with photo) -> atlas/demo/climbs-data.js")


if __name__ == "__main__":
    build()
