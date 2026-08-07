#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Find a *verifiably free* Wikimedia Commons photo for a named place.

The point of this tool is the licence check, not the search. It is easy to
lift an image URL off Wikidata and call it done; that produces a photo on the
map with a credit line nobody verified and a licence nobody read. So:

  1. resolve the name to a Wikidata item (wbsearchentities, then the item's
     P31 is printed so a human can confirm it is the right kind of thing),
  2. take P18 (image) from that item - nothing else, no guessing from
     categories or from the article's lead image,
  3. ask the *Commons* API for that exact file's `extmetadata`: the licence
     short name, the licence URL, the artist, and Commons' own
     machine-readable `NonFreeLicense` / `Restrictions` flags,
  4. accept ONLY a licence in FREE_LICENCES, which is the intersection of
     "free enough for the Commons dataset" and "a deed URL that
     `web/assets/map/util.js`'s ccUrl() already maps". Anything else is
     reported as skipped, with the licence it actually carries.

Output is JSON on stdout, one object per requested name, plus a human summary
on stderr. Nothing is written to the database or to any seed file: a person
reads the result and decides. Credit strings come from Commons verbatim -
never from the filename.

Usage:
    python3 tools/wikimedia/commons_photo.py "Furka Pass" "Grimsel Pass"
    python3 tools/wikimedia/commons_photo.py --qid Q666628
    python3 tools/wikimedia/commons_photo.py --file "Furkapass.jpg"

Requires network access to wikidata.org and commons.wikimedia.org.
"""

from __future__ import annotations

import argparse
import html
import json
import re
import sys
import time
import urllib.parse
import urllib.request

UA = "CyclingCommons-photo-check/1.0 (https://cyclingcommons.org; info@cyclingcommons.org)"

# Licence short names we accept, mapped to the exact string the catalogue
# stores. The keys are what Commons' extmetadata LicenseShortName reports
# (lower-cased); the values must stay inside ccUrl()'s table in
# web/assets/map/util.js, or the drawer's licence link falls back to the
# generic Commons licensing page.
FREE_LICENCES = {
    "cc0": "CC0",
    "public domain": "Public domain",
    "cc by 4.0": "CC BY 4.0",
    "cc by 3.0": "CC BY 3.0",
    "cc by 2.0": "CC BY 2.0",
    "cc by-sa 4.0": "CC BY-SA 4.0",
    "cc by-sa 3.0": "CC BY-SA 3.0",
    "cc by-sa 2.5": "CC BY-SA 2.5",
    "cc by-sa 2.0": "CC BY-SA 2.0",
}


def _get(url: str) -> dict:
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.load(resp)


def _strip_html(value: str) -> str:
    """Commons returns Artist as a rendered HTML fragment (often a user link)."""
    text = re.sub(r"<[^>]+>", "", value)
    return html.unescape(text).strip()


def _commons_user(artist_html: str) -> str | None:
    """The Commons username behind the artist link, when the artist IS a link."""
    m = re.search(r"/wiki/User:([^\"'#?]+)", artist_html)
    return urllib.parse.unquote(m.group(1)).replace("_", " ") if m else None


# Commons renders this sentence into Artist for old uploads that never carried
# a machine-readable author. It is a description of an absence, not a credit,
# and printing it under a photo on the map would be absurd. Where it names a
# user we credit that user; where it does not, the file is unusable, because
# CC BY-SA without a name to attribute cannot be complied with.
_NO_AUTHOR_BOILERPLATE = re.compile(r"no machine-readable author provided", re.I)


def credit_from(meta: dict) -> tuple[str | None, str | None]:
    """(credit line, why it is unusable). Never derived from the filename."""
    artist = meta["artist"]
    if artist and not _NO_AUTHOR_BOILERPLATE.search(artist):
        return artist, None
    if meta["user"]:
        return meta["user"], None
    if artist:
        return None, "Commons states no author, only its own no-author boilerplate"
    return None, "Commons states no author - a credit line would have to be invented"


def resolve_qid(name: str) -> tuple[str | None, str | None]:
    """Name -> (Q-id, its English description) via Wikidata search."""
    url = (
        "https://www.wikidata.org/w/api.php?action=wbsearchentities&format=json"
        f"&language=en&limit=5&search={urllib.parse.quote(name)}"
    )
    hits = _get(url).get("search", [])
    if not hits:
        return None, None
    return hits[0]["id"], hits[0].get("description")


def image_of(qid: str) -> str | None:
    """The item's P18, or None. Deliberately only P18."""
    url = (
        "https://www.wikidata.org/w/api.php?action=wbgetclaims&format=json"
        f"&property=P18&entity={qid}"
    )
    claims = _get(url).get("claims", {}).get("P18", [])
    for claim in claims:
        value = claim.get("mainsnak", {}).get("datavalue", {}).get("value")
        if isinstance(value, str) and value:
            return value
    return None


