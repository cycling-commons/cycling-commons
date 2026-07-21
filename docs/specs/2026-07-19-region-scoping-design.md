# Region scoping, search scope widening, and rider base location — design (working spec)

Status: **proposed** (2026-07-19); **Phases 1–3 executed** (2026-07-19 /
2026-07-20 / 2026-07-21; see §7), **Phase 2 adversarially reviewed 2026-07-21 —
all 10 findings fixed** (review round note in §7). Belgium is tessellated
(Wallonia/Flanders/Brussels); the map, search AND coverage tier are all
scope-aware — the whole map filters to scope. Phases 4–5 (base location / My
area; worldwide rollout) not started.
Multi-agent research + design run; all repo-structural claims below were
adversarially verified against the codebase (4 corrections from that pass are
folded in and marked "verified correction" where decision-relevant).

Product requirements (owner, 2026-07-19):

1. Countries can *optionally* be split into regions (Belgium: Wallonia / Flanders /
   Brussels). When a region context is active, map + search filter on that region.
2. The rider can widen the search scope from region to the whole country
   ("search in Belgium instead of Wallonia").
3. Riders near a border don't care about our region lines: an optional **base
   location** derives the appropriate region set automatically, possibly spanning
   regions *and* countries.
4. Regions are a bit less for riders and **more for moderation**.

Trigger observation: the map's Wallonia spotlight is decorative
(`web/assets/map/map.js` `addRegionBoundary('Wallonia')`, Nominatim-fetched) while the
coverage tier renders Belgium-wide — nothing in the data path filters by region today.

## 1. How other platforms do this

| Platform | Rider-facing geography | Moderation geography | Base location |
|---|---|---|---|
| Trailforks | Browsable region tree (country → province → riding area) | Region-subtree volunteer admins, vetted, trail-association directors preferred; staff wait ~1 week before touching queues that have local admins; trust-weighted community voting | n/a |
| Komoot | Predefined unlock polygons; planning free worldwide | Central | Free region ≈ home region, suggested from device location |
| Strava | None — viewport is the filter | "Ride it before you moderate it" (hazard flag needs a matched activity) | Privacy zones: address + jittered radius; still ~85 % reversible via derived distances (KU Leuven, CCS 2022) |
| RideWithGPS | None — pan/zoom + "Search this area" | Central | n/a |
| AllTrails / Wikiloc / MTB Project | Geo directory (country → region → city) for browse/SEO | Central staff review | n/a |
| Craigslist | Fixed metro containing-regions | Central | Border users forced into "nearby areas" workarounds; lost most of its audience to radius-first competitors |
| FB Marketplace / Meetup / Geocaching | Point + adjustable radius | Central | Private home point, user-chosen granularity, never shown to others |
| Nextdoor | Hard neighbourhood-polygon membership | Trust *is* the product (verified address) | Retains exact address server-side — the named anti-pattern |

Patterns that matter here:

