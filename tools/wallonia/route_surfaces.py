# SPDX-License-Identifier: Apache-2.0
"""Map the real road surface ALONG each quality-ride (asphalt / gravel / pavé / RAVeL).

Each ride's loop is routed through BRouter, whose GeoJSON carries the OSM way-tags (incl. `surface=`)
per segment; contiguous same-surface stretches become A·Road-surface segments. Output:
atlas/demo/route-surfaces.js (window.CC_ROUTE_SURFACES) — merged into the surface layer in map.html.
"""
import re
import json
import math
import pathlib
from collections import Counter
from . import overpass

ROOT = pathlib.Path(__file__).resolve().parents[2]
ROUTES_JS = ROOT / "atlas/demo/routes-data.js"
SURFACE_DATA = ROOT / "atlas/demo/surface-data.js"   # ride surfaces are appended into CC_SURFACE.segments here

# cls → (surface label, smoothness, traffic) — cls drives colour/pattern in map.html's SURFACE_STYLE
SURF = {
    "cycleway": ("Cycleway · RAVeL", "Excellent", "Car-free"),
    "paved": ("Asphalt", "Good", "Open road"),
    "gravel": ("Gravel", "Variable", "Open road"),
    "pave": ("Sett (pavé)", "Rough", "Open road"),
    "ground": ("Unpaved", "Variable", "Open road"),
}


def _hav(a, b):  # metres between two [lon, lat]
    R = 6371000.0
    la1, lo1, la2, lo2 = map(math.radians, [a[1], a[0], b[1], b[0]])
    h = math.sin((la2 - la1) / 2) ** 2 + math.cos(la1) * math.cos(la2) * math.sin((lo2 - lo1) / 2) ** 2
    return 2 * R * math.asin(math.sqrt(h))


def _cls(waytags):
    tg = dict(x.split("=", 1) for x in waytags.split() if "=" in x)
    hw, s = tg.get("highway", ""), tg.get("surface", "")
    # The actual `surface=` tag wins over the highway type: an UNPAVED cycleway is a gravel/dirt
    # path, NOT a (paved) RAVeL. So check surface first; only an asphalt/untagged cycleway is RAVeL.
    if s in ("sett", "cobblestone", "paving_stones", "unhewn_cobblestone"):
        return "pave"
    if s in ("ground", "dirt", "earth", "grass", "sand", "mud"):
        return "ground"
    if s in ("gravel", "fine_gravel", "compacted", "unpaved", "pebblestone"):
        return "gravel"
    if hw == "cycleway" or "route_bicycle" in waytags:
        return "cycleway"   # cycleway with a paved/asphalt or untagged surface → RAVeL
    if s in ("asphalt", "concrete", "paved", "chipseal", "metal", "concrete:plates"):
        return "paved"
    if hw == "track":
        return "gravel"
    if hw in ("path", "footway", "bridleway"):
        return "ground"
    return "paved"


def _brouter(waypoints):
    ll = "|".join(f"{x:.5f},{y:.5f}" for x, y in waypoints)
    return overpass.get_json("https://brouter.de/brouter?lonlats=" + ll
                             + "&profile=trekking&alternativeidx=0&format=geojson")


