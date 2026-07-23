<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Border-overlap ownership + region_id on re-harvest — design

**Status:** design, not executed. Measured against the live dev DB 2026-07-23
(BE + NL + DE, 375,078 `coverage_poi` rows).
**Audience:** contributors to the coverage pipeline.

## 1. What is actually wrong (measured, not assumed)

Geofabrik's per-country extracts **overlap at shared borders**, so one OSM entity
arrives in more than one extract. `load_region` resolves that with
`ON CONFLICT (ref, letter) DO UPDATE` — **last writer wins** — and the row's
`src_region_id` becomes whichever extract ran most recently.

Measured today:

| owning extract | stamped country | rows |
|---|---|---|
| `europe/germany` | DE | 317,693 |
| `europe/germany` | **NL** | **136** |
| `europe/germany` | **BE** | **58** |
| `europe/netherlands` | NL | 34,354 |
| `europe/netherlands` | **BE** | **125** |
| `europe/belgium` | BE | 22,712 |

**319 rows are owned by an extract from the wrong country.**

What is NOT wrong — worth stating, because it narrows the fix:

- **`country_code` is correct on every row.** 0 rows where the stamped country
  disagrees with the region's country. The "region ⇒ cc" step in `load_region`
  treats the region as authoritative and actively corrects a neighbour extract's
  wrong stamp, so a border entity reads BE even when Germany's extract wrote it.
- **`region_id` is broadly correct.** 380 of 375,078 rows (0.1 %) carry no region
  at all. 3,431 (0.9 %) sit in a region whose polygon does not strictly contain
  them — but that is the deliberate `BOUNDARY_SNAP_DEG` rescue for
  polygon-simplification gaps, not corruption.

So this is **not a data-correctness bug today**. It is an ownership bug with two
real consequences and one structural one.

## 2. Why it still has to be fixed

1. **A border entity can vanish for up to a week.** `load_region` deletes its
   whole slice (`DELETE WHERE src_region_id = :sid`) and re-inserts from staging.
   If entity X is owned by the Netherlands run but Geofabrik trims it out of the
   NL extract, the NL run deletes X and nothing re-creates it until Belgium's run
   comes round. X is on the map one day and gone the next, with no error.
2. **The drift-abort guard is noisy.** `DRIFT_ABORT_RATIO` compares this run's row
   count against the previous run *for the same `src_region_id`*. Because border
   entities flap between owners week to week, that baseline moves for reasons
   that have nothing to do with the extract's real content.
3. **It blocks partitioning** (storage backlog issue 5). Partitioning
   `coverage_poi` by `src_region_id` — the fix for the compaction/bloat story —
   requires the unique key to include the partition key. The global
   `UNIQUE (ref, letter)` exists precisely *because* ownership is
   nondeterministic. Make ownership deterministic and the constraint can become
   partition-local, which unblocks the partitioning work.

## 3. The fix: decide ownership by geometry, not by write order

Assign every entity to exactly one extract **before** it reaches
`coverage_poi`, by the same point-in-polygon machinery that already stamps
`region_id` and `country_code`.

**Rule:** resolve the entity to the **single nearest region** within
`BOUNDARY_SNAP_DEG`, ordered by distance, then `area_km2`, then `id`. The extract
owns the entity iff that region's country is the extract's own. An entity in the
Netherlands is owned by `europe/netherlands` no matter how many extracts contain
it, and Germany's run skips it.

The lookup deliberately does **not** reference the asking extract, so every
extract computes the same winner — that is what makes ownership exclusive rather
than merely narrower. `region.id` is the primary key, so the ordering is a strict
total order and a row exactly on a national border still gets one deterministic
owner.