def licence_of(filename: str) -> dict:
    """Commons' own metadata for one file. Never inferred from anything else."""
    url = (
        "https://commons.wikimedia.org/w/api.php?action=query&format=json"
        "&prop=imageinfo&iiprop=extmetadata|url|size"
        f"&titles={urllib.parse.quote('File:' + filename)}"
    )
    pages = _get(url).get("query", {}).get("pages", {})
    page = next(iter(pages.values()), {})
    info = (page.get("imageinfo") or [{}])[0]
    meta = info.get("extmetadata", {})

    def field(key: str) -> str:
        return str(meta.get(key, {}).get("value", "") or "")

    artist_html = field("Artist")
    return {
        "exists": "missing" not in page,
        "licence_short": field("LicenseShortName"),
        "licence_url": field("LicenseUrl"),
        "artist_html": artist_html,
        "artist": _strip_html(artist_html),
        "user": _commons_user(artist_html),
        "credit_line": _strip_html(field("Credit")),
        "attribution": _strip_html(field("Attribution")),
        # Commons' own machine-readable "this is NOT free" markers. Either one
        # being set is a hard skip regardless of what the short name says.
        "non_free": field("NonFreeLicense"),
        "restrictions": field("Restrictions"),
        "width": info.get("width"),
        "height": info.get("height"),
    }


def assess(name: str, qid: str | None = None, filename: str | None = None) -> dict:
    out: dict = {"name": name, "qid": qid, "file": filename, "usable": False}

    if filename is None:
        if qid is None:
            qid, description = resolve_qid(name)
            out["qid"] = qid
            out["wikidata_description"] = description
        if qid is None:
            out["skipped"] = "no Wikidata item matched that name"
            return out
        filename = image_of(qid)
        out["file"] = filename
    if not filename:
        out["skipped"] = "the Wikidata item has no P18 image"
        return out

    meta = licence_of(filename)
    out["licence_reported"] = meta["licence_short"]
    out["artist"] = meta["artist"]
    out["commons_user"] = meta["user"]

    if not meta["exists"]:
        out["skipped"] = "Commons has no such file"
        return out
    if meta["non_free"] or meta["restrictions"]:
        out["skipped"] = (
            "Commons flags it as non-free or restricted "
            f"(NonFreeLicense={meta['non_free']!r}, Restrictions={meta['restrictions']!r})"
        )
        return out

    canonical = FREE_LICENCES.get(meta["licence_short"].strip().lower())
    if canonical is None:
        out["skipped"] = f"licence {meta['licence_short']!r} is not on the accepted list"
        return out

    credit, why_not = credit_from(meta)
    if credit is None:
        out["skipped"] = why_not
        return out

    out["usable"] = True
    out["licence"] = canonical
    out["credit"] = credit
    out["size"] = f"{meta['width']}x{meta['height']}"
    # Exactly the arguments SeedManualCatalogCommand::wc() takes, in order.
    out["wc_args"] = [filename, credit, meta["user"], canonical]
    return out


