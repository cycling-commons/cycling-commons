<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Dynamic region pages and the 2+4 onboarding rollout — design

**Status:** design, approved by the owner (2026-07-30), pending implementation
· **Audience:** contributors to Cycling Commons

## 1. Why now

A country should become visible on the website the moment it is onboarded,
with no hand-edited page. Today neither regions page tells the truth:

- `/regions` (`PageController::regions`, `pages/regions.html.twig`) renders
  client-side from the **baked snapshot** `web/assets/data/regions-data.js`
  (`CC_REGIONS`): 24 countries / 142 exploratory clusters worldwide, most of
  which were never onboarded and imply promises nobody made.
- `/region` (`pages/region.html.twig`) is a static Wallonia showcase whose
  stats are literal translation strings (`region.stat_climbs_n` is a
  hardcoded number).

Meanwhile the country-requests/curator-signup feature (design:
2026-07-29-country-requests-and-curator-signup-design.md, merge-ready at
`ad1e940`) gives every country a real door — `/join/{cc}` — that nothing
public links to yet. This window makes the regions pages DB-driven, makes
them the discoverability surface for `/join/{cc}`, lands the §12.1 "levels
2 + 4" onboarding decision (that spec's §12, adopted 2026-07-30) in the
playbook with a one-time backfill, and closes by writing that spec's §13
public onboarding story in the wiki — from what actually shipped.

## 2. Owner decisions (2026-07-30, this design)

All six settled in the brainstorm; recorded here so nothing re-litigates:

1. **Region cards show status + modest stats.** A status tier plus
   verified-item count and route count (each only when nonzero). No
   coverage-POI numbers (raw OSM, not our editorial signal), no
   curator-present flag, and — per the standing §12.2 decision
   (2026-07-29-country-requests-and-curator-signup-design.md) — **no
   interest counts anywhere**.
2. **Server-rendered Twig, per-request queries.** No JSON endpoint, no
   client render, no cache layer. At ~32 regions the counts are indexed
   microsecond COUNTs; HTTP caching can come later if traffic ever demands
   it (and would then need the exact-path PUBLIC_ACCESS firewall
   carve-out — not built now).
3. **Detail page is `/regions/{slug}`**, DB-driven for any operational
   region; old `/region` becomes a locale-aware 301 to `/regions/wallonia`.
4. **The ask-for-your-country door is an inline typeahead on `/regions`**
   over the ~245 localized `world_country` names, baked into the page as
   JSON — no endpoint, one step to `/join/{cc}`.
5. **Level-2 backfill polygons come from the Overture divisions exporter**
   (same release pinning, same importer, upsert-by-slug) — one source
   lineage for every region row.
6. **The listing shows onboarded countries only.** The snapshot's
   exploratory world clusters disappear from pages entirely; everything
   else is reached through the typeahead door.

## 3. Facts this design stands on (verified 2026-07-30, dev DB)

- `region` rows: BE 3×L4, DE 16×L4, NL 12×L4, **LU 1×L2**. Items: Wallonia
  1,581 (2 verified in dev), every other region 0. Routes: 12
  (`recommended_route`). Coverage POIs are regionised (431,936 rows with
  `region_id`) but stay off these pages.
- `RegionResolver` (`web/src/Contribution/RegionResolver.php`) and
  `ImportCatalogCommand::recomputeMembership` share the containment rule
  *smallest area wins* — so a country-sized L2 row can never steal item or
  route membership from an L4 subdivision. The backfill is safe for
  anchoring by construction.
- `RegionRegistryProvider` (`web/src/Catalog/RegionRegistryProvider.php`)
  selects **all** region rows with no `admin_level` filter — without a new
  rule, backfilled L2 rows would surface as scope-rail chips ("Belgium"
  beside Wallonia) and pollute moderation pickers. Hence §4.
- Map deep-link format (`web/assets/map/scope.js` `serialize`):
  `/map?scope=region:<slug>` and `/map?scope=country:<CC>`; slug-based so
  links survive re-imports.
- `CuratedReadiness` (thresholds 25/3/5) and `region.curated_default`
  (curator Regions desk) already exist as status signals.
- LU's L2 slug is `luxembourg` (`tools/divisions/config.py`) — the slug
  precedent for country-level rows is the plain English country name.

## 4. The operational-region rule

**A region row is *operational* iff its `admin_level` equals the deepest
onboarded level for its country.**

- BE/NL/DE: L4 rows stay operational; the new L2 rows are **infrastructure**
  — submission anchoring for pre-onboarding evidence
  (2026-07-29-country-requests-and-curator-signup-design.md §4 step 3) and
  the country outline. They appear on no public or moderation surface.
- LU: its lone L2 row remains operational, exactly as today.
- The rule is **derived** (one `MAX(admin_level) … GROUP BY country_code`
  join or subquery), not stored: no schema change, no playbook state, no
  way for a flag to go stale when a country later deepens (a country
  onboarding L6 per the §12.1 per-country setting automatically demotes its
  L4 rows the day the L6 rows land — depth stays a property of the
  onboarding run).

**Consumer audit (implementation task):** every reader of `region` that
lists or enumerates regions gets the operational filter or a documented
exemption. Known readers to audit: `RegionRegistryProvider` (map scope
registry), `ModerateRegionsController` / moderation area pickers,
`CuratedReadiness.reportForRegions`, the new pages provider (§5), and the
catalog importer's membership recompute (exempt — containment already
resolves mixed levels; the smallest-area rule is the point). `RegionResolver`
is exempt by the same argument: L2 must stay matchable so evidence in
not-yet-subdivided countries anchors somewhere.

## 5. Data layer

One new read-side provider, `RegionDirectoryProvider` (Catalog namespace),
per-request, no cache. It returns:

