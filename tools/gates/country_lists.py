#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
"""No hand-kept list of countries in code: a country is rows (country, country_extract).

Per file it flags a retired list name, four or more distinct Geofabrik extract
slugs, four or more distinct IANA zone ids, or an `all_<cc>` catalogue key.
Tests, fixtures, migrations (the 2026-10-05 seed holds the old list by design)
and prose are out of scope. Three slugs in one file are examples in a docstring.

Usage: tools/gates/country_lists.py
"""
from __future__ import annotations

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
SCAN = ("pipeline", "web/src", "web/assets", "web/templates", "web/translations", "web/config",
        "tools", "developers/docker", "Makefile")
SUFFIXES = {".py", ".php", ".js", ".mjs", ".cjs", ".twig", ".yaml", ".yml", ".sh"}
SKIP_PARTS = {"tests", "fixtures", "migrations", "out", "__pycache__", "node_modules", "vendor", "var", "lib", "gates"}
BANNED = re.compile(r"\b(COUNTRY_CONFIG|COUNTRY_L2|ONBOARDED_REGIONS|COUNTRY_BY_REGION|TZ_COUNTRY)\b")
EXTRACT = re.compile(r"\b(?:africa|asia|australia-oceania|central-america|europe|north-america|south-america)"
                     r"/[a-z][a-z-]*(?:/[a-z][a-z-]*)*")
ZONE = re.compile(r"\b(?:Africa|America|Antarctica|Asia|Atlantic|Australia|Europe|Indian|Pacific)/[A-Z][A-Za-z_]+")
ALL_KEY = re.compile(r"^\s+all_[a-z]{2}:\s*$", re.M)
LIMIT = 4


def findings(path: str, text: str) -> list[str]:
    out = [f"{path}: names {m.group(1)}" for m in BANNED.finditer(text)]
    extracts = sorted(set(EXTRACT.findall(text)))
    if len(extracts) >= LIMIT:
        out.append(f"{path}: {len(extracts)} Geofabrik extracts ({', '.join(extracts[:3])}, ...)")
    zones = sorted(set(ZONE.findall(text)))
    if len(zones) >= LIMIT:
        out.append(f"{path}: {len(zones)} IANA zones ({', '.join(zones[:3])}, ...)")
    if ALL_KEY.search(text):
        out.append(f"{path}: an all_<cc> catalogue key")
    return out


def _files(root: pathlib.Path):
    for entry in SCAN:
        p = root / entry
        if p.is_file():
            yield p
            continue
        for f in sorted(p.rglob("*")):
            if f.is_file() and f.suffix in SUFFIXES and not set(f.relative_to(root).parts) & SKIP_PARTS:
                yield f


def scan(root: pathlib.Path = ROOT) -> list[str]:
    out = []
    for f in _files(root):
        out += findings(str(f.relative_to(root)), f.read_text(encoding="utf-8", errors="ignore"))
    return out


def main() -> int:
    problems = scan()
    if problems:
        print("A country list crept back into the code; countries are rows (country, country_extract):", file=sys.stderr)
        for p in problems:
            print(f"  {p}", file=sys.stderr)
        return 1
    print("country lists: none in the code")
    return 0


if __name__ == "__main__":
    sys.exit(main())