1. **Radius-from-a-point won rider-side discovery** (Marketplace/Meetup/Geocaching vs
   Craigslist's decline). Every polygon has a border someone lives on; a circle around
   the rider has none.
2. **Region membership survives where jurisdiction/trust is the product** — Nextdoor,
   Trailforks. That is exactly our moderation tier (requirement 4).
3. **Trailforks is the moderation blueprint**: vetted regional admins along lines real
   trail associations recognise; central staff as fallback — structurally our existing
   "unassigned = global" rule in `ModerationScope`.
4. **Viewport is the filter for the map canvas itself** (Strava, RWGPS): tiles never
   need per-request region filtering; *scope* governs lists, search, rankings, and the
   spotlight.

## 2. Recommended architecture

Three candidate designs were produced (admin-boundary-first / Upstream-cluster-first /
rider-radius-first) and judged through rider-UX, moderation-ops and engineering lenses.
**Winner: admin-boundary-first regions, grafted with the radius-derived "My area"
rider layer.** Reconciliation:

| Model | Role |
|---|---|
| **Admin subdivisions (ISO 3166-2)** | *The region atom*: storage, moderation jurisdictions, caps, ranking facets, named scope, future browse/SEO pages. Licence-clean (Overture divisions / OSM, both ODbL); stable slug + ISO identity across re-imports. |
| **Base location + radius** | *The rider default scope generator*, not a region model: produces a derived set of region atoms (cross-border by construction). Personal data — see section 4 privacy invariants. |
| **Divisions clusters (`tools/regions/`)** | **Rejected** as an operational region source: synthetic names are illegible to riders and partners, Brussels (~162 km²) structurally cannot exist under the KEEP 80–150 % band (13,520 km² floor), regeneration churns jurisdictions, and the pipeline reads the private upstream-geodata schema. **Retained** as the *calibration rule*: the ~16,900 km² Wallonia band decides each country's operating level (pick the admin level whose subdivisions best fit the band; else a lower level or explicit ISO-code list), and advises per-region cap tuning. `web/assets/data/regions-data.js` stays an indicative browse visual. |

Decisions:

1. **A region is one official administrative subdivision at a per-country configured
   operating level.** Belgium: admin_level 4 / ISO 3166-2 → BE-WAL, BE-VLG, BE-BRU.
   The codebase is already admin-boundary-shaped: the Wallonia seed *is* the OSM
   admin_level=4 relation (`tools/wallonia/export.py`), `Region.countryCode` exists,
   `ModeratorArea` is region-XOR-country, and the World bundle holds the ISO 3166-2
   tree.
2. **Unsplit countries simply have no region rows.** Caveat (verified correction):
   this is *not* zero machinery — `SpatialResolver` derives countryCode only from a
   containing region row, so items in unsplit countries get `''` and country-scoped
   curators there match only via the NULL-region safety net; and
   `ImportCatalogCommand::importItemLayers` hardcodes `'cc' => 'BE'`. Both are
   scheduled (Phase 5) — after Phase 2, Belgium is fully tessellated so BE is
   unaffected.
3. **Rider default scope = derived region set from an optional base location** (coarse
   point + adjustable radius, `ST_DWithin` against region polygons, cross-border by
   construction, capped at 8 regions).
4. **A named region/country selector still exists** (Wallonia / Flanders / Brussels /
   All Belgium / Everywhere) — requirement 1 verbatim, plus trip planning outside your
   home area, plus the future browse surface.
5. **One scope model everywhere**: `{kind: myArea|region|country|everywhere,
   regionIds[], countryCode}` threaded through map filtering, all three search
   branches, best-of, spotlight, viewport. (`kind`, not `mode` — `map.js` line ~1050
   already owns `mode` for Curated/Everything; verified collision.)
6. **Coverage scoping via region-id tile props + query params** — not per-region tile
   builds, not MapLibre `['within']` (section 6).
7. **Phase 1 is a hard hardening gate** before any region multiplies (importer
   country_code fix, deterministic overlap resolution, best-of LIMIT guard, Nominatim
   retirement, never-delete-region-rows rule). All three designs and all three judges
   converged on these independently.
8. **Worldwide rollout is lazy and demand-driven**: seed a country's subdivisions when
   moderation volume or a curator candidate appears. Coverage expansion is the upper
   bound, not the trigger.

## 3. Data model changes

**Polygon source + licence.** Primary: Overture Maps divisions theme `division_area`
(ODbL; conflates OSM + geoBoundaries; carries ISO 3166-1/-2 and a normalised
per-country admin level). Cross-check/fallback: OSM admin boundaries directly (also
ODbL). GADM excluded (non-commercial licence). Natural Earth never used for stamping
(display-generalised). A dedicated exporter (`tools/divisions/`, Overture
`division_area` via DuckDB — **owner decision 2026-07-20:** build it worldwide-ready
from the base rather than extending the Wallonia OSM harvest, which was a test
scaffold) emits `region-<slug>.geojson` artifacts for the existing
`ImportCatalogCommand::importRegions` glob — same pipeline, more files. Wallonia is
re-sourced through it (`source` osm→overture, slug stable). Region areas are computed
geodesically (`pyproj.Geod`); DuckDB `ST_Area_Spheroid` mis-scales by ~1/cos(lat).

**`region` table / `App\Catalog\Entity\Region`** — extend, never parallel:

- `iso_code` varchar(10) NULL — joins World `Subdivision.code`.
  **Verified correction:** localized names are *not* free via that link —
  `Subdivision.name` is English-only (php-isocodes-db-only ships English msgids, see
  `web/src/World/Command/ImportWorldDataCommand.php`). Region display names therefore
  come from the messages translations domain (`region.<slug>.label`, 4 locales — cheap
  for operational regions, added per seeding); country display names via Symfony Intl
  at runtime.
- `admin_level` smallint NULL, `source` / `source_id` (overture|osm provenance).
- `active_cap` smallint NULL — per-region override of `route.region_active_cap: 30`
  (`web/config/packages/route_domain.yaml`); Brussels and Flanders cannot share a cap.
- Upsert stays keyed by slug (`wallonia` keeps its slug).
- Full-resolution MultiPolygon stays in `geom` (stamping); a simplified
  (`ST_SimplifyPreserveTopology`) copy serves client spotlights.

**Critical importer fix (Phase 1 gate).** `ImportCatalogCommand::importRegions`
(`web/src/Catalog/Command/ImportCatalogCommand.php:98-124`) writes only
slug/name/geom/area_km2; `country_code` was backfilled for Wallonia only by migration
`Version20260704222148`, so every new region would get `''` — and country-scoped
curators match on `region.country_code` (`ModerationScope::sqlFragment`,
`ModerationScopeProvider`), i.e. a silent jurisdiction hole. The importer must stamp
`country_code` + `iso_code` + `admin_level` from artifact properties, with a
regression test. **Verified caveat:** the current `region-wallonia.geojson` artifact
carries only `{slug, name, area_km2}` — Phase 1 therefore includes re-exporting the
Wallonia artifact with the new properties (or an explicit fallback), not just the
importer change.

**Deterministic membership.** Overlaps must not be order-dependent. Fix **all three**
membership writers (the third is a verified addition):

- `RegionResolver` (`web/src/Contribution/RegionResolver.php:33-43`): currently
  `ORDER BY r.id LIMIT 1` → smallest-area-wins ordering.
- `ImportCatalogCommand::recomputeMembership` (unordered `UPDATE … FROM`).
- `pipeline/coverage/load.py:135-143` — the coverage_poi region stamp is the same
  unordered `UPDATE … FROM region`.

Plus an `ST_Overlaps` sanity check at import (operating-level regions must tessellate
within a country): the import check prevents, the ordering rule survives a bad import.

**Unchanged seams (by design).** `item.region_id`, `recommended_route.region_id`,
`submission.region_id` stay plain indexed bigint columns with from-scratch recompute
(catalog-data-model.md §6); `RegionResolver`/`SpatialResolver` interfaces unchanged;
`coverage_poi.region_id` is already stamped inside the per-region swap transaction and
picks up new polygons with zero code change (next weekly run — see the Phase 2 window
note).

**New index.** `coverage_poi.country_code` is unindexed (only geom/letter/region_id/
name exist). Add it before any `cc` filter arm ships. **Verified caveat:** that table
is pipeline-owned (`pipeline/coverage/load.py ensure_schema`), so the index lands
pipeline-side, not in `web/migrations`.

**`ModeratorArea`:** structurally unchanged. Codify the **never-delete-region-rows
invariant**: `moderator_area.region_id` is `ON DELETE CASCADE`
(`web/migrations/Version20260714210000.php`), so deleting a region row silently drops
curator jurisdictions. Region lifecycle is upsert-by-slug only; geometry changes only
via explicit versioned re-imports.

**User base location** (`web/src/Entity/User.php`, beside the nullable country FK):

- `base_point` — **coarse only**: town centroid, or a pin truncated to 2 decimals
  (~1 km) at write time; the raw pick is never persisted.
- `base_radius_km` smallint, default 40, clamped 10–150 (a riding distance).
- `base_region_ids` JSON + `base_country_codes` JSON — the derived set, mirroring the
  `bikeTypes` JSON-column pattern (tryFrom-style tolerance for deleted ids).
  Derivation: `ST_DWithin(geom::geography, base_point, radius)`, always including the
  containing region, capped at 8. Re-derivation runs inside the region-import
  transaction or verifiably queued (silent failure leaves best-of facets quietly
  wrong).
- One anchor point only — home+work pairs are near-unique even when coarse.

## 4. Rider UX

**Scope selector.** A Region group in the filter rail directly above View mode — the
slot the template already reserves (`web/templates/map/index.html.twig` ~85-88: View
mode "sits right under Region"); the rail *is* the mobile bottom sheet, so no second
mobile home. States: **My area** (only when a base location exists) / **Wallonia /
Flanders / Brussels** / **All Belgium** / **Everywhere**. Scope persists in
localStorage + URL. The header label (`map.region_line`; the literal
"Wallonia, Belgium" lives in the 4 translation files, not the template) becomes the
dynamic scope line ("Near Namur · 40 km" / "Flanders" / "Belgium" / "Everywhere").

**Defaults.** Base location set → My area. Otherwise → today's Wallonia behaviour plus
a one-tap "Set my area" prompt pre-filled from the map centre (Komoot's suggest-home
pattern). Anonymous riders: optional localStorage-only circle, nothing server-side.

**Spotlight.** Retire the Nominatim fetch (`map.js:49-63,709` — also a Nominatim
usage-policy problem for production). Named scope: simplified DB polygons via a small
cacheable boundary endpoint, same dim-outside + dashed-outline treatment. My-area
scope: a locally computed ~64-vertex soft circle — zero fetch, and the deliberately
fuzzy circle *is* requirement 3's anti-border message made visible. The hardcoded
Wallonia bounds literal (`map.js:42`) becomes fitBounds on the scope bbox.

**Map filtering.** `featureVisible` (`map.js` ~1322) gains a scope test on the
feature's region/country — which requires exposing `region_id` in the catalog map
payload (`CatalogProvider` carries no region data today; verified). Coverage layers
filter per section 6.

**Search scope widening (requirement 2).** Search runs scope-first; the results panel
shows the scope as a chip and always offers the one-tap ladder: My area → "Search in
Belgium instead" → "Search everywhere" (named scope: region → country → everywhere).
Auto-surface the widen chip when scoped results are sparse — the Craigslist lesson:
widening must be one tap, never a settings dig. Photon derives bbox + countrycode gate
from the scope (replacing the hardcoded Wallonia bbox / BE-only gate, `map.js`
~2675-2693); a My-area circle spanning the Dutch border admits NL hits automatically.
Best-of finally sends `&region=` (`MapController` already parses it; the "single
region — omitted for v1" note retires).

**Border-dweller flow (requirement 3).** Save base location → server derives the set →
a rider near Maastricht gets Flanders + Wallonia (+ Dutch Limburg once NL polygons are
seeded) with zero extra UI. Only the derived set is user-visible ("Your area:
Wallonia · Flanders · Limburg (NL)").

**Deep links & far panning.** `?feature/?pending/?route` deep links auto-*widen* the
scope to fit their target instead of failing inside a narrow scope; panning far
outside the My-area circle surfaces the widen chip rather than silently keeping
home-scoped lists.

**Settings + privacy.** Field lives in Settings → profile tab, "Identity & privacy"
section beside the country picker (`web/src/Form/SettingsType.php`). Pick a town
(Photon geocode → town centroid) or drop a pin (truncated to 2 decimals before
persisting). Optional, deletable. Privacy invariants (named, review-enforced):

- never rendered on `/riders/{uuid}` — the exposure list is frozen;
- never in any Commons dataset export (base location joins email/IP in the *account*
  category — update the privacy copy: dataset non-personal, accounts hold personal
  data);
- no derived distances or nearness-sorted output on any public surface (KU Leuven
  CCS 2022: 85 % of Strava privacy zones reversed from derived distances alone);
- requests transmit only the already-coarse stored value (request precision ==
  stored precision).

## 5. Moderation

**Jurisdictions — unchanged model, better atoms.** `ModeratorArea` (region XOR
country, union of rows, zero rows = global, ROLE_ADMIN always global) maps 1:1 onto
official subdivisions: a curator is appointed "for Flanders" or "for Belgium" — names
trail associations and dispute counterparties recognise, with a government referent to
arbitrate "is this in your area?". Trailforks validates the recruitment reality.
Country rows future-proof coverage: a 'BE' curator automatically covers new Belgian
regions the moment `region.country_code` stamping is correct — which is why the
importer fix gates everything.

**Queues — no structural change.** `ModerationScope::sqlFragment` (SubmissionQueue
alias `s`, RouteQueue alias `r`) and `ModerationScopeProvider::allowsRegion` are
region-count-agnostic (moderation-and-contribution.md §9.2/§9.3). NULL-region-visible-
to-all stays as the deliberate safety net; its noise shrinks as tessellation grows.
Interim remedy is an ops playbook, not code: when NULL-region volume clusters
somewhere, seed that country's subdivisions (pure ops action, zero rider-facing
consequence).

**Caps.** `route.region_active_cap` stays the 30 default; `region.active_cap`
overrides per region (read in `RouteModerationService::approve` /
`activeCountForRegion`; surfaced in RouteQueue's active-in-region display). Cap
changes get a `UserAdminService`-style audit note. The accepted approve-at-cap TOCTOU
(route-domain.md §5.1) stays accepted. Tune values with the Upstream calibration band
as advisory workload sizing.

**Admin tooling — hard gate before worldwide.** The moderator-areas form lists ALL
countries and regions flat and unpaginated (`Admin/UserCrudController` `findBy([],
['name'=>'ASC'])` for both). It becomes country-grouped with typeahead BEFORE global
curator recruiting or broad seeding. Add per-region queue-health counts to the desks
so recruiting targets the busiest beats. `UserAdminService::setModeratorAreas`
(validated, audited, transactional delete-and-reinsert) is unchanged.

**Future layer.** Trailforks-style vetting (local-club preference, staff-wait
convention, delegation groups) layers onto the same `moderator_area` rows without
schema change.

## 5a. Curator-sized jurisdictions (owner direction 2026-07-19 — **adopted**)

Owner direction from plan review: start each country **unsplit**; when someone wants
to curate, *they* pick the size — a Wallonia-sized chunk, a bigger request, or a
small area they personally know and can verify on the ground; others can join a
region; jurisdictions may merge later; leave it open and see how it evolves. With
the frontend no longer depending on regions (My-area does the rider-side work),
curation granularity is free to follow people, not planning.

Recommended mechanics — "curator-sized jurisdictions over official-line atoms":

1. **No upfront country configs.** Delete the idea of pre-deciding operating levels.
   A country has zero region rows until its first curator steps up. (Rider scope is
   unaffected: My area / country / Everywhere work without region rows.)
2. **Seeding follows the request.** When a curator volunteers, seed that country's
   subdivisions at the granularity that matches the *smallest* jurisdiction anyone
   there wants: level-4 for a "Wallonia-sized" request, provinces or communes for an
   "area I can verify in person" request. The ~16,900 km² band drops to purely
   advisory.
3. **A jurisdiction is a SET of official-subdivision rows**, not a drawn polygon.
   `ModeratorArea` already has union-of-rows semantics, so "my province + the two
   neighbouring ones" or "all five Walloon provinces" are just rows. Growing,
   joining (second curator adds the same rows), and merging jurisdictions are set
   edits — no schema change, no geometry work.
4. **Why official lines instead of freeform curator-drawn polygons:** deterministic
   membership stamping keeps working (`ST_Contains` over a tessellation), names stay
   legible to riders/partners/dispute counterparties, no overlap arbitration
   between rival hand-drawn shapes, and the never-delete/re-import lifecycle stays
   sound. The curator still gets exactly the *size* they asked for — composed from
   official pieces.
5. **One operating level per country at a time** (the tessellation invariant).
   If later demand needs finer granularity than the country was seeded with,
   re-seed at the finer level as a versioned migration event and remap existing
   jurisdiction sets onto the new rows (coarse jurisdictions = the union of their
   finer pieces, so remapping is lossless).
6. **Governance follows the canonical commons doctrine** (owner direction
   2026-07-19; wiki/governance.md "Ostrom's design principles, applied" and
   "Regional governance: subsidiarity, not hierarchy"): the moderators of a country
   govern that country's regional organisation **themselves**. Seeding granularity,
   splits, joins and merges are collective-choice decisions of the country's
   moderator community (Ostrom principle 3), whose right to self-organise the core
   recognises rather than grants (principle 7), nested commune ⊂ province ⊂ region ⊂
   country ⊂ core ⊂ foundation (principle 8). Per the wiki's subsidiarity rule,
   the core's role is **coordination, not taste**: it checks *standards* (official-
   line atoms, one tessellation per country, audit trail, never-delete rows, the
   moderation safety net, anti-abuse) and executes what the community decides via
   the audited `UserAdminService::setModeratorAreas` flow — it does not approve or
   override a country's *judgment* about its own organisation. Cold start follows
   the wiki verbatim ("most regions won't have a curator community at the start;
   this is the structure the Commons grows into"): the core recognises a country's
   first curator and executes the seeding they request, standards-checked only;
   from the second moderator on, organisation is the community's collective call.
   The per-region `active_cap` is principle 2 made concrete (the curated target
   scales with local conditions — e.g. the Brussels cap decision), and a region's
   curators are the natural voice for tuning it. Vetting conventions
   (Trailforks-style local-club preference) can be adopted *by* a community, never
   imposed on it.

Impact (confirmed 2026-07-19): Phase 5's "per-country operating-level config"
bullet is replaced by demand-driven seeding as above; Belgium keeps its
already-planned level-4 split (the owner's verbatim example and our live
moderation reality).

## 6. Server contract changes

**Query endpoints.**

- `/map/coverage/search`, `/nearby` — optional `rids` (csv region ids) + `cc`
  (country) params compiled to `region_id = ANY(:rids)` / `country_code = :cc` arms
  in `CoverageRepository`. Extend the same to `/counts` so rail badges reflect scope.
  Absent params = current behaviour (backward compatible). `region_id` is indexed;
  `country_code` gets its index first (pipeline-side).
- **Cacheability discipline:** these are anonymous public ETag/max-age responses
  behind the `coverage_read` limiter. Scope is always client-sent params (region ids,
  or the already-coarse centre + radius) — never per-user server-side resolution; any
  full-precision coordinate param would reopen the URL/log leak channel.
- `/map/best-of`: client sends `&region=`; My-area sends the derived set with a
  server-side merge + LIMIT. A hard LIMIT / require-region guard lands in **Phase 1**,
  before any widening UI exists — best-of without a region filter is the flagged
  unbounded aggregate (route-domain.md §12, known latent constraints item 1;
  **verified correction:** §12, not §8).
- New small cacheable **boundary endpoint** serving simplified region polygons
  (spotlight), replacing Nominatim.

**Coverage tiles — decision: region id as a tile property.** Emit `rid` (region_id)
and `cc` (country) as per-feature props in `pipeline/coverage/tiles.py::_letter_sql` +
`pipeline/contract/coverage-contract.json` tileProps — verified near-one-line change
(export SELECTs straight from `coverage_poi`; `jsonb_strip_nulls` makes NULL rows
free; flat bigint is MVT-legal). Client filters with
`['match', ['get','rid'], scopeIds, true, false]` composed with the existing
curated-ref dedupe filter.

Rejected alternatives:

- **MapLibre `['within']` as primary** — boundary GeoJSON inlines into the style,
  per-feature point-in-polygon at tile parse, boundary points return false. Acceptable
  only as the transition fallback on a heavily simplified polygon.
- **Per-region PMTiles** (`pmtiles extract`) — multiplies artifacts and bypasses the
  single-manifest flow, for no query win the property filter doesn't give.

**Rollout coupling:** new props ride the weekly pipeline rebuild (systemd timer,
Sun 03:12) and go live within ~1 h via the manifest (CoverageManifest TTL 3600) with
no deploy — the client MUST tolerate prop-less tiles during transition. Fallback
ladder: no tile filtering (status quo) or temporary `['within']` on the simplified
polygon. Country scope = `rid` match against that country's region ids, OR `cc` match
for unsplit countries.

## 7. Phased implementation plan

Belgium-first, then worldwide. Every phase independently shippable.

**Phase 1 — Foundation hardening (zero UX change, correctness only). Hard gate.**

**Executed 2026-07-19 (symfony-base, not pushed).** Every bullet landed with
unit tests + end-to-end verification against the dev stack. Anchors: migration
`Version20260719140000` (region `iso_code`/`admin_level`/`source`/`active_cap`,
all nullable); the importer now **requires** `country_code` and rejects
meaningful `ST_Overlaps` pairs (sub-permille boundary slivers tolerated);
membership is smallest-area-wins in **all four** writers — the three named in §3
plus `SeedManualCatalogCommand::recomputeMembership`, a fourth writer caught in
review (catalog-data-model.md §6); `RouteRankingService::MAX_RESULTS` = 200; boundary endpoint
`GET /map/region/{slug}/boundary` (`RegionBoundaryProvider`) replaced the
Nominatim fetch; invariants + seeding playbook in catalog-data-model.md §2.4.

- `importRegions`: stamp `country_code`/`iso_code`/`admin_level`/`source` from
  artifact properties + regression test; re-export `region-wallonia.geojson` with
  those properties (current artifact lacks them — verified).
- Migration: `region` + `iso_code`/`admin_level`/`source`/`active_cap`;
  pipeline-side `coverage_poi.country_code` index.
- Deterministic overlap: smallest-area-wins in `RegionResolver`,
  `recomputeMembership`, **and** `pipeline/coverage/load.py`; `ST_Overlaps` rejection
  at import.
- Hard LIMIT / require-region guard on `/map/best-of`.
- Boundary endpoint (simplified Wallonia polygon from DB); retire the Nominatim fetch
  in `map.js`.
- Document the never-delete-region-rows invariant (catalog-data-model.md §2.4) and
  the seeding playbook.

**Phase 2 — Belgium multi-region + scope selector (requirements 1 + 2).**

- Overture-divisions exporter → `region-flanders.geojson` (BE-VLG),
  `region-brussels.geojson` (BE-BRU); import → Belgium fully tessellated; item
  membership recompute picks them up with no code change. Note: `coverage_poi`
  re-stamps only on the next weekly pipeline run — a bounded window where coverage
  rows still carry the old mapping (verified).
- Region translations: `region.<slug>.label` keys, 4 locales.
- Rail Region group (reserved slot); scope object (`kind`, localStorage + URL);
  dynamic header scope line; spotlight from the boundary endpoint; initial bounds =
  scope bbox.
- `featureVisible` scope test + `region_id` exposed in the catalog payload; best-of
  sends `&region=`; widen chip ("Search in Belgium instead") with auto-surface on
  sparse results; Photon bbox/countrycode derived from scope; deep links auto-widen.

**Phase 2 — EXECUTED 2026-07-20 (symfony-base `acfc7d9..cf941f7`, NOT pushed).**
Belgium is fully tessellated (3 Overture regions imported to the dev DB — 1592
items region-assigned) and the map + search are scope-aware end-to-end. Every
slice below landed with unit/functional tests + browser verification on the dev
stack (0 console errors). Belgium data is currently Wallonia-only, so the scope
filter is binary in practice (Flanders/Brussels render empty until their POIs are
harvested — a data task, not Phase 2); the widen ladder makes that legible.
Landed:

- **Exporter** (`tools/divisions/`, commit acfc7d9): Overture `division_area` →
  `region-{wallonia,flanders,brussels}.geojson` with the required provenance;
  geodesic areas verified (Wallonia 16 903 km² vs 16 901 official). Region export
  removed from `tools/wallonia/export.py`; `make divisions-data` added.
- **Translations** (3790d8f): `region.<slug>.label` + all-belgium/everywhere,
  4 locales in parity.
- **Payload** (5c756f5): `CatalogProvider` serves `region_id` as `rid` on every
  shape (POI/climb/surface/route), conditional on non-null; aligns with the
  Phase-3 tile prop for one client filter.
- **Scope module** (6688c59): `web/assets/map/scope.js` (`window.CCScope`) —
  `{kind, regionIds, countryCode}`, localStorage+URL persistence, widen ladder,
  bbox, best-of/Photon param derivation. `kind`, not `mode`. Node-smoke-verified.
- **Tessellation gate** (589574b): sub-permille sliver-tolerance test + a
  real-Overture-Belgium tessellation test through PostGIS. **Finding:** Overture's
  Belgium boundaries are topologically clean — the 3 regions share exact edges
  with ZERO area overlap, so the guard passes with maximal margin (the tolerance
  is a safety net for sliver-prone sources like independent OSM relations).

- **Scope selector + filtering** (6776c0b): `RegionRegistryProvider` feeds
  `window.CC_REGIONS`; rail Region group (Wallonia/Flanders/Brussels/All Belgium/
  Everywhere), dynamic header + brand kicker, per-region spotlight from the
  boundary endpoint, scope-bbox initial bounds (retired the hardcoded `map.js`
  Wallonia literal). `featureVisible` + `renderSurfaceLayer` + the verified-POI
  clusters gate on `rid`; best-of sends `&region=` for a named region. Verified:
  Wallonia 77 markers → Flanders 0 → All Belgium 77. **Correction (07-21 review
  round):** this list was incomplete as executed — `render()`'s route-line branch
  bypassed `featureVisible` (review finding 1) and the heat layer had no gate at
  all (finding 5); the 77→0→77 measurement counted marker DOM nodes and could see
  neither. Both fixed in the review round below. **Bug caught in browser +
  fixed:** the registry emitted `cc` but `CCScope` expects `countryCode`, which
  silently broke "All Belgium" + the widen ladder; aligned to `countryCode`.
- **Search widening** (cf941f7): Photon bbox + countrycode derive from scope
  (retired the hardcoded Wallonia bbox / BE gate); one-tap widen chip in the
  results (region → country → everywhere, always offered while widenable, dropdown
  stays open); dynamic search title; deep links (`?feature/?pending/?route`)
  transiently widen to Everywhere so a narrow saved scope can't hide the target.

Coverage-tile scoping (`rid`/`cc` tile props + `rids`/`cc` coverage params) stays
Phase 3; base location / My area stays Phase 4. `kind` leaves room for the future
`myArea` value.

**Phase 2 — adversarial review round (2026-07-21, all findings fixed).** A
fresh-context reviewer (docs/2026-07-20-phase2-review-brief.md, local-only)
audited `1cd5f44..HEAD`; 10 findings + 5 info items, all resolved:

1. *Route lines bypassed the scope gate* (HIGH, live-reproduced: Flanders scope
   + Everything drew all 11 Wallonia lines while the legend said 0/11). Fixed:
   `render()`'s line branch now calls `featureVisible()` — the full rule
   (mode/cur + `inScope` + bike-pref), never an inlined subset. This also fixed
   a latent legend-vs-map mismatch for the bike-pref prefilter on lines.
2. *The hardcoded Hautes Fagnes hazard fixture* carried no `rid`, so the scope
   gate hid it everywhere except Everywhere — including the default Wallonia
   view (HIGH). **Owner decision: retired.** F ships empty until real hazard
   rows are served as region-stamped data; rid-less stays the leak-safe hidden
   default. (The stale twin row in the dead demo file
   `web/assets/data/profiles-data.js` — no consumers — is left for the planned
   dead-legacy sweep.)
3. *Local search ignored scope while the header claimed "Search in X"*
   (MED-HIGH). Fixed: `runS` filters served rows with the map's own
   `inScope(rid)` gate; the widen chip restores them rung by rung. **Owner
   decisions:** towns stay exempt (places, not scoped features — CITIES
   generalises in Phase 5); the town card's `nearbyItems()` stays deliberately
   scope-exempt (opening a town is an explicit location choice); coverage
   search rows stay unfiltered until Phase 3 so search always mirrors the map.
4. *Exporter maritime/duplicate hazards* (MED). Fixed: `class='land'`
   predicate (coastal divisions carry a maritime twin row), fail-loud on
   duplicate ISO rows (never last-wins-overwrite), and the bbox pushdown is
   now a true overlap test (the min-corner BETWEEN dropped regions whose min
   corner fell outside the config box). Live-verified against Overture
   `2026-06-17.0`: Belgium still exports exactly its 3 regions.
5. *Heat layer was scope-exempt undeclared* (MED-LOW). **Owner decision:
   scope it.** `heat_point.region_id` (migration `Version20260721120000`,
   backfilled; `recomputeMembership` stamps it on every import), payload tuple
   is now `[lat, lng, season, rid]`, and `updateHeatFilter()` composes the
   season facet with the scope (the two setFilter sites used to overwrite each
   other).
6. *Single-country selector hardcoding* (LOW-MED). Fixed: one country rung per
   distinct registry country (`scope_countries`), labels follow the per-key
   convention `region.all_<cc>.label` (was `all_belgium`) — a country import
   adds its key exactly like `region.<slug>.label`.
7. *Empty registry blanked the header* (LOW). Fixed: `applyScope` rewrites the
   header/kicker/search-title only when the rail can label the scope; the
   server-rendered fallbacks survive a region-less install.
8. *scope.js had zero committed tests* (LOW). Fixed: `web/tests/js/scope.test.cjs`
   (17 node:test cases — init precedence, sanitize, widen ladder, bbox union,
   persistence, transient set), `make scope-test`, wired into `make app-test` +
   App CI; the divisions pytest suite was also missing from ci-tools — added.
9. *Unresolvable deep links still widened* (LOW). Fixed: the transient widen
   fires only when the `?feature/?pending/?route` target actually resolves,
   via the same resolvers the open calls use (`resolveLocalFeature` extracted
   so gate and open can't drift). Coverage-only `?feature` targets don't widen
   (coverage renders scope-unfiltered until Phase 3).
10. *Multi-region URL tokens filtered on both regions but labelled one* (LOW).
    Fixed: `deserialize` collapses `region:a,b` to the first resolvable slug
    until Phase 4's derived sets make multi-region scopes a real UI state.

Info items: widen chip is now a real keyboard option in the search listbox
(data-i + sMatches pseudo-entry); the Everywhere-reopen comment now states the
Wallonia-literal fallback truthfully; scope switches render once, not twice
(`applyScope` defers to `refreshBestOf`'s render in Everything mode);
antimeridian risk recorded as §8 risk 11. Rationale-closed (accepted as-is): a
quote inside a region slug would throw in `scopeLabel()`'s attribute selector —
slugs are curator-authored config validated at import (`[a-z0-9-]+` route
requirement), not user input, so the querySelector interpolation is not an
injection surface.

**Phase 3 — Coverage scoping.**

- `rid`/`cc` tile props (`tiles.py::_letter_sql` + contract tileProps); client
  `setFilter` composed with the dedupe filter; prop-less-tile fallback until the
  weekly rebuild lands.
- `rids`/`cc` params on `/map/coverage/search`, `/nearby`, `/counts`.

**Phase 3 — EXECUTED 2026-07-21 (symfony-base, NOT pushed).** The whole map
now filters to scope — the coverage carve-outs are retired. Landed with
unit/functional tests (PHP + pipeline pytest + scope node suite) and browser
verification on the dev stack. Slices:

- **Tile props** (`pipeline/coverage/tiles.py::_letter_sql`): `rid`
  (`region_id`) + `cc` (`country_code`) emitted on EVERY layer alongside
  `ref`/`n`/`t`; `jsonb_strip_nulls` drops them for NULL rows, so a prop-less
  feature is legal. Declared as `universalTileProps` in
  `coverage-contract.json` and pinned by both contract test suites
  (`pipeline/tests/test_contract.py`, `web/tests/Catalog/CoverageContractTest.php`).
- **Endpoint params** (`CoverageRepository` + `CoverageController`): `rids`
  (csv region ids) → `region_id IN (…)`, `cc` → `country_code = :cc`, on
  `/search` (both the curated-item and community arms), `/nearby`, `/counts`.
  A country scope sends both, ORed, so an unsplit-country row (region_id NULL,
  cc set) still matches. Params are de-duped, **sorted**, and capped at
  `MAX_SCOPE_REGIONS = 24` in the controller (§8 risk 10 cache-keyspace
  discipline; the cap is safe because a country scope's `cc` arm is the
  complete fallback). Client-sent only, never server-resolved (§6 cacheability
  discipline); absent = current behaviour.
- **Client filter** (`map.js`): `covScopeFilter()` composes with
  `covDedupeFilter()` (and stays' `acc` extra) through one `covBaseFilter()`
  helper, re-applied on every scope change via `updateCoverageScopeFilter()`.
  **Deliberate asymmetry from served data (documented in code):** a prop-less
  coverage feature RENDERS (the §8 risk-2 fallback — the PMTiles artifact lags
  the DB by up to a weekly rebuild, so hiding-all would blank the map), whereas
  `inScope()`/`updateHeatFilter()` HIDE rid-less served rows (authoritative
  rid). `runCoverageSearch` sends the scope params (search mirrors the tiles);
  the runS "scope-unfiltered until Phase 3" exemption comment is retired; the
  widen chip re-runs `runCoverageSearch` so wider rungs surface.
- **Deep-link widen flip:** `widenForDeepLink()` (extracted, shared by the
  synchronous F9 gate) now also fires inside `openCoverageFeatureByName` once a
  coverage-only `?feature` target resolves — because a scoped tile can now hide
  it. The deep-link coverage lookup stays UNSCOPED so it can find the target
  regardless of the saved scope, then widens to reveal it.
- **Counts decision (open item in this phase — DECIDED):** `/map/coverage/counts`
  totals become **scope-aware** (they take `rids`/`cc` too), not global. The
  rail legend renders `shown/total`; `shown` counts scope-filtered rendered
  tiles (`covShownCount`) and the served side counts `featureVisible`, both
  scope-aware — so a global `total` would read incoherently (e.g. "3/500" in a
  region with 3 dots). Scope-aware totals keep the two sides in the same frame
  of reference. `fetchCoverageCounts` re-fetches with a race guard on each
  scope change.
- **Data window (bounded, same class as the Phase-2 `coverage_poi` note):** the
  dev PMTiles was built before this change, so its tiles carry no `rid`/`cc`
  until the next weekly pipeline rebuild (or a manual `make coverage-refresh`).
  Until then every coverage dot is prop-less and renders unfiltered under the
  fallback — correct-by-design, not a bug. The `coverage_poi.country_code`
  index (`load.py`) likewise lands on the next pipeline run on any DB that
  predates it; the `cc` arm works without it, just unindexed.

**Phase 4 — Base location + My area (requirement 3).**

- User columns (`base_point` coarse, `base_radius_km` 40 [10–150],
  `base_region_ids`/`base_country_codes` JSON) + settings field (town pick or pin,
  truncation at write) + derivation service (ST_DWithin, containing region always
  included, cap 8, transactional/queued re-derivation).
- My area as default scope: circle spotlight, union-bbox viewport, derived-set
  best-of merge with LIMIT, widening ladder My area → country/-ies → everywhere.
- Cold start: "Set my area" prompt from map centre; anonymous localStorage-only
  circle.
- Privacy: frozen profile exposure list, no public derived distances, privacy-copy
  update.

**Phase 5 — Worldwide rollout (per-country, incremental).**

- Region seeding per this spec's section 5a (curator-demand-driven, curator-sized
  jurisdiction sets over official-line atoms; the ~16,900 km² band advisory only —
  adopted 2026-07-19); coverage expansion (`COVERAGE_REGIONS` env/CLI
  default + `COUNTRY_BY_REGION` map in `pipeline/coverage/run.py` — env/config
  change plus the dict, verified) is the upper bound, not the trigger; per-country
  opt-in after disputed-territory review.
- Unsplit-country country stamping (verified gap): country-polygon fallback in
  `SpatialResolver` (country areas from the same Overture import) and de-hardcode
  `'cc' => 'BE'` / Belgian ProvinceMap in `importItemLayers` — required before the
  first non-BE seeding, since country-scoped curators in unsplit countries currently
  match only via the NULL-region safety net.
- Hard gate first: country-grouped typeahead moderator-areas picker + per-region
  queue-health counts.
- Per-region `active_cap` tuning; CITIES quick-picks generalised per scope;
  NULL-region noise audit as tessellation grows.

## 8. Risks

1. **Silent moderation hole until Phase 1 ships** — regions imported before the
   importer fix are invisible to country-scoped curators. Phase 1 is a hard gate.
2. **Tile-prop rollout coupling** — props appear only after the weekly rebuild;
   client tolerates prop-less tiles (fallback ladder documented).
3. **Everywhere best-of is the flagged unbounded aggregate** (route-domain.md §12) —
   guard hoisted to Phase 1.
4. **Boundary churn on re-import** shifts membership and derived base sets —
   from-scratch recompute is the existing design; slugs/ISO codes are identity;
   re-derivation transactional/queued; region rows never deleted.
5. **Base location is attack-tested personal data** — coarse storage alone is
   insufficient if derived outputs leak (85 % EPZ reversal). Named invariants in
   section 4.
6. **Admin boundaries mismatch riding communities in some countries** (giant Bavaria,
   micro-cantons) — per-country operating level via the calibration band; My-area
   default does the rider-side smoothing.
7. **Disputed territories politicise seeding** — per-country opt-in review; unsplit
   countries work via country rows + safety net.
8. **NULL-region queue noise grows with curators until tessellation broadens** —
   accepted; volume-triggered seeding playbook; Phase 5 audit.
9. **Photon dependency deepens** (scope-derived bbox) — keep silent degradation;
   consider self-hosting if town search becomes core.
10. **Scope params fragment the shared HTTP cache keyspace** on coverage endpoints —
    bounded by coarse rounding + small id sets; monitor hit rates after Phase 3/4.
11. **Antimeridian-spanning regions break both bbox paths** (07-21 review, info a):
    `RegionRegistryProvider` publishes `ST_XMin/ST_XMax` extents and
    `CCScope.bbox()` takes a naive min/max union — a region crossing 180°
    (Chukotka, Fiji, NZ incl. the Chathams) yields a world-wrapping box, so the
    viewport fit, Photon bbox and widen-chip bbox all go wrong. Fix (split
    boxes or lon-normalised union) is REQUIRED before seeding any such country
    — add it to the section 5a per-country seeding checklist when it happens.

## 9. Open product questions

1. **Default precedence:** ~~base location set → My area wins over last-used named
   region — confirm.~~ **Decided 2026-07-19 (owner, via plan review): My area wins
   whenever a base location is set.**
2. **Brussels cap:** ~~proposed start 10, tune with data — sign off.~~ **Decided
   2026-07-19: BE-BRU `active_cap` starts at 10, tuned with data.**
3. **Radius bounds:** ~~default 40 km, clamp 10–150 — sign off; slider v1?~~
   **Decided 2026-07-19: bounds confirmed (10–150 km, default 40 km); the radius
   slider ships in v1 of the settings field.**
4. **Anonymous base location:** ~~localStorage-only circle acceptable? Device-location
   suggestion?~~ **Decided 2026-07-19: yes — localStorage-only client circle; no
   device-location prompt.**
5. **Jurisdiction sizing — owner direction 2026-07-19 (supersedes the "who signs off
   operating levels" framing):** countries start **unsplit**; region seeding is
   triggered by curator demand, and the curator chooses the size they can stand
   behind — a Wallonia-sized chunk, a bigger request, or a small area they know and
   can verify in person; others can join a region; jurisdictions may merge later;
   keep it open and let it evolve. **Decided 2026-07-19: recommendation adopted —
   curator-sized jurisdictions composed as sets of official-subdivision rows,
   demand-driven seeding, no freeform polygons, governed per section 5a (country
   moderators self-govern under wiki/governance.md subsidiarity).**
6. **Named-region browse pages:** ~~later phase or explicitly parked?~~ **Decided
   2026-07-19: later phase — stays in the spec (AllTrails-style SEO directory over
   the same region rows, e.g. /regions/wallonia).**
7. **Everywhere scope in v1:** ~~ship guarded in Phase 2, or hold for Phase 3?~~
   **Decided 2026-07-19: ship guarded (hard LIMIT) in Phase 2.**
