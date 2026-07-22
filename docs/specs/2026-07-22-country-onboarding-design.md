# Repeatable worldwide country/state onboarding — design (working spec)

Status: **executed** (2026-07-22) on `symfony-base` (NOT pushed). First run:
**the Netherlands** (full onboarding — coverage + region tessellation), live
end-to-end in dev: 12 province `region` rows seeded, `europe/netherlands`
coverage harvested (34,751 POIs), scope selector + coverage counts + search
verified serving NL (Noord-Holland region scope a correct subset of whole-NL;
BE unregressed). The first non-BE coverage run surfaced a real bug —
Geofabrik extracts overlap at borders (203 shared OSM refs BE↔NL) violating the
global `coverage_poi` `UNIQUE(ref, letter)` — root-caused and fixed (upsert +
authoritative region⇒cc; see `pipeline/coverage/load.py`).
**Second run: Germany** (2026-07-22) — 16 Bundesländer (subtype=region/ISO 3166-2)
seeded, `europe/germany` coverage harvested (**317,887 POIs**; 99.9% region-stamped,
0 null country_code). First continental-scale run: `coverage_poi` now 375,078 rows
/ 360 MB across BE/NL/DE with **0 region⇒cc violations**, validating the provenance
normalization (`src_region_id`/`region_id`, coverage-provider.md §2) and the
border-overlap dedup at scale (DE borders both BE and NL; shared refs reassigned
last-writer-wins, counts stayed self-consistent). Browser-verified: All-Germany
scope renders per-country clusters (0 cross-border mixed bubbles into NL/BE) + the
country dim mask; 16 localized region labels (Bavaria/Saxony/… exonyms) render.
This run also exposed + fixed a Geofabrik **mirror-lag** flaw in the PBF md5 check
(a valid 4.8 GB download failed because `.md5` and `.pbf` came from mirrors at
different sync states; `run.py::fetch_pbf` now pins the verify to the resolved
mirror URL + retries). Generalizes the one-off
"add a country" path in `tools/divisions/README.md` into a fixed, largely
automated sequence that works for any country **or** a single state of a big
country, worldwide. Supersedes the informal runbook it extends; does **not**
change the tessellation invariant or the region model from
[2026-07-19-region-scoping-design.md](2026-07-19-region-scoping-design.md)
(that spec's Phase 5 "worldwide rollout" is what this operationalizes).

Owner decisions folded in (2026-07-22, brainstorming session):

1. NL gets **full onboarding**: coverage data **and** region tessellation, so
   it is a first-class country like Belgium.
2. NL tessellates into its **12 official provinces** (Overture `subtype=region`,
   ISO 3166-2 `NL-DR … NL-ZH`). Official ISO-coded divisions are the region
   atom; the ~17k km² calibration band is advisory, not a gate.
3. **Grouping into synthetic macro-regions is a fallback only** — used when no
   official admin level of reasonable size exists. Small official regions are
   fine: **moderation composes upward** (one moderator covers 2–4 provinces).
4. The deliverable is a **repeatable process**, not an NL patch. NL is its first
   execution.
5. Automation level **A**: a scaffolder command that *emits config +
   translation stubs for human review* (it never writes `config.py`,
   translation files, or the DB), plus one end-to-end playbook. The two genuine
   human judgments — operating-level choice and exonym correctness — stay in
   review.
6. **State-level onboarding is in v1**: `--only=ISO,…` seeds just a subset of a
   country's subdivisions (demand-driven, e.g. one US state first).
7. Run everything for NL this session; the dev-DB seed (step 5) is confirmed
   before it executes (dev-DB writes are approval-gated).

---

## 1. The onboarding spine

A fixed 8-step sequence, identical for every country or state. Automated steps
are **bold**; steps needing a human judgment are marked ⚑.

| # | Step | Tool | Notes |
|---|------|------|-------|
| 1 | ⚑ Choose operating level | scaffolder `--probe-areas` advises | §3 rule |
| 2 | **Scaffold config + translation stubs** | `app:region:scaffold <CC>` | reads World bundle; emits to a review dir |
| 3 | ⚑ Review slugs / exonyms / level; merge | human | merges the config block + translation patch |
| 4 | **Export Overture geojson** | `python3 -m divisions.export_divisions --country CC` | already generic |
| 5 | Import → seed `Region` rows | `app:catalog:import` | dev-DB write — confirm first |
| 6 | **Add Geofabrik region; run coverage** | `COVERAGE_REGIONS` + pipeline | regions independent; BE untouched |
| 7 | ⚑ Assign moderators to atoms | admin | optional; 2–4 provinces per moderator |
| 8 | Update specs / playbook | docs | keep specs in sync |

The playbook (in `tools/divisions/README.md`, cross-linked from the coverage and
region-scoping specs) documents this sequence with the exact commands and the
NL run as the worked example.

## 2. New component — `app:region:scaffold`

A Symfony console command in `web/src/World/Command/` (mirrors the existing
`app:world:import` house style; the World bundle is its data source).

```
app:region:scaffold <CC> [--subtype=region] [--only=ISO,ISO,…] [--probe-areas]
```

- `<CC>` — ISO 3166-1 alpha-2 country code (e.g. `NL`).
- `--subtype` — Overture operating level to target (`region` default; maps to
  World `Subdivision.level` — `region` ≈ top-level `level=1`). Written verbatim
  into the emitted config block.
- `--only=ISO,…` — restrict to an explicit ISO 3166-2 list, for **state-level /
  demand-driven onboarding** (seed one state of a large country now, the rest
  later). Omitted = the country's whole operating level.
- `--probe-areas` — additionally query Overture `division_area` for the target
  subtype and report geodesic km² per subdivision vs the ~17k band, so the level
  choice in step 1 is evidence-based. Reuses the exporter's existing
  `divisions.export_divisions.geodesic_area_km2`. Off by default (no network).

**Data source:** `world_subdivision` rows for `<CC>` at the chosen level, giving
`code` (ISO 3166-2), `name` (English, from sokil/isocodes), and `type`
("province"). The scaffolder **proposes** slugs by slugifying `name`; slug is
permanent identity (upsert-by-slug), so the review step (below) is where a human
**freezes the form**. Rule of thumb: use the established English exonym where
one genuinely exists (BE: `wallonia`, `flanders`), otherwise the native form
(NL: `noord-holland`, not `north-holland` — Dutch provinces are conventionally
referenced natively). The scaffolder also flags cross-country slug collisions
(e.g. Limburg exists in both BE and NL).

**Outputs** — written to `tools/divisions/out/scaffold/<cc>/` (a review
staging dir, git-ignored under the existing `out/` convention), **never** to the
live config / translation / DB:

- `config-block.py` — a ready-to-merge `COUNTRY_CONFIG["CC"]` literal: `subtype`,
  `slugs` (ISO→slug), `names` (English), and `bbox` (computed from subdivision
  extents when `--probe-areas`, else a `# TODO bbox` line).
- `translations.patch.yaml` — `region.<slug>.label` for each subdivision plus the
  `region.all_<cc>.label` country rung, in all four locales
  (`en/fr/nl/de`). Native name pre-filled; a non-native locale whose value the
  command can't confidently localize is emitted with a `# TODO exonym?` marker so
  the reviewer can't miss it.
- `areas.md` (with `--probe-areas`) — the per-subdivision area table and the
  level recommendation.

**Explicitly emits, never applies.** The reviewer (step 3) copies the config
block into `tools/divisions/config.py`, applies the translation patch into the
four `web/translations/messages.*.yaml` files, and fixes exonyms. This preserves
the two human judgments and keeps a clean diff to review.

**Plan refinements (2026-07-22, recorded per `coverage-provider.md` precedent —
the implementation refined these details; the plan's refinement governs):**

1. The scaffold staging dir is **`web/var/scaffold/<cc>/`** (container
   `/app/var/scaffold/<cc>/`), not `tools/divisions/out/scaffold/`: the app
   container only mounts `web/`, `web/var/` is gitignored and host-visible, and
   the probe writes there too so `--probe-areas` can read `probe.json`.
2. The Overture area probe is a **separate Python tool**
   `tools/divisions/probe_areas.py` (`make region-probe`), not a flag on the PHP
   command — the app container has no DuckDB. `--probe-areas` on the scaffolder
   *reads* the probe's `probe.json` and fails loud when it is missing.
3. sokil `php-isocodes-db-only` ships English msgids only, so **every** emitted
   translation label line carries `# TODO exonym?` (not only non-native locales).
4. The scaffolder reads the World bundle, which lists **18** NL level-1
   subdivisions (12 provinces + Aruba/Curaçao/Sint Maarten + the BES islands);
   the human review step curates to the 12 mainland provinces. The scaffolder
   flags all four cross-country name collisions (Limburg + the three BES
   islands) automatically — this is the review surface working as designed.
5. **Coverage regions are NOT fully independent at borders** (contra the
   "regions are independent" invariant): Geofabrik regional extracts overlap in
   a border buffer, so one OSM entity appears in adjacent extracts with the same
   `(ref, letter)`. The global `coverage_poi UNIQUE(ref, letter)` + the
   per-`src_region` delete-then-insert meant the second bordering region to load
   crashed on the shared entity. `load_region` now upserts
   (`ON CONFLICT (ref, letter) DO UPDATE`, last-writer-wins) and takes
   `country_code` authoritatively from the geometric region — so a border entity
   the neighbouring extract mis-stamped reads its true country. Every future
   country that borders an already-loaded one exercises this path; it is now
   covered by `test_load_region_upserts_shared_border_entity_across_regions`.

## 3. Operating-level selection rule

Codified in the playbook and surfaced by `--probe-areas`:

> Seed a country at the **official administrative level whose subdivisions are of
> reasonable riding size**, preferring **legibility and stable ISO 3166-2
> identity** over an exact area match. The ~17k km² band (Wallonia/Flanders) is
> **advisory**: Brussels (~162 km²) already sits far under it deliberately. A
> small official region is acceptable because **moderation composes upward** —
> one moderator holds 2–4 region atoms (§6).
>
> **Group into synthetic macro-regions only when no official level fits** — i.e.
> the official subdivisions are absurdly small/numerous, or are not ISO-coded.
> Grouping requires a hand-authored grouping map (ISO→group slug) and forfeits
> ISO identity, so it is the last resort, never the default.

For the Netherlands the 12 provinces are ISO-coded, legible, and recognized by
riders and partners → **provinces, no grouping**, even though each is below the
band floor.

## 4. Netherlands — concrete wiring

`COUNTRY_CONFIG["NL"]` in `tools/divisions/config.py`:

- `subtype = "region"` (→ `admin_level = 4` via `SUBTYPE_ADMIN_LEVEL`).
- `slugs` / `names` — the 12 provinces:

  | ISO | slug | English name |
  |-----|------|------|
  | NL-DR | drenthe | Drenthe |
  | NL-FL | flevoland | Flevoland |
  | NL-FR | friesland | Friesland |
  | NL-GE | gelderland | Gelderland |
  | NL-GR | groningen | Groningen |
  | NL-LI | limburg-nl | Limburg |
  | NL-NB | noord-brabant | North Brabant |
  | NL-NH | noord-holland | North Holland |
  | NL-OV | overijssel | Overijssel |
  | NL-UT | utrecht | Utrecht |
  | NL-ZE | zeeland | Zeeland |
  | NL-ZH | zuid-holland | South Holland |

  Note `limburg-nl`: BE also has a Limburg province; slugs are global identity,
  so the NL one is disambiguated. (The scaffolder flags cross-country slug
  collisions during review.)
- `bbox = [3.2, 50.7, 7.3, 53.6]` — mainland NL predicate pushdown. The Caribbean
  special municipalities (Bonaire/Saba/St-Eustatius, `NL-BQ*`) are out of the
  bbox and out of scope; they are not cycling regions for this atlas.

Translations (`web/translations/messages.{en,fr,nl,de}.yaml`), 12
`region.<slug>.label` + `region.all_nl.label`, ×4 locales. Exonyms that matter:

| slug | en | fr | de | nl |
|------|-----|-----|-----|-----|
| noord-holland | North Holland | Hollande-Septentrionale | Nordholland | Noord-Holland |
| zuid-holland | South Holland | Hollande-Méridionale | Südholland | Zuid-Holland |
| zeeland | Zeeland | Zélande | Zeeland | Zeeland |
| noord-brabant | North Brabant | Brabant-Septentrional | Nordbrabant | Noord-Brabant |
| friesland | Friesland | Frise | Friesland | Friesland |
| all_nl | All Netherlands | Tous les Pays-Bas | Gesamte Niederlande | Heel Nederland |

(de `Zeeland` — corrected during planning from an earlier "Seeland", which is the
Danish exonym for Sjælland, not the German name for the Dutch province; de
`all_nl` = `Gesamte Niederlande`. Both are what shipped in the four catalogs.)

(Remaining provinces — Drenthe, Flevoland, Gelderland, Groningen, Limburg,
Overijssel, Utrecht — are stable across the four locales.)

## 5. Coverage

- Add `europe/netherlands` to `COVERAGE_REGIONS`:
  `developers/docker/.env.example` (dev default) and the production coverage
  job's env. Value becomes `europe/belgium,europe/netherlands`.
- Run the pipeline for NL: Geofabrik `europe/netherlands` PBF (~1.4 GB) →
  `osmium tags-filter` → pyosmium → `coverage_poi` **per-region atomic swap** →
  tippecanoe → `coverage.pmtiles` → `cc-maps` bucket. Belgium is not re-run
  (regions are independent per [coverage-provider.md](coverage-provider.md)).
- For state-level coverage of a large country, `COVERAGE_REGIONS` accepts
  Geofabrik sub-region paths (e.g. `europe/germany/bayern`) — the same
  demand-driven granularity as `--only` on the divisions side.

## 6. Moderation — no schema change

`ModeratorArea` is already `(user × exactly-one-region-OR-country)` with a
unique constraint on `(user_id, region_id, country_code)` — so a user can hold
**several** rows. "A moderator covers 2–4 provinces" is therefore just 2–4
`ModeratorArea` region rows for that user; no entity or migration change.

Verification owed by the plan: confirm the moderation scope-resolution query
**unions** all of a user's areas (a moderator with Noord-Holland + Zuid-Holland +
Utrecht rows sees all three), and add a test if that multi-area path is not
already covered.

## 7. Testing

- **`app:region:scaffold`** — unit test against a World fixture (NL subset):
  asserts the emitted `config-block.py` slug/ISO/name map, the four-locale
  translation stub keys incl. `all_nl`, `--only` narrowing, and the
  cross-country slug-collision flag (`limburg` BE vs NL).
- **Divisions** — NL config parse + area-guard test offline (the exporter's
  "too-small box fails loud" guard); NL live-Overture smoke behind
  `RUN_LIVE_OVERTURE=1`.
- **Coverage** — assert the runner accepts a multi-region `COVERAGE_REGIONS`
  and processes each independently (committed fixture PBF, no network).
- **Translations** — the existing `tools/check-translations-precommit.sh`
  enforces four-locale key parity; the new keys must pass it.

## 8. Specs / docs updated

- `tools/divisions/README.md` — replace the short "Adding a country" section
  with the full §1 playbook + the scaffolder, NL as the worked example.
- `2026-07-19-region-scoping-design.md §7` — record Phase 5 opened, NL the first
  rollout country (12 provinces).
- `coverage-provider.md` — note `europe/netherlands` added to `COVERAGE_REGIONS`.
- This design doc stays the working record of the onboarding-process decisions.

## 9. NL execution order (this session)

1. Build + test `app:region:scaffold`; run it for NL (`--probe-areas`).
2. Review its output; merge the NL config block + translation patch; fix exonyms.
3. `export_divisions --country NL` → 12 geojson artifacts; run divisions tests.
4. **Confirm, then** `app:catalog:import` to seed the 12 NL `Region` rows in dev.
5. Add `europe/netherlands` to `COVERAGE_REGIONS`; run the NL coverage pipeline.
6. Verify end-to-end in the running app (NL scope selectable, Dutch coverage POIs
   render + are searchable, a province region resolves).
7. Update the specs/playbook in §8; run the full suite.

## 10. Out of scope / non-goals

- Curated NL data (climbs, routes, best-of) — separate, demand-driven, later.
- NL Caribbean municipalities.
- Per-region cap tuning — `activeCap` stays NULL (global default 30); an admin
  can override later.
- Automating exonym translation — deliberately a human review step.
- A one-shot `onboard` command (rejected: removes review where it matters).
