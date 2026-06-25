# SPDX-License-Identifier: Apache-2.0
"""Attach a representative free-licence Wikimedia Commons photo to each quality-ride in routes-data.js.

For each ride a curated *subject* — a scenic landmark it passes (a citadel, waterfall, dam, abbey,
moorland…) — is resolved on Wikidata; its P18 image is used only if Commons reports a free licence.
Existing routes keep any photo they already have. Lossless append (other route fields untouched).
"""
import json
import pathlib
import urllib.parse
from . import overpass, enrich

ROOT = pathlib.Path(__file__).resolve().parents[2]
ROUTES_JS = ROOT / "atlas/demo/routes-data.js"

# ride name → ordered candidate subjects (first that yields a free-licence P18 wins)
SUBJECTS = {
    "Spa · Sankt Vith": ["Sankt Vith", "Vielsalm"],
    "Spa · Coo · Francorchamps": ["Cascade de Coo", "Coo (Belgique)"],
    "Spa · Côte des Hézalles": ["Lac de Warfaaz", "Spa (ville)", "Spa"],
    "Rondje Spa–Chevron": ["Stoumont", "La Gleize", "Amblève"],
    "Rondje Super Stockeu": ["Abbaye de Stavelot", "Stavelot"],
    "Afternoon Ride": ["Hautes Fagnes", "Signal de Botrange"],
    "Namur · Meuse & the Citadels": ["Citadelle de Namur", "Château de Freÿr", "Citadelle de Dinant", "Dinant"],
    "Condroz · Namur – Ciney rollers": ["Château de Modave", "Château de Spontin", "Crupet", "Ciney"],
    "Pays de Herve · cider & cheese": ["Charneux", "Aubel", "Plateau de Herve", "Abbaye du Val-Dieu"],
    "Vesdre & the dams": ["Barrage de la Gileppe", "Lac de la Gileppe", "Eupen"],
    "Liège → Spa · the Vesdre valley": ["Chaudfontaine", "Vesdre", "Pepinster"],
}


def _search_qid(name):
    url = ("https://www.wikidata.org/w/api.php?action=wbsearchentities&language=fr&format=json&limit=1&search="
           + urllib.parse.quote(name))
    hits = (overpass.get_json(url) or {}).get("search", [])
    return hits[0]["id"] if hits else None


def _photo_for(subjects):
    for s in subjects:
        qid = _search_qid(s)
        if not qid:
            continue
        ent = enrich._wikidata_entities([qid]).get(qid)
        if not ent or not ent["image"]:
            continue
        info = enrich._commons_imageinfo([ent["image"]]).get(ent["image"])
        if info and info.get("thumb") and any(k in (info["license"] or "").lower() for k in enrich.FREE):
            return enrich._photo(ent["image"], info), s
    return None, None


def build():
    txt = ROUTES_JS.read_text(encoding="utf-8")
    marker = "window.CC_ROUTES="
    head = txt[:txt.index(marker)]
    data = json.loads(txt[txt.index(marker) + len(marker):].rsplit(";", 1)[0])
    n = 0
    for r in data["routes"]:
        if r.get("photo"):
            continue
        subs = SUBJECTS.get(r["name"])
        if not subs:
            print("  no subject mapped:", r["name"])
            continue
        photo, used = _photo_for(subs)
        if photo:
            r["photo"] = photo
            n += 1
            print(f"  + {r['name']} ← {used} ({photo['license']})")
        else:
            print("  no free image found:", r["name"])
    ROUTES_JS.write_text(head + marker + json.dumps(data, ensure_ascii=False) + ";\n", encoding="utf-8")
    print(f"route images: {n}/{len(data['routes'])} rides -> atlas/demo/routes-data.js")


if __name__ == "__main__":
    build()