> **Correction, 2026-07-23 (found in final review, before any harvest ran).**
> This rule first read *"iff the entity's location falls inside a region belonging
> to that extract's configured country"*, and was implemented — following decision
> 2's "reuse the snap" — as `ST_DWithin(r.geom, s.geom, BOUNDARY_SNAP_DEG)` with
> `r.country_code = <extract cc>`. **That predicate is not mutually exclusive.**
> Geofabrik's overlap buffer reaches **0.1026°** into a neighbour's territory,
> about ten times `BOUNDARY_SNAP_DEG` (0.01°), so the entire snap allowance sits
> *inside* the overlap zone where both extracts carry the entity and both filters
> accept it. Ownership then fell back to `ON CONFLICT`, i.e. the very
> last-writer-wins this design removes. Measured on the live table: 1,691 rows had
> two or three claimants, and **313 of the 319 mis-owned rows would have stayed
> mis-owned** — the fix would have corrected 6.
>
> Nearest-wins repairs it while preserving decision 2 exactly: containment scores
> distance 0 and always beats proximity, and a row just outside its own country's
> region is still owned and still snapped. Validated independently three times
> against all 375,078 live rows — 374,695 rows keep their current stamp, 3 change
> (genuine equidistant border ambiguity, made self-consistent downstream by the
> `region ⇒ cc` step), 380 get no owner, which is decision 1.
>
> Lesson worth keeping: "inside a region of my country" and "within the snap of a
> region of my country" are not interchangeable, and the difference is invisible
> until you measure the extract overlap against the snap tolerance.

Consequences:

- Ownership stops depending on run order, so `src_region_id` stops flapping.
- Each entity is refreshed by exactly one extract, on that extract's schedule —
  the week-long disappearance in §2.1 becomes impossible.
- The drift baseline becomes stable.
- `UNIQUE (ref, letter)` can become partition-local, because two extracts can no
  longer produce the same `(ref, letter)`.

### Where it goes

The natural home is the **staging step**, not the upsert: filter the staged rows
to those the running extract owns, before the `DELETE`/`INSERT` swap. That keeps
one transaction and one atomic slice swap.

The check needs `region.geom`, which the pipeline already queries in the
membership steps. Cost: one point-in-polygon pass over the staged rows per run,
against the running country's regions only.

### Cases settled by the owner (2026-07-23)

**1. An entity in no onboarded region at all → DROP at staging.**

Measured before deciding: all 380 are foreign or offshore, not "in the country but
outside every province". That case is already rescued by the 1.1 km snap; these
survived it.

| stamped | rows | nearest region | what it actually is |
|---|---|---|---|
| DE | 195 | `sachsen` (0.7–14.1 km) | across the Czech / Polish border |
| DE | 122 | `bayern` (0.8–10.4 km) | across the Austrian / Czech border |
| DE | 24 | `baden-wurttemberg` (0.9–18.6 km) | across the Swiss / French border |
| DE | 29 | various (0.7–3.2 km) | scattered border and coast |
| NL | 4 | `friesland` (1.0–2.7 km) | Wadden Sea |
| NL | 1 | `noord-holland` (5.1 km) | offshore |
| BE | 5 | `wallonia` (0.8–5.4 km) | France and Luxembourg |

278 of the 380 are letter `I` (scenic views) — viewpoints just over the Czech and
Austrian borders that Geofabrik's cut overshoots into Germany's extract.

Keeping them means continuing to serve a Czech viewpoint as `country_code = 'DE'`
under "All Germany". Dropping is reversible in the only way that matters: onboarding
CZ/AT/PL/CH/FR/LU re-harvests them **with correct region stamps**.

Rejected: keeping the row but stripping its `country_code` so it cannot appear under
a country scope. It does not work — `scope.js`'s `coverageTileFilter()` treats a row
with neither a region nor a country token as *prop-less*, which **renders under every
scope** by design (so a stale tile artifact never blanks the map). A stamp-less Czech
viewpoint would therefore appear under a Dutch region scope. Distinguishing "outside
coverage" from "prop-less/stale" needs a new third state in the tile filter — real
scope creep on a determinism fix.

**2. The boundary-snap rows (3,431) → ownership reuses today's snap UNCHANGED**
(`BOUNDARY_SNAP_DEG = 0.01`, ~1.1 km, same-`country_code` guard). This change does
exactly one thing: make ownership deterministic. Nothing regresses and the diff stays
reviewable.

Distance distribution, for whoever revisits the tolerance:

| outside by | rows | cumulative |
|---|---|---|
| ≤100 m | 1,180 | 34 % |
| ≤200 m | 1,845 | 54 % |
| ≤300 m | 2,291 | 67 % |
| ≤500 m | 2,793 | 81 % |
| 500–1,094 m | 638 | 100 % |

