# SPDX-License-Identifier: AGPL-3.0-only
"""python -m onboarding.plan <CC>: decide a country's regions, write the plan, print it.

Writes country (status planned) + country_plan_region + country_extract in one
transaction; never region rows, so a rejected plan costs nothing. Refuses a
seeded or live country: its slugs are frozen identity."""
from __future__ import annotations

import argparse
import json
import os
import re
import statistics
import sys
from dataclasses import dataclass, field

import psycopg
from psycopg.types.json import Jsonb

from divisions import config
from divisions.probe_areas import bbox_union, geom_bounds
from onboarding.extracts import GEOFABRIK_INDEX, ExtractUndecided, choose_extract, load_index
from onboarding.labels import LOCALES, country_phrases, region_labels
from onboarding.levels import LEVEL_PROBE_ORDER, NoLevelFits, choose_level
from onboarding.neighbours import country_status, neighbours
from onboarding.overture import Division, Overture
from onboarding.slugs import SlugError, assign


class PlanError(ValueError):
    """The plan cannot be built or written; the message is the one-line reason."""


@dataclass
class PlanRegion:
    slug: str
    iso_code: str | None
    name: str
    labels: dict[str, str]
    fallbacks: list[str]
    admin_level: int
    area_km2: float
    geometry: dict


@dataclass
class Plan:
    cc: str
    name: str
    subtype: str
    bbox: list[float]
    labels: dict[str, str]
    release: str
    extract: str
    regions: list[PlanRegion]
    skipped: list[str] = field(default_factory=list)
    neighbours: list[str] = field(default_factory=list)


def _key(d: Division, subtype: str, cc: str) -> str | None:
    if subtype == "country":
        return cc
    if subtype == "region":
        return d.iso
    return d.primary


def build_plan(cc: str, release: str, level_rows: dict[str, list[Division]], outline: Division,
               taken: set[str], index: dict, *, level: str | None = None,
               slug_overrides: dict[str, str] | None = None, extract: str | None = None,
               extract_check: bool = True) -> Plan:
    medians = {st: (statistics.median(d.area_km2 for d in rows) if (rows := level_rows.get(st)) else None)
               for st in LEVEL_PROBE_ORDER}
    subtype = choose_level(outline.area_km2, medians, level)
    chosen = [outline] if subtype == "country" else level_rows.get(subtype, [])
    if not chosen:
        raise PlanError(f"Overture has no {subtype} land rows for {cc}")

    keyed: list[tuple[str, Division]] = []
    skipped: list[str] = []
    for d in chosen:
        key = _key(d, subtype, cc)
        if not key:
            skipped.append(f"{d.primary} (no ISO 3166-2 code)")
            continue
        keyed.append((key, d))
    seen: dict[str, int] = {}
    for key, _d in keyed:
        seen[key] = seen.get(key, 0) + 1
    if dupes := sorted(k for k, n in seen.items() if n > 1):
        raise PlanError(f"Overture returned more than one land row for {', '.join(dupes)}; inspect the release")

    level_two = subtype == "country"
    # Subdivisions keep native-name slugs; the level-2 outline takes the English one (`denmark`, like `belgium`).
    rows = [(key, d.common.get("en") or d.primary, d.primary) if d is outline else (key, d.primary, d.common.get("en"))
            for key, d in keyed]
    if not level_two:
        rows.append((cc, outline.common.get("en") or outline.primary, outline.primary))
    slugs = assign(rows, cc, taken, slug_overrides or {})

    admin = config.SUBTYPE_ADMIN_LEVEL[subtype]
    regions = []
    for key, d in keyed + ([] if level_two else [(cc, outline)]):
        labels, fallbacks = region_labels(d.common, d.primary)
        regions.append(PlanRegion(
            slug=slugs[key],
            iso_code=None if subtype == "county" and d is not outline else key,
            name=labels["en"],
            labels=labels, fallbacks=fallbacks,
            admin_level=2 if d is outline else admin,
            area_km2=round(d.area_km2, 1),
            geometry=d.geometry,
        ))

    outline_labels, _fallbacks = region_labels(outline.common, outline.primary)
    operating = [r for r in regions if r.admin_level == admin] or regions
    if extract and not extract_check:
        chosen_extract = extract
    else:
        chosen_extract = choose_extract(index, cc, extract)
    return Plan(
        cc=cc,
        name=outline_labels["en"],
        subtype=subtype,
        bbox=bbox_union([geom_bounds(r.geometry) for r in operating]),
        labels=country_phrases(outline.common, outline.primary),
        release=release,
        extract=chosen_extract,
        regions=regions,
        skipped=skipped,
    )


def write_plan(conn, plan: Plan) -> None:
    with conn.transaction():
        row = conn.execute("SELECT status FROM country WHERE code = %s FOR UPDATE", (plan.cc,)).fetchone()
        if row and row[0] in ("seeded", "live"):
            raise PlanError(f"{plan.cc} is {row[0]}: its slugs are frozen identity. Nothing written.")
        owner = conn.execute("SELECT country_code FROM country_extract WHERE slug = %s", (plan.extract,)).fetchone()
        if owner and owner[0].strip() != plan.cc:
            raise PlanError(f"{plan.extract} already belongs to {owner[0].strip()}; rerun with --extract <slug>")
        conn.execute(
            """INSERT INTO country (code, name, subtype, bbox, labels, status, overture_release, planned_at)
               VALUES (%s, %s, %s, %s, %s, 'planned', %s, now())
               ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, subtype = EXCLUDED.subtype,
                 bbox = EXCLUDED.bbox, labels = EXCLUDED.labels, status = 'planned',
                 overture_release = EXCLUDED.overture_release, planned_at = now(),
                 seeded_at = NULL, live_at = NULL""",
            (plan.cc, plan.name, plan.subtype, Jsonb(plan.bbox), Jsonb(plan.labels), plan.release),
        )
        conn.execute("DELETE FROM country_plan_region WHERE country_code = %s", (plan.cc,))
        conn.execute("DELETE FROM country_extract WHERE country_code = %s", (plan.cc,))
        conn.execute("INSERT INTO country_extract (slug, country_code) VALUES (%s, %s)", (plan.extract, plan.cc))
        for r in plan.regions:
            conn.execute(
                """INSERT INTO country_plan_region
                     (country_code, slug, iso_code, name, labels, fallback_locales, admin_level, area_km2, geom)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)))""",
                (plan.cc, r.slug, r.iso_code, r.name, Jsonb(r.labels), r.fallbacks, r.admin_level,
                 r.area_km2, json.dumps(r.geometry)),
            )


