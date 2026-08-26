<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Edit spec — E · Hazards & conditions

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** E · Hazards & conditions
- **Map depiction:** ⚠ pin, colour #C8923A
- **Edit-item id:** `exposed-crosswind-hautes-fagnes` in `atlas/demo/edit-items.js`, dynamic — needs freshness
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Persistent, real hazards — crosswind and fog exposure, ice, loose surface — kept fresh by rider confirmations so stale warnings age out.

## Read view (drawer "current details")
- Type
- Severity
- Seasonal

## Edit form  (`improve.html?item=exposed-crosswind-hautes-fagnes`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Hazard type | select(Crosswind / fog / Ice / frost / Loose surface / gravel / Flooding / Roadworks / **Road closed** / Other) | `[edit]` |
| If closed, for how long? | select(Unknown / Today / Days / Weeks / Months) | `[edit]` |
| Severity | select(Low / Moderate / High) | `[edit]` |
| When is it worst? | select(Autumn / winter / Year-round / After rain / Windy days) | `[edit]` |
| Still present? | select(Yes — confirmed today / Reduced / Gone — clear now) | `[tap]` |
| What did you see? | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Alternative / detour | input | `[edit]` |
| Time of day | select(Any / Morning / Afternoon / Evening) | `[edit]` |

### Report a problem
- Resolved / gone · Wrong location · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Closures expire themselves

**Road closed** is the one hazard with an end date, and the only reason the
duration field above exists. The reporter's own answer sets the window
(`App\Catalog\ClosureLifetime`): Today 2 days · Days 10 · Weeks 42 ·
Months 180. Past it, the item is moved to `retired` and stops being served.

- **The clock starts at the last sighting, not at creation.** An existence
  confirmation restarts the full window — the "unless re-confirmed" half of
  data-priority.md's promise — so a long roadworks closure that riders keep
  confirming never falls off the map. A `form`-sourced confirmation does not
  count: that is the submitter answering their own contribution, the same
  exclusion the verified tier makes.
- **`observedOn`**, when present, overrides the row's creation date. Scout
  writes it, because a rider taps the tag on the road days before uploading.
- **Unknown is bounded, not forever.** It is the one answer with no duration in
  it, and letting it mean "never expires" would reproduce exactly the lie
  Manifesto §VII names, so it gets the longest window and then has to be
  re-reported.
- **Retire, never delete.** `retired` is already outside the served states, so
  nothing in any serving path changed. The row, its history and its
  confirmations all stay: the closure was true when it was reported, and that
  record is what makes a repeat closure legible next year.
- **Recorded, not silent.** The expiry writes a `state` row to `change_history`
  with `changed_by = 0` (`ChangeHistory::SYSTEM_ACTOR`), so an item's public
  history explains the disappearance. The history endpoint emits the token
  `system` rather than a translated word, because it is publicly cached and its
  body must not vary by locale; the client renders `d_history_auto`.

**Something has to run it.** `app:catalog:expire-closures` (dry-run by default,
`--write` to act) belongs on the worker host beside `app:moderation:gc` and
`app:media:gc`. Until those timers exist, `/map/catalog.json` sweeps
opportunistically at most once an hour — a safety net, not the mechanism.

## Implementation
- **Demo:** registry entry `exposed-crosswind-hautes-fagnes` in `atlas/demo/edit-items.js` (hand-picked fixture data).
- **Production:** community report + freshness decay (confirmations age out); safety-tagged, never auto from OSM.
