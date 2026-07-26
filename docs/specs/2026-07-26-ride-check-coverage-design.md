# Ride-check ∪ coverage (design)

**Status:** EXECUTED 2026-07-26. Backend `517dc49`; `ref` + controller-test
provisioning `99a016f`; frontend `bb65fe6`. See §7 for what changed against this
design during execution — §3.3's premise about the curated arm was wrong.
**Scope:** extend the GPX ride-check corridor search to also surface open
`coverage_poi` utility points (water/services/transport/shelter) along the
route, deduped against curated items.
**Refs:** `web/src/Catalog/RideCheckService.php`, `docs/specs/map-and-search.md`
§9, `web/assets/map/map.js` (ride-check rendering).

## 1. Problem

Ride-check ("what's along my route", map-and-search.md §9) lists curated
`item` rows and followed `recommended_route`s inside a corridor of the uploaded
GPX. It ignores `coverage_poi` — so the open coverage layer's water points, bike
services, ferries/train halts and shelters along the ride are invisible unless
someone curated them. A rider planning refills/bailouts sees a sparser picture
than the map's Everything mode already holds.

## 2. Owner decisions (2026-07-26)

- **Letters: C/D/G/H (utility only).** Water/bakery (C), bike services (D),
  transport — ferry/train (G), shelter (H). Experiential E-stays / I-scenic /
  J-history are left to the curated arm, where they overlap most and add corridor
  noise.
- **Dedup: curated wins.** A `coverage_poi` whose `ref` matches a **served**
  curated `item`'s `source_ref` **and** letter is excluded from the coverage
  arm — the same real-world thing is shown once, as the curated pick. (Dedup is
  against *served* items only, i.e. exactly what the curated arm displays, so a
  pending/rejected item does not hide its coverage POI.)
- **Presentation: separate `coverage` arm.** The result gains a `coverage` array
  parallel to `groups` (curated) and `routes`. The frontend renders coverage
  points with the smaller coverage-style icon; curated items keep their bigger
  spot icons — so the two are visually distinct on the map, deduped so nothing
  is drawn twice.

## 3. Design

### 3.1 Service — a third corridor arm

Add `private function corridorCoverage(string $geoJson, int $radiusM, float $rawM): array`
to `RideCheckService`, mirroring `corridorGroups()` exactly (same
`MATERIALIZED track`/`corridor` CTE that turns containment into an index-served
`ST_Intersects`, same `ST_LineLocatePoint` along-the-ride ordering, same
`MAX_PER_LETTER` truncation and `representativeLatLng` anchor). Differences:

- `FROM coverage_poi cp` instead of `item i`.
- `WHERE cp.letter IN ('C','D','G','H')` (a class constant `COVERAGE_LETTERS`).
- Dedup: `AND NOT EXISTS (SELECT 1 FROM item d WHERE d.source_ref = cp.ref AND
  d.letter = cp.letter AND d.state IN <servedSqlTuple>)`.
- `coverage_poi.geom` is always a Point, so the anchor is the point itself.
- No `state` column on `coverage_poi` (it is a pipeline cache) — the served
  filter applies only to the dedup subquery, not to `coverage_poi` itself.

`ST_Intersects(cp.geom, corridor)` rides `coverage_poi_geom_idx` (GIST). The
dedup subquery matches on the `(source_ref, letter)` half of item's
`uniq_item_source_ref_letter` index. `coverage_poi` and `item` are co-located on
CC's own cluster (osm-data-architecture / the #9 harvest-prod-safety move), so
this stays a local join — no cross-DB concern.

Return shape mirrors a group list:
`list<array{letter: string, items: list<array{id, name, ll, distM, alongKm}>, truncated: bool}>`
where `id` is the `coverage_poi.id` (numeric, for the marker key; the
authoritative identity is `ref`, but ride-check only needs a stable marker key
and a name/anchor). Add `coverage` to `check()`'s return array and its docblock
shape.

### 3.2 Controller

No change — `RideCheckController` serializes the whole service result to JSON;
the new `coverage` key flows through automatically.

### 3.3 Frontend (`map.js`)

