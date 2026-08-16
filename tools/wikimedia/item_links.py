#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Outbound links for wikidata-seeded items, as a reviewable artifact.

The `links` attribute's free first fill (docs/TODO.md, owner scope
2026-08-14): every wikidata-seeded item already has a Q-id in `source_ref`,
and Wikidata knows both its **official website** (P856) and its **Wikipedia
articles** in every language (sitelinks). That is exactly the two-level
shape the attribute stores - the official site is one destination, the
Wikipedia article is ONE destination whose urls are its language variants.

    python3 tools/wikimedia/item_links.py --dsn postgresql://cc:cc@localhost:5433/cyclingcommons

Writes tools/wikimedia/out/item-links.json as {qid: links[]}; a human
reviews and commits it, and `app:items:import-links` loads it (validating
against the same OutboundLinks caps the rest of the pipeline enforces).
Only https official sites are kept - the map renders these as <a href>.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import subprocess
import sys
import time
import urllib.parse
import urllib.request

UA = "CyclingCommons-item-links/1.0 (https://cyclingcommons.org; info@cyclingcommons.org)"
API = "https://www.wikidata.org/w/api.php"
LOCALES = ["en", "fr", "nl", "de", "es"]
OUT = pathlib.Path(__file__).resolve().parent / "out" / "item-links.json"


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


def refs_from_db(dsn: str) -> dict[str, str]:
    """{source_ref: qid}. Two ref shapes exist in the wild - the climb seeds
    store the bare Q-id, the I/J place seeds store `wikidata:Q...` - and the
    artifact must key by the STORED ref so the importer matches rows without
    guessing formats."""
    res = subprocess.run(
        ["psql", dsn, "-At", "-c", "SELECT DISTINCT source_ref FROM item WHERE source = 'wikidata' ORDER BY source_ref"],
        capture_output=True, text=True, check=True,
    )
    out: dict[str, str] = {}
    for ref in res.stdout.strip().splitlines():
        qid = ref.rsplit(":", 1)[-1]
        if qid.startswith("Q") and qid[1:].isdigit():
            out[ref] = qid
    return out


def entry_for(entity: dict) -> dict:
    """{web?, links?}: ONE storage slot per fact (owner 2026-08-16).

    The official website (P856) goes to the item's editable `web` attribute -
    the same slot the OSM harvest and the wizard's Website field use - never
    into `links`, or the drawer ends up with two fields for one fact and the
    rider can only edit one of them. `links` carries the OTHER destinations
    (the Wikipedia article with its language variants)."""
    out: dict = {}
    claims = (entity.get("claims") or {}).get("P856") or []
    for claim in claims:
        value = ((claim.get("mainsnak") or {}).get("datavalue") or {}).get("value")
        if isinstance(value, str) and value.startswith("https://"):
            out["web"] = value
            break
    sitelinks = entity.get("sitelinks") or {}
    urls = []
    for lc in LOCALES:
        sl = sitelinks.get(f"{lc}wiki")
        if sl and sl.get("title"):
            title = urllib.parse.quote(str(sl["title"]).replace(" ", "_"), safe="")
            urls.append({"url": f"https://{lc}.wikipedia.org/wiki/{title}", "locale": lc})
    if urls:
        out["links"] = [{"label": "Wikipedia", "urls": urls}]
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dsn", required=True)
    args = ap.parse_args()

    refs = refs_from_db(args.dsn)
    by_qid: dict[str, list[str]] = {}
    for ref, qid in refs.items():
        by_qid.setdefault(qid, []).append(ref)
    qids = sorted(by_qid)
    artifact: dict[str, list] = {}
    for i in range(0, len(qids), 50):
        chunk = qids[i : i + 50]
        url = API + "?" + urllib.parse.urlencode({
            "action": "wbgetentities", "ids": "|".join(chunk),
            "props": "sitelinks|claims", "format": "json",
        })
        for qid, entity in (_get(url).get("entities") or {}).items():
            found = entry_for(entity)
            if found:
                for ref in by_qid.get(qid, []):
                    artifact[ref] = found
        print(f"{min(i + 50, len(qids))}/{len(qids)}", file=sys.stderr)
        time.sleep(1)

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(artifact, ensure_ascii=False, indent=1, sort_keys=True) + "\n", encoding="utf-8")
    print(f"wrote {OUT} - links for {len(artifact)} of {len(qids)} items", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