def _decimate(loop, n=22):
    step = max(1, len(loop) // n)
    pts = loop[::step]
    if pts[-1] != loop[-1]:
        pts.append(loop[-1])
    return [[p[1], p[0]] for p in pts]   # [lon, lat]


def _segments(gj, ride):
    ft = gj["features"][0]
    coords = ft["geometry"]["coordinates"]
    msgs = ft["properties"].get("messages")
    if not msgs or len(coords) < 3:
        return []
    hdr = msgs[0]
    di, wi = hdr.index("Distance"), hdr.index("WayTags")
    breaks, cum = [], 0.0
    for row in msgs[1:]:
        try:
            cum += float(row[di])
        except (ValueError, TypeError):
            pass
        breaks.append((cum, _cls(row[wi])))
    gcum = [0.0]
    for i in range(1, len(coords)):
        gcum.append(gcum[-1] + _hav(coords[i - 1], coords[i]))

    def cls_at(d):
        for c, cl in breaks:
            if d <= c:
                return cl
        return breaks[-1][1] if breaks else "paved"

    runs, cur = [], None
    for i, c in enumerate(coords):
        cl = cls_at(gcum[i])
        ll = [round(c[1], 5), round(c[0], 5)]
        if cur and cur["cls"] == cl:
            cur["path"].append(ll)
            cur["len"] = gcum[i] - cur["d0"]
        else:
            if cur:
                runs.append(cur)
            cur = {"cls": cl, "path": [ll], "d0": gcum[i], "len": 0.0}
    if cur:
        runs.append(cur)
    # fold runs shorter than 120 m into the previous one (kills visual fragmentation)
    merged = []
    for r in runs:
        if merged and r["len"] < 120:
            merged[-1]["path"].extend(r["path"])
        else:
            merged.append(r)
    segs = []
    for r in merged:
        if len(r["path"]) < 2:
            continue
        surf, smooth, traf = SURF[r["cls"]]
        segs.append({"name": f"{ride} · {surf}", "surface": surf, "smoothness": smooth,
                     "width": "—", "traffic": traf, "cls": r["cls"], "path": r["path"]})
    return segs


def build():
    txt = ROUTES_JS.read_text(encoding="utf-8")
    data = json.loads(txt[txt.index("window.CC_ROUTES=") + len("window.CC_ROUTES="):].rsplit(";", 1)[0])
    allsegs = []
    for r in data["routes"]:
        gj = _brouter(_decimate(r["loop"]))
        if not gj or not gj.get("features"):
            print("  skip (brouter):", r["name"])
            continue
        s = _segments(gj, r["name"])
        allsegs.extend(s)
        print(f"  {r['name']}: {len(s)} segments  {dict(Counter(x['cls'] for x in s))}")
    # Field correction: OSM under-tags surface on the Hautes Fagnes cycleways (forest/moor gravel
    # tracks), so they derive as "RAVeL". Confirmed gravel on the ground — force cycleway→gravel for
    # segments lying ENTIRELY within the plateau box (segments that exit it, e.g. back toward Spa, are
    # left alone so a genuinely paved stretch isn't mislabelled).
    FAGNES = (50.46, 50.51, 6.01, 6.13)            # lat0, lat1, lon0, lon1 — path points are [lat, lon]
    gsurf, gsmooth, gtraf = SURF["gravel"]
    for s in allsegs:
        if s["cls"] == "cycleway" and all(FAGNES[0] <= p[0] <= FAGNES[1] and FAGNES[2] <= p[1] <= FAGNES[3] for p in s["path"]):
            s["cls"] = "gravel"
            s["surface"], s["smoothness"], s["traffic"] = gsurf, gsmooth, gtraf
            s["name"] = s["name"].replace(" · Cycleway · RAVeL", " · Gravel")
    # append into CC_SURFACE.segments (the existing surface layer merge picks them up — no map.html change).
    # marker-wrapped so re-running replaces them rather than duplicating; the demo segments are preserved.
    sd = SURFACE_DATA.read_text(encoding="utf-8")
    sd = re.sub(r",/\*RS_START\*/.*?/\*RS_END\*/", "", sd, flags=re.S)
    idx = sd.rindex("]")                          # the segments-array close
    inject = ",/*RS_START*/" + ",".join(json.dumps(s, ensure_ascii=False) for s in allsegs) + "/*RS_END*/"
    SURFACE_DATA.write_text(sd[:idx] + inject + sd[idx:], encoding="utf-8")
    print(f"route surfaces: injected {len(allsegs)} segments from {len(data['routes'])} rides into surface-data.js")


if __name__ == "__main__":
    build()
