# SPDX-License-Identifier: Apache-2.0
"""Enrich harvested features with a Wikipedia description + a Wikimedia Commons photo.

Sources (all cached via overpass.get_json):
  - Wikidata wbgetentities  → P18 image, sitelinks, short description
  - Wikipedia REST summary  → a richer one-paragraph blurb
  - Commons imageinfo       → photo thumbnail URL + LICENCE + credit (only free licences are kept)
"""
import re
import json
import hashlib
import urllib.parse
import urllib.request
from . import overpass

WD_API = "https://www.wikidata.org/w/api.php"
COMMONS_API = "https://commons.wikimedia.org/w/api.php"
FREE = ("cc0", "cc by", "cc-by", "public domain", "pd-", "no restrictions", "attribution")


def _translate(text, src):
    """Machine-translate `text` from `src` (e.g. 'fr') to English via MyMemory. Caches successes only."""
    text = " ".join(text.split())[:450]
    if not text:
        return None
    overpass.CACHE.mkdir(exist_ok=True)
    key = overpass.CACHE / ("tr_" + hashlib.sha1((src + "|" + text).encode()).hexdigest() + ".txt")
    if key.exists():
        return key.read_text(encoding="utf-8") or None
    # the `de` contact email raises MyMemory's daily limit (public project contact, not a secret)
    url = ("https://api.mymemory.translated.net/get?de=paceline@cyclingcommons.com&langpair="
           + src + "|en&q=" + urllib.parse.quote(text))
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "CyclingCommons/wallonia-harvest"})
        with urllib.request.urlopen(req, timeout=30) as r:
            d = json.loads(r.read())
    except Exception:
        return None
    if d.get("responseStatus") != 200:
        return None
    out = ((d.get("responseData") or {}).get("translatedText") or "").strip()
    if not out or "WARNING" in out.upper() or "LIMIT" in out.upper() or out.lower() == text.lower():
        return None      # rate-limited / unusable → NOT cached, so it retries next run
    key.write_text(out, encoding="utf-8")
    return out


def _wikidata_entities(ids):
    """Batch wbgetentities → {qid: {image, sitelinks:{lang:title}, desc}}."""
    out = {}
    for i in range(0, len(ids), 40):
        url = (WD_API + "?action=wbgetentities&format=json&props=claims|sitelinks|descriptions&ids="
               + "|".join(ids[i:i + 40]))
        data = overpass.get_json(url)
        for qid, ent in ((data or {}).get("entities") or {}).items():
            img = None
            p18 = ent.get("claims", {}).get("P18")
            if p18:
                try:
                    img = p18[0]["mainsnak"]["datavalue"]["value"]
                except Exception:
                    img = None
            sl = {}
            for k, v in (ent.get("sitelinks") or {}).items():
                if k.endswith("wiki") and k[:-4] in ("en", "fr", "nl", "de"):
                    sl[k[:-4]] = v.get("title")
            desc = (ent.get("descriptions", {}).get("en") or {}).get("value")
            out[qid] = {"image": img, "sitelinks": sl, "desc": desc}
    return out


def _wikipedia_summary(lang, title):
    url = (f"https://{lang}.wikipedia.org/api/rest_v1/page/summary/"
           + urllib.parse.quote(title.replace(" ", "_")))
    d = overpass.get_json(url)
    return (d or {}).get("extract")


def _commons_imageinfo(filenames):
    """Batch Commons imageinfo → {filename: {thumb, license, artist}} (filename without 'File:')."""
    out = {}
    files = ["File:" + f for f in filenames]
    for i in range(0, len(files), 40):
        url = (COMMONS_API + "?action=query&format=json&prop=imageinfo&iiprop=extmetadata|url"
               + "&iiurlwidth=520&titles=" + urllib.parse.quote("|".join(files[i:i + 40])))
        d = overpass.get_json(url)
        for pg in ((d or {}).get("query", {}).get("pages", {}) or {}).values():
            ii = (pg.get("imageinfo") or [{}])[0]
            em = ii.get("extmetadata", {})
            title = pg.get("title", "").replace("File:", "")
            artist = re.sub("<[^>]+>", "", (em.get("Artist", {}) or {}).get("value", "")).strip()
            out[title] = {"thumb": ii.get("thumburl"),
                          "license": (em.get("LicenseShortName", {}) or {}).get("value", ""),
                          "artist": artist}
    return out


def _photo(file, info):
    enc = urllib.parse.quote(file.replace(" ", "_"))
    return {"sm": f"https://commons.wikimedia.org/wiki/Special:FilePath/{enc}?width=520",
            "lg": f"https://commons.wikimedia.org/wiki/Special:FilePath/{enc}?width=1400",
            "credit": info.get("artist") or "Wikimedia Commons",
            "license": info.get("license") or "Wikimedia Commons",
            "source": f"https://commons.wikimedia.org/wiki/File:{enc}"}


def enrich(features):
    """Add `desc` + `photo` (free-licence only) to features carrying wikidata/wikipedia tags. Returns count."""
    wd_ids = sorted({f.get("_tags", {}).get("wikidata") for f in features
                     if (f.get("_tags", {}).get("wikidata") or "").startswith("Q")})
    ents = _wikidata_entities(wd_ids) if wd_ids else {}
    imgfiles = sorted({ents[f["_tags"]["wikidata"]]["image"] for f in features
                       if f.get("_tags", {}).get("wikidata") in ents
                       and ents[f["_tags"]["wikidata"]]["image"]})
    imginfo = _commons_imageinfo(imgfiles) if imgfiles else {}
    n = 0
    for f in features:
        t, props = f.get("_tags", {}), f["properties"]
        ent = ents.get(t.get("wikidata"))
        # description (English): English Wikipedia → else translate a FR/NL/DE article → else Wikidata one-liner
        en_title = None
        if ent and ent["sitelinks"].get("en"):
            en_title = ent["sitelinks"]["en"]
        elif t.get("wikipedia", "").startswith("en:"):
            en_title = t["wikipedia"].split(":", 1)[1]
        desc = _wikipedia_summary("en", en_title) if en_title else None
        translated = False
        if not desc:
            for lang in ("fr", "nl", "de"):
                title = ent["sitelinks"].get(lang) if ent else None
                if not title and t.get("wikipedia", "").startswith(lang + ":"):
                    title = t["wikipedia"].split(":", 1)[1]
                if title:
                    src = _wikipedia_summary(lang, title)
                    tr = _translate(src, lang) if src else None
                    if tr:
                        desc, translated = tr, True
                        break
        if not desc and ent and ent.get("desc"):
            desc = ent["desc"][:1].upper() + ent["desc"][1:]
        if desc:
            desc = desc.strip()
            if len(desc) > 260:
                desc = desc[:257].rsplit(" ", 1)[0] + "…"
            props["desc"] = desc
            if translated:
                props["descTr"] = 1
        # photo: from Wikidata P18, only if Commons reports a free licence
        if ent and ent["image"] in imginfo:
            info = imginfo[ent["image"]]
            if info.get("thumb") and any(k in info["license"].lower() for k in FREE):
                props["photo"] = _photo(ent["image"], info)
        if props.get("desc") or props.get("photo"):
            n += 1
    return n