The ride-check renderer draws `groups` as per-letter marker sets + a panel list.
Render `coverage` the same way but with the **coverage marker style** (the
smaller icon the Everything layer already uses) so curated (bigger spot) vs
coverage (smaller) are visually distinct, and label the coverage section in the
panel as open-coverage (distinct from curated). Reuse the existing coverage icon
styling already in `map.js`; no new icon assets. *(This rendering lands in the
map.js ride-check code; it pairs naturally with the #1 map.js split since it
touches the same region.)*

## 4. Deliberately unchanged

Radii, length bounds, the MATERIALIZED corridor idiom, the no-privacy-trim
stance (§ service docblock), letter-A exclusion, rate limit, CSRF/auth. No
schema change, no migration.

## 5. Testing

Extend `web/tests/Catalog/RideCheckServiceTest.php` (real PostGIS, the existing
fixture pattern):

- **Coverage in corridor:** a `coverage_poi` C/D/G/H point inside the corridor
  appears in `coverage`, grouped by letter, with `alongKm`/`distM` set; one
  outside the corridor does not.
- **Letter filter:** an E/I/J coverage point in the corridor is excluded.
- **Dedup:** a coverage point whose `ref` matches a served `item`
  (`source_ref` + letter) is excluded from `coverage` (shown only in `groups`);
  the same `ref` with a NON-served item still appears in `coverage`.
- **Truncation:** > `MAX_PER_LETTER` coverage points in one letter set
  `truncated: true`.
- **Ordering:** coverage points come back ordered by along-the-ride fraction.

`web/tests/Controller/RideCheckControllerTest.php`: assert the JSON response
carries the `coverage` key.

## 6. Task breakdown (proportionate — small feature on an existing template)

1. Service: `COVERAGE_LETTERS` const + `corridorCoverage()` + wire into
   `check()` + docblock shape. TDD against `RideCheckServiceTest`.
2. Controller test: `coverage` key present in JSON.
3. Frontend: render the `coverage` arm in `map.js` with the coverage icon style
   + panel section; browser-verify. (Pairs with #1 map.js split.)
4. Sync `docs/specs/map-and-search.md` §9 to mention the coverage arm.

## 7. Execution notes (2026-07-26)

- **§3.3's premise was wrong, and the design changed accordingly.** It describes
  the curated `groups` arm as "per-letter marker sets + a panel list". It is a
  panel list only — curated items are already drawn on the map by their own
  served layers, so the ride-check renderer never needed markers for them.
  Coverage POIs have no equivalent guarantee, so "render coverage the same way"
  would have meant listing points the rider cannot see. The resolution, decided
  with the owner and specified in `2026-07-26-map-js-module-split-design.md` §7,
  is a dedicated `ridecheck-cov` symbol overlay, independent of the coverage
  layer's on/off state, the region scope and the view mode. Verified against
  exactly that case: a Liège ride under an All-Netherlands scope still draws its
  coverage icons.
- **The arm needed `ref`, which §3.1 did not select.** As landed, the coverage
  arm returned `cp.id` — a `coverage_poi` row id that no endpoint accepts. The
  only lookup is `/map/coverage/poi/{ref}`, so every coverage result was
  unopenable. `cp.ref` is now selected and carried through `groupByLetter()` as
  an optional passthrough, leaving the curated arm's shape untouched.
- **§5's controller test could not have passed as written.** `check()` queries
  `coverage_poi` unconditionally, and that table is pipeline-owned DDL outside
  Doctrine's migrations, so `RideCheckControllerTest`'s happy path was 500ing on
  "relation coverage_poi does not exist" from `517dc49` onward. It now builds the
  table through the same `CoverageSchema` trait the service tests use.
- **Dev data makes the dedup look broken; it is not.** Curated fixtures carry
  synthetic refs (`fx:water:50.64079,5.5579`) while harvested coverage rows carry
  real OSM refs (`node/4019749189`), so `source_ref = ref` cannot match and the
  same fountain appears in both arms on the dev stack. Prod curates by forking
  coverage rows, which keeps the real ref, so the dedup fires there. Worth
  knowing before anyone "fixes" it.
- **Frontend location:** `web/assets/map/ride-check.js`, extracted from map.js as
  part of the split rather than added to the monolith and moved later. New i18n
  keys `d_along_track_cov_h` / `d_cov_arm_note` in all four locales.