- **Per country** (grouped by continent): ISO code, localized name and SVG
  flag via `world_country`, continent, and the country's operational
  regions.
- **Per operational region:** slug, rider-facing label (existing
  `region.<slug>.label` messages-domain keys, 4 locales), `area_km2`,
  status tier, verified-item count, route count — and, for the detail page
  only, verified items **by kind**.

**Status tiers** (public, deliberately simpler than CuratedReadiness):

| Tier | Rule |
|------|------|
| **Curated** | `curated_default` is on |
| **Growing** | any verified items |
| **Onboarded** | otherwise |

`CuratedReadiness` stays an internal curator-desk signal; the public tier
reads only `curated_default` and verified counts, so the public story never
needs the 25/3/5 thresholds explained.

## 6. `/regions` — the listing

Server-rendered Twig replaces the client render; `regions-data.js` leaves
this page entirely (the map's chip-ranking copy of `CC_REGIONS` is
untouched — standing constraint).

- Onboarded countries only, grouped by continent, region chips per country.
- Each chip: label, status tier, verified-item count and route count when
  nonzero, km². Chips link to `/regions/{slug}` (every operational region,
  not just Wallonia — the "live vs indicative" legend dies with the
  snapshot; the new legend explains the three tiers).
- Each country header carries the **apply-to-curate door** to `/join/{cc}`.
- Bottom block, **"Don't see your country?"**: a client-side typeahead over
  the ~245 localized country names baked into the page as JSON (filters as
  you type, keyboard accessible, links to `/join/{cc}`). Four-locale copy.
  Progressive enhancement: with JS off the block still shows a plain link
  to the existing `/join` page (`PageController::join`), which carries the
  contribute-paths guidance.

## 7. `/regions/{slug}` — the detail page

New localized route (path-prefix i18n, EN clean, as everywhere).

- **404** for unknown slugs and for non-operational rows (nothing links to
  them; `belgium` et al. resolve nowhere public).
- Old `/region` route becomes a locale-aware **301** to `/regions/wallonia`.
- Content, honest at any data volume: region name, country (+flag), km²,
  status tier; verified items **by kind** (climbs, water, stays, …), route
  count; two doors — the scoped map deep-link
  (`/map?scope=region:{slug}`) and apply-to-curate (`/join/{cc}`); when
  counts are all zero, "just onboarded" copy frames the emptiness as an
  invitation, not a failure.
- The hardcoded showcase (best-of lists, coverage bars, contributor chips)
  is deleted along with its translation strings in all four catalogs. A
  real rankings block is a later iteration once regions beyond Wallonia
  have data (route-domain rankings already exist server-side).

## 8. Doors elsewhere

One footer/Get-involved link to the regions page (anchor at the
ask-for-your-country block). No new nav item — Get involved ≠ contribute
(standing nav decision). Moderator signup stays admin-only; the only public
volunteer door remains the curator application.

## 9. Playbook 2+4 and the one-time backfill

- **Exporter:** `tools/divisions` always emits the Overture
  `admin_level=2` country outline alongside the L4 (or configured-level)
  divisions — same `OVERTURE_RELEASE` pinning, same GeoJSON provenance
  properties, same importer, upsert-by-slug. Country-row slugs are plain
  English country names (`belgium`, `netherlands`, `germany`), matching
  LU's `luxembourg`.
- **Backfill:** one export+import run for BE/NL/DE. Proof: before/after
  `region` row counts per country against the dev DB, with the exact
  commands in the task report. INSERT-only; no destructive dev-DB ops.
- **Playbook README** (`tools/divisions/README.md`): rewritten to the
  2+4 default with the finer-levels-per-country setting
  (2026-07-29-country-requests-and-curator-signup-design.md §12.1);
  documents the operational-region rule (§4 above) so the next onboarder
  knows why the L2 row exists and where it must *not* appear. France waits
  for the owner to run the playbook after this lands — no new country is
  onboarded in this window.

## 10. §13 wiki spec — written last

The public onboarding story
(2026-07-29-country-requests-and-curator-signup-design.md §13) lands in the
**wiki**, not `docs/specs/` — the wiki owns the public "why", specs own the
"what/how" (docs/specs/README.md rule 2). It is the final task of the
window, written from what actually shipped: how a country goes from "not on
the map" to curated, what the volunteer is signing up for, and what the
regions pages now show at each stage.

## 11. Testing and gate

- **Provider unit tests:** operational rule (LU L2 operational; BE L2 not;
  a country gaining a deeper level demotes the old one), tier derivation,
  count correctness (verified-only, by kind).
- **Controller tests:** listing renders onboarded countries only; detail
  200 for operational slugs, 404 for `belgium` and unknown slugs; `/region`
  301s locale-aware; localized paths resolve in all four locales.
- **Translations:** every new/changed key in all four catalogs in the same
  commit (hook-enforced); dead `region.*` showcase keys removed from all
  four.
- **SPDX** on every new file; DBAL 4 rules (`ParameterType::BOOLEAN`,
  `jsonb_exists()`).
- Full per-task gate: PHPUnit baseline 856 green, PHPStan, Psalm,
  php-cs-fixer, check-spdx, check-translations. No `web/assets/map/`
  changes are expected, so the map arms (map-refs, scope-test, logged-in
  sweep) shouldn't trigger; if a task does touch them, they apply.
- Backfill task: row-count proof per §9.

## 12. Out of scope

- Interest-count displays anywhere (owner decision, standing).
- Onboarding any new country (France is the owner's own playbook run,
  after).
- Rankings/best-of blocks on the detail page (later iteration).
- HTTP/shared caching of the pages (add only if traffic demands).
- The map module split, the chip-ranking consumer of `CC_REGIONS`, and the
  go-live gate (all standing constraints).
