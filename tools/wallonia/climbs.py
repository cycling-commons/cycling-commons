# SPDX-License-Identifier: Apache-2.0
"""Curated legendary Wallonia climbs → atlas/demo/climbs-data.js.

Locations come from **Wikidata** (P625 coordinates) — accurate, not guessed — and each climb is
enriched with an English description + a free-licence Wikimedia Commons photo (same pipeline as the
POI layers). Climbs without a Wikidata item, or whose coordinates fall outside Wallonia, are skipped.
Excludes the five climbs already curated in map.html (Redoute, Mur de Huy, Stockeu, Roche-aux-Faucons,
Hockai).
"""
import json
import pathlib
import urllib.parse
from . import overpass, enrich

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
        headline = f"{m['km']} km · {m['avg']}% avg" if m["km"] and m["avg"] else "Legendary Ardennes climb"
        record = []
        if m["km"]:
            record.append({"label": "Length", "value": f"{m['km']} km"})
        if m["avg"]:
            record.append({"label": "Average gradient", "value": f"{m['avg']}%"})
        record.append({"label": "Famous for", "value": m["race"]})
        record.append({"label": "Surface", "value": "Asphalt", "method": "OSM"})
        climb = {"name": m["name"], "headline": headline, "cur": True, "sq": "Good", "tr": "Quiet",
                 "geom": {"ll": m["ll"]}, "record": record,
                 "source": "Wikidata (P625) · OpenStreetMap"}
        if p.get("desc"):
            climb["desc"] = p["desc"]
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
