#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Wikipedia lead extracts for region pages, per locale, as a reviewable artifact.

A region page with three verified items has little to say; a short
encyclopedic lead gives it context (owner idea 2026-08-14). The licence weigh
decided the shape (2026-08-16):

- **Text comes from Wikipedia** (the REST summary endpoint's plain-text
  `extract`), taken VERBATIM - an unedited excerpt keeps the reuse simple:
  attribution plus a link plus the licence name, which the page renders under
  the text. Wikipedia text is **CC BY-SA 4.0**; the artifact stores the
  article URL and title so the attribution can never drift from the text.
- **Wikidata descriptions were rejected** (CC0 but one terse line - "province
  of the Netherlands" says nothing a rider wants), and **images are skipped
  deliberately**: the region page already carries its silhouette, and summary
  thumbnails have per-file licences this pipeline does not verify.
- **BUILD time, never runtime**: this script writes
  `tools/wikimedia/out/region-context.json`, a human reviews and commits it,
  and `app:regions:import-context` loads it. The page keeps zero external
  dependencies.

Regions are matched to Wikidata by their ISO 3166-2 code (property P300) -
the one machine-readable key the region table already carries - then to each
language's Wikipedia via sitelinks. A region without an ISO code, or a
language without an article, is simply absent from the artifact; the page
falls back (locale -> en -> nothing) rather than inventing text.

    python3 tools/wikimedia/region_context.py --dsn postgresql://cc:cc@localhost:5433/cyclingcommons
    python3 tools/wikimedia/region_context.py --iso BE-WLX --iso NL-NH   # a subset, for review
"""

from __future__ import annotations

import argparse
import json
import pathlib
import sys
import time
import urllib.parse
import urllib.request

UA = "CyclingCommons-region-context/1.0 (https://cyclingcommons.org; info@cyclingcommons.org)"
SPARQL = "https://query.wikidata.org/sparql"
API = "https://www.wikidata.org/w/api.php"
LOCALES = ["en", "fr", "nl", "de", "es"]
OUT = pathlib.Path(__file__).resolve().parent / "out" / "region-context.json"

# Reviewed exceptions: ISO codes whose P300/P297 sits on a Wikidata item with
# no sitelinks (usually a data-modelling duplicate), mapped to the item whose
# articles a reader actually wants. ES-IB's P300 lives on Q107356467 (empty);
# the Balearic Islands articles live on Q5765.
QID_OVERRIDES = {"ES-IB": "Q5765"}

# One query for every ISO code at once: P300 is unique per item, and asking
# region-by-region is 271 round trips against a rate-limited endpoint.
QUERY = """
SELECT ?iso ?item %(site_vars)s WHERE {
  VALUES ?iso { %(isos)s }
  # P300 = ISO 3166-2 (subdivisions); P297 = ISO 3166-1 alpha-2 (countries).
  # The country-level rows (slug 'slovenia', iso 'SI') carry the bare country
  # code, which only P297 knows - matching P300 alone left every country page
  # without its lead (owner-reported 2026-08-16).
  ?item wdt:P300|wdt:P297 ?iso.
  %(site_optionals)s
}
"""


def _get(url: str, attempts: int = 4) -> dict:
    last: Exception | None = None
    for attempt in range(attempts):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json"})
            with urllib.request.urlopen(req, timeout=120) as resp:
                return json.load(resp)
        except Exception as exc:  # noqa: BLE001 - retried below
            last = exc
            if attempt < attempts - 1:
                time.sleep(5 * (attempt + 1))
    raise RuntimeError(f"gave up after {attempts} attempts: {last}")


def sitelinks_by_iso(isos: list[str]) -> dict[str, dict[str, str]]:
    """{iso: {locale: article title}} via one SPARQL query per 100 codes."""
    out: dict[str, dict[str, str]] = {}
    for i in range(0, len(isos), 100):
        chunk = isos[i : i + 100]
        site_vars = " ".join(f"?{lc}" for lc in LOCALES)
        site_optionals = "\n  ".join(
            f'OPTIONAL {{ ?a_{lc} schema:about ?item; schema:isPartOf <https://{lc}.wikipedia.org/>; schema:name ?{lc}. }}'
            for lc in LOCALES
        )
        q = QUERY % {
            "isos": " ".join(f'"{iso}"' for iso in chunk),
            "site_vars": site_vars,
            "site_optionals": site_optionals,
        }
        url = SPARQL + "?" + urllib.parse.urlencode({"query": q, "format": "json"})
        for row in _get(url)["results"]["bindings"]:
            iso = row["iso"]["value"]
            titles = {lc: row[lc]["value"] for lc in LOCALES if lc in row}
            if titles:
                out[iso] = titles
        time.sleep(1)
    # Reviewed overrides fill in via sitelinks on the named item.
    for iso, qid in QID_OVERRIDES.items():
        if iso in out or iso not in isos:
            continue
        url = API + "?" + urllib.parse.urlencode({
            "action": "wbgetentities", "ids": qid, "props": "sitelinks", "format": "json",
        })
        sl = ((_get(url).get("entities") or {}).get(qid) or {}).get("sitelinks") or {}
        titles = {lc: sl[f"{lc}wiki"]["title"] for lc in LOCALES if f"{lc}wiki" in sl}
        if titles:
            out[iso] = titles
    return out


def summary(locale: str, title: str) -> dict | None:
    """The REST summary: plain-text lead + canonical URL. None on any miss."""
    url = f"https://{locale}.wikipedia.org/api/rest_v1/page/summary/" + urllib.parse.quote(title.replace(" ", "_"), safe="")
    try:
        data = _get(url, attempts = 2)
    except RuntimeError:
        return None
    extract = (data.get("extract") or "").strip()
    page_url = ((data.get("content_urls") or {}).get("desktop") or {}).get("page")
    if not extract or not page_url:
        return None
    # Disambiguation pages have an extract too, and it is never the region.
    if data.get("type") != "standard":
        return None
    return {"title": data.get("title") or title, "extract": extract, "url": page_url}


def regions_from_db(dsn: str) -> list[tuple[str, str]]:
    import subprocess

    sql = "SELECT slug, iso_code FROM region WHERE iso_code IS NOT NULL ORDER BY slug"
    res = subprocess.run(
        ["psql", dsn, "-At", "-F", "\t", "-c", sql],
        capture_output=True, text=True, check=True,
    )
    return [tuple(line.split("\t")) for line in res.stdout.strip().splitlines() if "\t" in line]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dsn", help="postgres DSN to read (slug, iso_code) from")
    ap.add_argument("--iso", action="append", default=[], help="harvest only these ISO 3166-2 codes")
    args = ap.parse_args()

    if not args.dsn:
        ap.error("need --dsn (slugs come from the region table; --iso only filters)")
    pairs = regions_from_db(args.dsn)
    if args.iso:
        wanted = {i.upper() for i in args.iso}
        pairs = [(slug, iso) for slug, iso in pairs if iso.upper() in wanted]

    titles = sitelinks_by_iso([iso for _, iso in pairs])
    artifact: dict[str, dict] = {}
    misses: list[str] = []
    for slug, iso in pairs:
        found = titles.get(iso)
        if not found:
            misses.append(iso)
            continue
        entry: dict[str, dict] = {}
        for lc in LOCALES:
            if lc not in found:
                continue
            s = summary(lc, found[lc])
            if s:
                entry[lc] = s
            time.sleep(0.2)
        if entry:
            artifact[slug] = entry
        print(f"{slug} ({iso}): {', '.join(sorted(entry)) or 'nothing'}", file=sys.stderr)

    OUT.parent.mkdir(parents=True, exist_ok=True)
    # A PARTIAL run (--iso) merges into the existing artifact instead of
    # replacing it: re-harvesting one region must never silently discard the
    # other 250 committed entries - which is exactly what the first ES-IB
    # re-run did. A full run still replaces wholesale.
    if args.iso and OUT.exists():
        existing = json.loads(OUT.read_text(encoding="utf-8"))
        existing.update(artifact)
        artifact = existing
    OUT.write_text(json.dumps(artifact, ensure_ascii=False, indent=1, sort_keys=True) + "\n", encoding="utf-8")
    print(f"wrote {OUT} - {len(artifact)} regions, {len(misses)} without a Wikidata match"
          + (f" ({', '.join(misses[:10])}{'…' if len(misses) > 10 else ''})" if misses else ""), file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
