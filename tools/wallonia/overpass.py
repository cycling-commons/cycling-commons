# SPDX-License-Identifier: Apache-2.0
"""Cached Overpass client + element→feature mapping for the Wallonia harvest."""
import hashlib
import json
import pathlib
import time
import urllib.request
import urllib.error
import urllib.parse

ENDPOINT = "https://overpass-api.de/api/interpreter"
CACHE = pathlib.Path(__file__).resolve().parent / ".cache"


def query(ql: str) -> dict:
    """POST an Overpass QL query, returning parsed JSON. Caches by sha1(ql); retries on 429/5xx."""
    CACHE.mkdir(exist_ok=True)
    key = CACHE / (hashlib.sha1(ql.encode()).hexdigest() + ".json")
    if key.exists():
        return json.loads(key.read_text())
    data = ("data=" + urllib.parse.quote(ql)).encode()
    for attempt in range(3):
        try:
            req = urllib.request.Request(
                ENDPOINT, data=data,
                headers={"User-Agent": "CyclingCommons/wallonia-harvest"})
            with urllib.request.urlopen(req, timeout=180) as r:
                out = json.loads(r.read())
            key.write_text(json.dumps(out))
            return out
        except urllib.error.HTTPError as e:
            if e.code in (429, 502, 503, 504) and attempt < 2:
                time.sleep(5 * (attempt + 1))
                continue
            raise


def elements_to_features(elements, type_map, prov):
    """Map raw Overpass elements (node, or way/relation with `center`) to GeoJSON point features.

    `type_map` is a list of (key, value, label); the first matching tag picks the feature's `t`.
    A private `_id` ("<type>/<id>") is attached for dedupe and stripped before serialization.
    """
    feats = []
    for el in elements:
        tags = el.get("tags", {})
        label = next((lbl for k, v, lbl in type_map if tags.get(k) == v), None)
        if label is None:
            continue
        if el["type"] == "node":
            lon, lat = el["lon"], el["lat"]
        else:
            c = el.get("center")
            if not c:
                continue
            lon, lat = c["lon"], c["lat"]
        props = {"t": label}
        if tags.get("name"):
            props["n"] = tags["name"]
        props["prov"] = prov
        feats.append({
            "type": "Feature",
            "properties": props,
            "geometry": {"type": "Point", "coordinates": [round(lon, 5), round(lat, 5)]},
            "_id": f'{el["type"]}/{el["id"]}',
        })
    return feats