def plan_json(plan: Plan) -> dict:
    return {
        "country": plan.cc, "name": plan.name, "level": plan.subtype, "release": plan.release,
        "extract": plan.extract, "neighbours": plan.neighbours, "labels": plan.labels, "skipped": plan.skipped,
        "regions": [{"slug": r.slug, "iso_code": r.iso_code, "admin_level": r.admin_level, "area_km2": r.area_km2,
                     "labels": r.labels, "fallback_locales": r.fallbacks} for r in plan.regions],
    }


def render_table(plan: Plan) -> str:
    head = [
        f"Country     {plan.cc}  {plan.name}  (Overture {plan.release})",
        f"Level       {plan.subtype} (admin_level {config.SUBTYPE_ADMIN_LEVEL[plan.subtype]})",
        f"Extract     {plan.extract}",
        f"Neighbours  {', '.join(plan.neighbours) or 'none'}",
        f"Phrase      {' | '.join(f'{loc} {plan.labels[loc]}' for loc in LOCALES)}",
        "",
    ]
    cells = [["slug", "iso", "km²", *LOCALES]]
    for r in plan.regions:
        cells.append([r.slug, r.iso_code or "-", f"{r.area_km2:,.0f}",
                      *[r.labels[loc] + ("*" if loc in r.fallbacks else "") for loc in LOCALES]])
    widths = [max(len(row[i]) for row in cells) for i in range(len(cells[0]))]
    body = ["  ".join(c.ljust(w) for c, w in zip(row, widths)).rstrip() for row in cells]
    tail = ["", "* no Overture name in that language; the native name stands in (app:country:label fixes one)."]
    tail += [f"skipped: {s}" for s in plan.skipped]
    return "\n".join(head + body + tail)


def _overrides(pairs: list[str]) -> dict[str, str]:
    out = {}
    for pair in pairs:
        key, sep, slug = pair.partition("=")
        if not sep or not key or not slug:
            raise PlanError(f"--slug {pair}: expected KEY=slug, e.g. --slug DK-84=hovedstaden")
        out[key] = slug
    return out


def main(argv=None, *, source=None, connect=psycopg.connect, index_loader=load_index) -> int:
    ap = argparse.ArgumentParser(description="Plan a country's onboarding (writes country_plan_region, never region)")
    ap.add_argument("country", help="ISO 3166-1 alpha-2, e.g. DK")
    ap.add_argument("--level", help="Overture subtype to seed at, overriding the level rule")
    ap.add_argument("--slug", action="append", default=[], help="KEY=slug override (KEY: ISO code, or native name for counties)")
    ap.add_argument("--extract", help="Geofabrik extract, overriding the index rule, e.g. europe/denmark")
    ap.add_argument("--release", default=config.OVERTURE_RELEASE, help="Overture release")
    ap.add_argument("--index", default=GEOFABRIK_INDEX, help="Geofabrik index URL or file")
    ap.add_argument("--json", action="store_true", help="print the plan as JSON instead of a table")
    args = ap.parse_args(argv)
    cc = args.country.strip().upper()
    if not re.fullmatch(r"[A-Z]{2}", cc):
        print(f"{args.country!r} is not an ISO 3166-1 alpha-2 code", file=sys.stderr)
        return 2

    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    with connect(dsn) as conn:
        status = country_status(conn, cc)
        if status in ("seeded", "live"):
            print(f"{cc} is {status}: its slugs are frozen identity, a new plan would orphan them. Nothing written.",
                  file=sys.stderr)
            return 1
        overture = source or Overture(args.release)
        try:
            overrides = _overrides(args.slug)
            box = overture.country_box(cc)
            outline_rows = overture.divisions(cc, "country", box)
            if len(outline_rows) != 1:
                raise PlanError(f"Overture returned {len(outline_rows)} country rows for {cc}, expected exactly one")
            level_rows = {st: overture.divisions(cc, st, box) for st in LEVEL_PROBE_ORDER}
            if args.level and args.level not in level_rows and args.level != "country":
                level_rows[args.level] = overture.divisions(cc, args.level, box)
            taken = {slug for (slug,) in conn.execute("SELECT slug FROM region WHERE slug IS NOT NULL")}
            plan = build_plan(cc, args.release, level_rows, outline_rows[0], taken, index_loader(args.index),
                              level=args.level, slug_overrides=overrides, extract=args.extract)
            write_plan(conn, plan)
            plan.neighbours = neighbours(conn, cc, source="plan")
        except (PlanError, NoLevelFits, ExtractUndecided, SlugError, LookupError, ValueError) as exc:
            print(str(exc), file=sys.stderr)
            return 1
    print(json.dumps(plan_json(plan), ensure_ascii=False, indent=1) if args.json else render_table(plan))
    return 0


if __name__ == "__main__":
    sys.exit(main())