**Known and accepted consequence:** see §3.1 — the snap's country guard is unsound,
so a genuinely foreign entity within 1.1 km of our border keeps a wrong owner. That
is the status quo; this change neither fixes nor worsens it.

**3. Backfill → fold into the pending tag-trim re-harvest.** Land the ownership
change first, then run ONE full re-harvest of all three extracts. That fixes the 319,
applies the `storedTagKeys` trim (storage backlog issue 4 step 2) and rebuilds the
coverage tiles in a single pass, with no separate corrective migration to write,
test and review.

### 3.1 Prerequisite finding: the snap's country guard is unsound

Not part of this change, but it must be recorded, because §3's phrasing ("ownership
must use the same snap tolerance") would otherwise read as an endorsement.

`pipeline/coverage/load.py:247` snaps an unstamped POI to the nearest region where
`r.country_code = c.country_code`, commented as *"Constrained to the same
country_code so a true country-border row is never pulled across"*. But at that point
`c.country_code` is **stamped from extract config** (`load.py:64`) — it is the
extract's country, not the entity's. So a Danish entity in Germany's extract arrives
as `DE`, matches a German region, snaps in, and the `region ⇒ cc` step then confirms
`DE`. The guard compares a value that is already wrong.

Evidence, self-proving because the POI names its own country:

```
way/964406030   9.4316, 54.8322   0.70 km outside schleswig-holstein
  {"amenity":"shelter", "shelter_type":"lean_to",
   "website":"https://udinaturen.dk/shelter/105512"}
```

`udinaturen.dk` is the Danish Nature Agency's shelter registry. 327 rows carry a
foreign TLD under an onboarded stamp, clustering on Germany's land borders (cz 83,
at 65, fr 55, ch 32, dk 21, lu 21, pl 21; 309 of them region-stamped). A foreign TLD
is a signal, not proof — border businesses do use a neighbour's domain — but the
`.dk` government-registry case is unambiguous.

Fixing this properly needs real national frontiers (a DK/CZ/PL/AT/CH/FR/LU boundary
source), so that "is this ours" stops being inferred from extract config. The
measurement it needs first is the simplification tolerance of our own `region.geom`,
without which any tightened snap threshold is a guess that would orphan
legitimately-German rows to catch foreign ones.

**Owner disposition (2026-07-23): PARKED, not urgent.** *"A tiny bit of spot for a
wrong region is not that bad. It is just a cycling map."* A handful of border POIs
attributed to the neighbouring region is an acceptable standing cost — a rider who
finds a shelter 700 m over the border is helped, not harmed. Do not fold this into
the ownership change, and do not re-raise it as a defect: it is a known, accepted,
low-severity inaccuracy. Revisit only if a country onboarding makes the frontier data
worth importing for its own sake.

## 4. Verification this needs

- A pipeline test with two synthetic overlapping extracts and an entity in the
  overlap, asserting the same extract owns it regardless of load order — run the
  loads in both orders and assert an identical `src_region_id`.
- A test that an entity dropped from its owner's extract disappears on that
  owner's next run, and that an entity present in a *non-owning* extract is not
  resurrected by it.
- Post-change query: the §1 table must show zero rows whose owning extract's
  country differs from the row's `country_code`.
- A test that an entity in no onboarded region is **not staged** (decision 1), and
  that a row within `BOUNDARY_SNAP_DEG` of a region of the extract's country still
  is (decision 2) — the two decisions meet at that boundary and must not be
  conflated.
- Post-change count: `coverage_poi WHERE region_id IS NULL` must be **0**, down
  from 380. This is the cheapest single check that decision 1 actually took effect.

## 5. Relationship to other work

- **Storage backlog issue 5** (partition `coverage_poi` by `src_region_id`) is
  blocked on this and should follow it directly.
- **Storage backlog issue 4** step 2 (the `storedTagKeys` trim) needs a
  re-harvest to take effect on existing rows. Doing that re-harvest *after* this
  change lands means one harvest instead of two.
- `docs/specs/coverage-provider.md` §3 documents the current last-writer-wins
  behaviour and must be updated when this ships.
