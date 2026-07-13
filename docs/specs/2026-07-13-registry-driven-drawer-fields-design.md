# Registry-driven map-drawer fields — design

**Date:** 2026-07-13
**Branch:** symfony-base
**Status:** approved (brainstorm), pending implementation plan

## Problem

The map detail drawer decides *which* per-type attributes to show from a
hand-maintained whitelist in `web/assets/map/map.js` — `POI_ATTR_FIELDS`
(lines 256-270) for the bulk point layers, plus bespoke per-type blocks for
climbs (`1146-1154`), routes (`637-682`), surface (`688-704`) and water
(`322-327`). This duplicates the server's `CatalogFormRegistry`, and the two
have **drifted**:

- Climbs' additional options `waterOnClimb`, `hairpins`, `shade` are collected
  by the improve form but never rendered — a set value is silently invisible.
- Every future field added to a form must be hand-copied into `map.js` or it
  won't show.

Two further consequences of the hand-copied list:

- **i18n:** the labels in `POI_ATTR_FIELDS` are baked English strings in JS, so
  they never localize for FR/NL/DE (the app is day-one four-language).
- **No contribution affordance:** the drawer only renders fields that already
  have a value, so a rider viewing a sparse item sees no hint that, e.g.,
  "Tools available" is a thing they could add.

## Goal

Make the improve form's field registry the **single source of truth** for the
drawer. Every field a type declares renders in the drawer — filled with its
value, or shown as an empty prompt inviting a contribution. Labels localize in
one place. The whitelist and the per-type attribute blocks in `map.js` are
deleted.

Non-goals: changing the moderation/approval flow, the catalog geometry
pipeline, or the structural/derived rows (see below). Two related bugs are
logged separately in `docs/TODO.md` (empty deep-link drawer for OSM-dot items;
hazards `F` not catalog-served) and are **out of scope here**.

## Design

### 1. Canonical DTO (`CatalogField` / `ItemFieldSet`)

`CatalogField` already is the field DTO (`name`, `label`, `kind`, `choices`,
`placeholder`, `default`, `required`, `maxLength`) and `ItemFieldSet` groups
`fields` (main) + `addFields` (additional). It is already consumed by
`ImproveType` (form) via `$field->label` / `$field->kind`. Two changes make it
the shared contract for the drawer too:

- **`display` flag** (`bool`, default `true`). Intake-only fields — the `name`
  title field and the free-text `correction` "anything wrong?" textareas — are
  marked `display: false` so they never render as drawer *data* rows (they are
  inputs, not facts). Genuine `note` / "Anything to add?" fields keep
  `display: true` (they read as content). This encodes, on the DTO, the same
  distinction the old whitelist made by omission.
- **`label` is a translation key.** Field labels move into a `catalog`
  translation domain, seeded EN/FR/NL/DE. `ImproveType` and the drawer both
  read the localized label; there is exactly one definition per field.

### 2. Schema provider + delivery

New `App\Catalog\CatalogSchemaProvider`:

- `displayFields(ItemType): list<array{key,label,kind}>` — walks
  `CatalogFormRegistry::for($type)`, keeps `display: true` fields in
  `fields`-then-`addFields` order, and returns each as `{key: field.name,
  label: <translated>, kind: field.kind->value}`.
- `all(): array<letter, list<...>>` — the same for every `ItemType`, keyed by
  catalog letter (`A`…`K`).

Delivery: the map controller injects the result into the page as
`window.CC_FIELD_SCHEMA = { A:[…], C:[…], D:[…], … }`, **localized per request**.
It stays **out of `catalog.json`**: that endpoint is locale-agnostic and
HTTP-cached (`security.yaml` PUBLIC_ACCESS), whereas labels must vary by locale.
The schema is small and static per locale, so it rides on the page like
`CC_CATALOG_URL` does today.

### 3. Drawer rendering (`map.js`)

Delete `POI_ATTR_FIELDS` and the per-type attribute blocks. Add one generic
helper — `schemaRows(letter, props, itemId)` — that iterates
`CC_FIELD_SCHEMA[letter]` and, for each field:

- **value present** (`props[key]` truthy) → a normal row `{label, value}`,
  formatting list values (multiselect) as chips as today.
- **unset** → a muted prompt row with an "add" affordance linking to
  `/improve?item=<id>&type=<letter>&field=<key>` — the existing edit-bridge
  (`/improve` applies the login gate). Mirrors the visibility of the drawer's
  current "✎ Edit this item" link (shown when `item.id` is present and the
  layer is editable). The `field=<key>` param is a best-effort focus hint —
  `/improve` honouring it (scroll/focus the matching field) is a nice-to-have;
  the row works as a plain deep-link if it is ignored.