def category_of(qid: str) -> str | None:
    """The item's Commons category (P373), for the --candidates fallback."""
    url = (
        "https://www.wikidata.org/w/api.php?action=wbgetclaims&format=json"
        f"&property=P373&entity={qid}"
    )
    claims = _get(url).get("claims", {}).get("P373", [])
    for claim in claims:
        value = claim.get("mainsnak", {}).get("datavalue", {}).get("value")
        if isinstance(value, str) and value:
            return value
    return None


def candidates(name: str, limit: int = 12) -> dict:
    """Every usable photo in the subject's own Commons category.

    The fallback for when P18 is free but has no attributable author, which is
    common for old Swiss pass photographs. A category is still the subject's
    own set of images - it is not a keyword search across Commons - but the
    choice between them is a human's: only a person can say whether a photo
    shows the climb rather than the car park at the top.
    """
    qid, description = resolve_qid(name)
    out: dict = {"name": name, "qid": qid, "wikidata_description": description, "candidates": []}
    if qid is None:
        out["skipped"] = "no Wikidata item matched that name"
        return out

    category = category_of(qid)
    out["category"] = category
    if category is None:
        out["skipped"] = "the Wikidata item has no Commons category (P373)"
        return out

    url = (
        "https://commons.wikimedia.org/w/api.php?action=query&format=json"
        "&list=categorymembers&cmtype=file&cmlimit=100"
        f"&cmtitle={urllib.parse.quote('Category:' + category)}"
    )
    members = _get(url).get("query", {}).get("categorymembers", [])
    for member in members:
        filename = member["title"].removeprefix("File:")
        assessed = assess(filename, filename=filename)
        if assessed["usable"]:
            out["candidates"].append(assessed)
        if len(out["candidates"]) >= limit:
            break
        time.sleep(0.2)
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("names", nargs="*", help="place names to look up on Wikidata")
    ap.add_argument("--qid", action="append", default=[], help="skip the search, use this Wikidata item")
    ap.add_argument("--file", action="append", default=[], help="skip Wikidata, check this Commons filename")
    ap.add_argument(
        "--candidates",
        action="store_true",
        help="list every usable photo in the subject's Commons category instead of just P18 "
        "(use when P18 is free but has no attributable author; a human picks)",
    )
    args = ap.parse_args()

    if args.candidates:
        results = []
        for name in args.names:
            found = candidates(name)
            results.append(found)
            if found.get("skipped"):
                print(f"  SKIP  {name}: {found['skipped']}", file=sys.stderr)
            else:
                print(f"  {name} (Category:{found['category']})", file=sys.stderr)
                for c in found["candidates"]:
                    print(f"    - {c['file']} · {c['licence']} · {c['credit']} · {c['size']}", file=sys.stderr)
        json.dump(results, sys.stdout, indent=2, ensure_ascii=False)
        print()
        return 0

    jobs: list[dict] = []
    jobs += [{"name": n} for n in args.names]
    jobs += [{"name": q, "qid": q} for q in args.qid]
    jobs += [{"name": f, "filename": f} for f in args.file]
    if not jobs:
        ap.error("give at least one name, --qid or --file")

    results = []
    for i, job in enumerate(jobs):
        if i:
            time.sleep(0.4)  # be a polite API client
        try:
            result = assess(**job)
        except Exception as exc:  # noqa: BLE001 - a lookup failure is a result, not a crash
            result = {"name": job["name"], "usable": False, "skipped": f"lookup failed: {exc}"}
        results.append(result)
        if result["usable"]:
            print(
                f"  OK    {result['name']}: {result['file']} - {result['licence']} - {result['credit']}",
                file=sys.stderr,
            )
        else:
            print(f"  SKIP  {result['name']}: {result['skipped']}", file=sys.stderr)

    usable = sum(1 for r in results if r["usable"])
    print(f"\n{usable} usable, {len(results) - usable} skipped.", file=sys.stderr)
    json.dump(results, sys.stdout, indent=2, ensure_ascii=False)
    print()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
