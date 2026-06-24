# SPDX-License-Identifier: Apache-2.0
"""Add curated NE + center Wallonia quality-rides, traced on real OSM geometry via BRouter.

Appends to atlas/demo/routes-data.js (existing routes preserved byte-for-byte; only new
routes + heat points are added). Each route's loop, length and elevation are real (BRouter
on OpenStreetMap data); the selection/theming is editorial.
"""
import json
import pathlib
from . import overpass

ROOT = pathlib.Path(__file__).resolve().parents[2]
ROUTES_JS = ROOT / "atlas/demo/routes-data.js"
LABELS = {1: "Easy", 2: "Moderate", 3: "Challenging", 4: "Hard", 5: "Very hard"}

# region: center (Namur/Condroz/Meuse) + northeast (Liège/Pays de Herve/Vesdre)
# wp = waypoints [lon, lat]; loop=True closes back to the start.
SEEDS = [
    {"name": "Namur · Meuse & the Citadels", "season": "summer", "loop": True,
     "wp": [[4.8670, 50.4670], [4.8730, 50.4200], [4.8660, 50.3780], [4.8800, 50.3280],
            [4.9120, 50.2600], [4.8810, 50.3100], [4.8830, 50.3640]]},
    {"name": "Condroz · Namur – Ciney rollers", "season": "autumn", "loop": True,
     "wp": [[4.8670, 50.4670], [5.0950, 50.4890], [5.1230, 50.4300], [5.1000, 50.2950],
            [5.0270, 50.3470], [5.0120, 50.3700]]},
    {"name": "Pays de Herve · cider & cheese", "season": "autumn", "loop": True,
     "wp": [[5.7930, 50.6380], [5.8190, 50.6430], [5.8570, 50.7050], [5.8290, 50.6650],
            [5.8680, 50.6620]]},
    {"name": "Vesdre & the dams", "season": "summer", "loop": True,
     "wp": [[6.0370, 50.6280], [5.9850, 50.6380], [5.9400, 50.6120], [5.9720, 50.5900],
            [5.9700, 50.5560]]},
    {"name": "Liège → Spa · the Vesdre valley", "season": "summer", "loop": False,
     "wp": [[5.5730, 50.6450], [5.6380, 50.5850], [5.6940, 50.5670], [5.8060, 50.5630],
            [5.8190, 50.5350], [5.8640, 50.4920]]},
]


def _brouter(wp, closed):
    pts = wp + ([wp[0]] if closed else [])
    lonlats = "|".join(f"{x:.5f},{y:.5f}" for x, y in pts)
    return overpass.get_json("https://brouter.de/brouter?lonlats=" + lonlats
                             + "&profile=trekking&alternativeidx=0&format=geojson")


def _build(seed):
    gj = _brouter(seed["wp"], seed.get("loop"))
    if not gj or not gj.get("features"):
        return None
    ft = gj["features"][0]
    coords = ft["geometry"]["coordinates"]
    props = ft.get("properties", {})
    loop = [[round(c[1], 5), round(c[0], 5)] for c in coords]            # [lat, lon]
    elev = [round(c[2]) for c in coords if len(c) > 2]
    gain, prev = 0, (elev[0] if elev else 0)
    for e in elev[1:]:                                                    # sum positive deltas, filter <2 m noise
        if e - prev >= 2:
            gain += e - prev
        prev = e
    km = round(int(props.get("track-length", 0)) / 1000, 1)
    step = max(1, len(elev) // 40)
    prof = elev[::step] if elev else []
    score = max(1, min(5, 1 + int(km // 45) + int(gain // 450)))
    return {"name": seed["name"], "season": seed["season"], "km": km,
            "start": loop[0], "loop": loop, "elev": prof, "gain": gain,
            "difficulty": {"score": score, "label": LABELS[score]}}


def build():
    txt = ROUTES_JS.read_text(encoding="utf-8")
    marker = "window.CC_ROUTES="
    head = txt[:txt.index(marker)]
    data = json.loads(txt[txt.index(marker) + len(marker):].rsplit(";", 1)[0])
    existing = {r["name"] for r in data["routes"]}
    added = 0
    for seed in SEEDS:
        if seed["name"] in existing:
            continue
        r = _build(seed)
        if not r:
            print("  skip (BRouter failed):", seed["name"])
            continue
        data["routes"].append(r)
        for p in r["loop"][::6]:                                          # feed the loop into the heatmap too
            data["heat"].append([p[0], p[1], r["season"]])
        added += 1
        print(f"  + {r['name']} · {r['km']} km · {r['gain']} m climb · {r['difficulty']['label']}")
    ROUTES_JS.write_text(head + marker + json.dumps(data, ensure_ascii=False) + ";\n", encoding="utf-8")
    print(f"routes: +{added} added -> atlas/demo/routes-data.js ({len(data['routes'])} total)")


if __name__ == "__main__":
    build()