Layout: **flat list, every field visible** (filled or empty), no collapsing —
maximum contribution invitation, per the approved UX choice.

Each drawer builder keeps its **structural / derived rows**, which are *not*
registry attributes and stay hand-authored: Type, Town, Province, Source,
website, the "Proposed · ride it to verify" status badge, the difficulty badge,
and routes' derived `Surfaces` breakdown (with its `method` estimate note).
`schemaRows(...)` produces the declared-attribute rows that used to come from
the whitelist; a set attribute row replaces a structural row of the same label
(the existing dedup-by-label rule is preserved).

Applies uniformly to the point layers (services/stays/transit/shelter/scenic/
history), water (C), climbs (B), surface (A) and routes (K).

### 4. i18n

Labels resolve through the translator (`catalog` domain, four languages) at
schema-serialize time and in the form. No English literals remain in `map.js`
for these labels.

## Data flow

```
CatalogFormRegistry::for(type)  ──►  CatalogField[] (DTO, display flag, label key)
        │                                   │
        ▼                                   ▼
   ImproveType (form)              CatalogSchemaProvider (localize labels)
                                            │
                                            ▼
                          window.CC_FIELD_SCHEMA  (per-locale, on the page)
                                            │
                                            ▼
                    map.js schemaRows(letter, props, id)  ──►  drawer rows
                          (value row  |  empty "add" prompt)
```

## Testing

- **Server:** `CatalogSchemaProvider::displayFields()` returns the expected
  ordered fields per type; excludes `name` / `correction`; labels come out
  localized (assert a non-EN locale differs). `all()` covers every letter.
- **Page:** the map response embeds `CC_FIELD_SCHEMA` for the active locale.
- **Drawer (browser):** a filled field (item 11010 `tools`) renders its value;
  an unset field renders the "add" prompt with the correct `/improve?...`
  href. Verify the previously-dropped climb `addFields` now appear when set.

## Rollout notes

- Deleting `POI_ATTR_FIELDS` + per-type blocks is a net simplification; the
  bespoke structural rows are the only hand-authored per-type logic that
  remains.
- The `catalog` translation domain seeding (≈60 field labels × 4 languages) is
  mechanical but real work; it is part of this change, not a follow-up, since
  i18n correctness was an explicit requirement.

## Execution notes (2026-07-13, symfony-base 04a1a3a..25588e7, NOT pushed)

Implemented via subagent-driven-development (11 tasks + final whole-branch
review). Deviations / decisions taken during execution:

- **Ratings render as stars, not digits (product decision).** Rather than the
  generic renderer showing route `quietness`/`scenic`/`friendliness` as bare
  digits, `CatalogSchemaProvider` emits `kind: 'rating'` for any select whose
  choices are exactly `['1','2','3','4','5']`, and `schemaRows()` renders those
  via the shared `stars()` glyph helper (hoisted to outer scope; the dead inner
  duplicate was removed). Star detection is registry-derived, no hard-coded keys
  in map.js.
- **i18n parity required editing all four locale files, not just fr/nl/de.**
  `tools/check-translations.sh` compares fr/nl/de against **en** and fails on
  *extra* keys, so the 72 display labels were added to `messages.en.yaml` too
  (English identity values). All four files went 1263 → 1335 keys, in parity.
- **`url`-kind fields linkify.** During final review a regression was caught:
  once `web` (stays "Website") became a registry-driven display field, a *plain*
  schema row was deduping away the structural *linkified* Website row. Fixed by
  adding a `url` branch to `schemaRows()` that renders via the same safe
  `links:[]`/`safeHref` path as the bespoke row — restoring the link and also
  linkifying `bookingLink`. The structural Website row is retained for non-E
  layers whose OSM payload carries a `website`.
- **Accepted trade-offs:** per-row `method:'OSM'` provenance tags on the surface
  drawer's Surface/Smoothness rows were dropped (provenance now shows only on the
  Source line); route `season` renders as chips (multiselect) rather than a
  dot-joined string.
- **Deferred (recorded for a native-review follow-up):** minor FR/NL/DE wording
  polish (e.g. NL `helling` reused for both "ramp" and "gradient"); the map page
  is header/session-locale-driven, not `/{_locale}/map` path-prefixed — a
  separate routing question if the app standardises on locale prefixes.
