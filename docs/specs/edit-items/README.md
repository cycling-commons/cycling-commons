<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Edit-item specs — one per editable catalog type

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

Per the project rule that **every catalog item must have a designed edit flow** (not just the
service station): each editable type gets its own spec here, documenting what a contributor can
change, the field-level provenance, and how it's built in the demo vs. production. Having one per
type is the guarantee that we've thought through the design and implementation options for each.

**K is the deliberate exception** (since 2026-07-08): recommended routes are *curated
compositions* — riders propose (GPX + metadata), vote, confirm rides, and suggest corrections,
but never edit route data; curators own all edits. See
[`../route-domain.md`](../route-domain.md) and the rewritten
[K-quality-rides.md](K-quality-rides.md).

These specs are the **design source of truth** for [`atlas/demo/edit-items.js`](../../../atlas/demo/edit-items.js)
(the registry rendered by [`atlas/demo/improve.html`](../../../atlas/demo/improve.html)) and for the catalog
[`../catalog-data-model.md`](../catalog-data-model.md).

## Production implementation (Symfony)

The real, server-rendered port of this registry lives in the Symfony app:

- **Catalog source of truth** — [`web/src/Catalog/`](../../../web/src/Catalog/): `ItemType` (the A–M enum, L skipped
  carrying letter/label/icon/eyebrow, `locationMode` point/segment/none, and `isVotable()` per the funnel
  table below) and `CatalogFormRegistry` (each type's *Fix-details* + *Add-missing* fields, lifted from
  `edit-items.js` to per-type schemas). `LocationMode`, `FieldKind`, `CatalogField`, `ItemFieldSet` support them.
  For **K** the registry field set backs the *propose-route* and *curator* forms, not a rider improve form —
  `/improve` refuses `type=K` ([route-domain.md](../route-domain.md) §1).
- **Type-aware form** — [`web/src/Form/ImproveType.php`](../../../web/src/Form/ImproveType.php) builds the
  Details step from the registry; [`web/templates/contribute/improve.html.twig`](../../../web/templates/contribute/improve.html.twig)
  renders it and surfaces this **votability/lifecycle context** in the review step.
- **Reachability** — the contribute hub deep-links each card with `?type=<slug>`; the map drawer's Edit/Add-photo
  links use `?type=<letter>` (`ItemType::fromParam()` resolves either) — **except K**: the route drawer offers
  vote / "I rode this" / GPX download / suggest-a-correction instead of an edit link.

**Persistence** — item submissions persist for real since data-API phase B
([../moderation-and-contribution.md](../moderation-and-contribution.md));
route proposals bypass that pipeline entirely and land as `RecommendedRoute` rows (state `submitted`)
with purpose-built `route_vote` / `route_ride` / `route_suggestion` tables (route-domain.md).

## Registry-derivation contract (P1–P4)

The reconciliation principles that keep the contribution frontend honest — binding on
every type here:

- **P1 — one source of truth.** `CatalogFormRegistry` + `item.attributes` define an item
  type's editable shape. Forms (`App\Form\ImproveType`), map-drawer attribute rows
  (`CatalogSchemaProvider::displayFields()` served as `window.CC_FIELD_SCHEMA`, rendered
  by `map.js`'s `schemaRows()`), and the wizard review step ALL derive from it. No field
  is ever invented in a template or in `map.js`.
- **P2 — no hardcoded per-item display.** Every drawer row is either a stored attribute
  rendered with its provenance or a clearly-labelled derived value; decorative constants
  are banned.
- **P3 — honest review step.** The review step echoes exactly the fields that will be
  POSTed, with their entered values; empty fields are omitted.
- **P4 — everything on the curated map is a real DB item** (submittable, editable,
  moderatable). Hand-authored demo content is seed data with `source='manual'`
  (`App\Catalog\ItemSource::Manual`) — real items in the normal lifecycle, permanently
  distinguishable from `osm`/`pivot`/`wikidata`/`user`/`auto` — never code. Standing documented
  exception: the single hazard fixture pin (F), inlined in `map.js` with no serving path.
- **W5 — change history is user-visible** — see
  [Change history & field-level diffs](#change-history--field-level-diffs) below.
- **W6 — provenance renders uniformly** across ALL layers and item types; user/manual
  contributions and approved edits are legibly attributed wherever they surface.

**Character vocabulary (D2).** The filterable "what the place is like" attributes are
live registry fields (never "who it's good for" framing): **effort** (`B`, with a map
filter), road quality **`sq`** + traffic **`tr`** (`B`, with map filters), **famousFor**
and **approach** (`B` add-missing free text), **accessibility** (`E`, with a map
filter). The map filter chips' vocab lists mirror the registry's (`map.js`,
`ALL_EFFORT`/`ALL_ACCESS`), with narrowing semantics: with every chip on, items
with no value still show; deselect one and unvalued items hide too.

## Common to every type
These panes behave the same across all edit items, so the per-type specs don't repeat them:

**Moderation feedback, messages, retention & trash** *(built)* — every
contribution channel (item submissions A–J, route proposals, route corrections)
inherits the shared feedback system owned by
[../moderation-and-contribution.md](../moderation-and-contribution.md) (the
user-messages contract M1–M12, retention/GC, and Trash): every decision writes
the submitter a dashboard message (thank-you on approve/done, informing on
reject/dismiss; on item submissions, a needs-info request whose reply re-queues
the submission — route channels use curator messages instead), an unread bulb
on the account chip, curator↔rider pseudonymous messaging, 3-month retention +
GC for dismissed corrections and rejected item submissions, and an immediate
hard-delete **Trash** for spam. Per-type specs only note deviations.

**Setting the location (add mode, `?mode=add`).** The *first* action is always to set the location, and
it varies by type:
- **point** types (water, services, stays, hazards, getting-there, shelter, scenic, history) — tap the map
  to drop a single pin; the eyebrow coordinates update to the dropped point.
- **segment** (road surface) — tap the **start**, then the **end**; the segment line is drawn between them.
- **none** (quality rides) — no pin; the **GPX** track sets the whole route. Upload happens in the
  dedicated rate-limited **propose-route flow** (not `/improve` add-mode), with server-side validation,
  privacy trim, and distance/ascent computation (route-domain.md §4).
- **climbs** use the dedicated `/add-climb` wizard: draw the **foot**, then the **summit** — the
  road between them is auto-routed and a lockable **steepest** marker is placed
  (three-point definition — [B-climbs.md](B-climbs.md)).

**Report a problem** always includes an **Other** option (free-text) alongside the type-specific reasons.

**Media.** Uploads are real — the full contract is
[photo-uploads.md](../photo-uploads.md); this is the rider-facing summary.
- **Add photos** — several at once (six per submission): JPG · PNG · WebP · HEIC (iPhone),
  CC BY-SA 4.0. Format is decided by decoding the bytes, never by the filename.
- **Video is gone from the wizard** and deferred as its own future feature. Nothing in the
  contribution flow accepts video, and nothing pretends to.
- **Consent is asked when a photo is dropped**, about that photo — not as a gate in front of a drop
  zone nobody can use yet. The rider ticks that they license their photos under CC BY-SA 4.0 and took
  them themselves, with the licence one click away so they can read what they are agreeing to. The
  photos they picked wait while they decide: agreeing uploads them, dismissing discards them. The
  gate is fail-closed — nothing is uploaded until the SERVER has stored a consent record, a failed or
  ambiguous consent uploads nothing, and nothing is remembered in the browser. Once consent exists it
  is shown as a standing notice with its date on every later visit, and repeated beside the queued
  photos on the review step, rather than re-asking.
- **Real per-file progress**, driven by actual uploaded bytes, becoming a thumbnail on success and a
  named error on failure ("that photo is over 15 MB", "that file type cannot be used", …).
- **Link instead of upload:** paste a photo URL — **known sources** (Wikimedia, Flickr, Unsplash)
  have their rights-holder & licence read and validated automatically; **unknown sources** require the
  contributor to confirm rights-holder & licence manually. A link is reviewer context, never an
  upload: it is marked distinctly (🔗) and is not gated by the upload consent.
- Photo credits link the licence deed, and for imported photos the image source (the Wikimedia
  Commons file page) and the author's Wikimedia profile. A rider upload has neither: it is credited to
  the photographer's Commons profile when public, and to "an anonymous rider" otherwise.
- **Drawer galleries** support **multiple photos** per feature (main image + thumbnail strip), opened as
  a **slideshow** in the lightbox (‹ › buttons + ←/→ keys, per-photo credit, N/M counter). Photos are
  served responsively — the device picks the variant.
- **Location metadata is stripped.** Every inherited profile — EXIF including GPS, IPTC, XMP, ICC — is
  destroyed before storage. The coordinates are used exactly once, at intake, to record how far the
  shot was taken from the pin, and are then deleted; the Commons maps places, not riders. (Also
  stated on the public privacy page.) The one thing written back is the licence and a link to the
  photo's own page — never a name, so the credit stays revocable.

## Provenance tags
- `[OSM]` — already lives in OpenStreetMap (ODbL); we mirror/enrich it.
- `[auto]` — derived by the pipeline (e.g. gradient from DEM, quietness from traffic model).
- `[tap]` — one-tap community confirmation ("still here / still true").
- `[edit]` — a richer community edit (typed fields, ratings, notes).

## Change history & field-level diffs
Every catalog item keeps a **per-field change history** — the durable record behind the
`[edit]` tag. Each accepted change appends one entry:

- **what** — the field that changed, with its **old value → new value** (a field-level diff);
- **who** — the contributor (kept for provenance; identity stays opt-in public per the profile rules);
- **when** — a timestamp.

The history is **append-only** and per item: a submission is one entry, a later correction (by a
rider or a curator) is another. **For K (routes)** riders never author changes — curator edits and
state transitions write to a route-scoped `route_change_history` table instead (route-domain.md
§2.2); rider input arrives as moderated `route_suggestion` records. The history is the source of
truth for:

- the **moderation "was → now" diff** a curator reviews before deciding
  (see [../moderation-and-contribution.md](../moderation-and-contribution.md) §3.2 and §5);
- an item's public "last confirmed / last edited" line and the freshness signal;
- honest provenance — we can always show *what* changed and *when*, without exposing rider tracks.

**Persistence is real** — the `change_history` table
(`App\Catalog\Entity\ChangeHistory`, append-only: no code path may ever UPDATE or
DELETE rows) holds one row per applied field change. The write/apply contract
(including what `old_value` records) is owned by
[../moderation-and-contribution.md](../moderation-and-contribution.md). The history is
user-visible (W5): `GET /map/item/{id}/history` (`MapController::history()`, public,
cacheable, 200-with-empty-list for never-edited items) feeds the map drawer's
"Recent changes" section — field, old → new, anonymised rider pseudonym, when,
newest first — visible to everyone including the contributor for their own edits.

## Item lifecycle and votability

Every catalog item moves through a lifecycle. The **map's view-mode toggle (Curated best-of ↔
Everything — [map-and-search.md](../map-and-search.md) §4.2) is driven by _votability_, not
verification** — verification is only the gate that lets a votable item start collecting votes.

```
Submitted → [moderation: spam / abuse / duplicate — ROLE_CURATOR]   ← off the public map
   → Unverified  — public but unconfirmed; shows only in Everything as a small
                   "unverified · help confirm" dot; NOT votable
   → [verification gate: ≥ X independent community confirmations ([tap] "still here / still true")]
   → Verified    — a full pin.                     ◀── utility / coverage types stop here
   → Votable     — votable types only; now accrues votes
   → Best-of     — top-voted; this is what Curated best-of mode surfaces
```

- **X** (confirmations to verify) is **not one global number** — it's a tier + modifiers model
  (see [Verification threshold (X)](#verification-threshold-x) below).
- **Votable types** climb the funnel toward best-of: they are *rankable*, and riders vote on which are
  the best. **Utility / coverage types** stop at *verified* — they are **never votable and never
  "best-of"**, because their value is *completeness*, not ranking. A waterpoint isn't a best-of
  candidate; you just need to know it's there.
- **Two kinds of "unverified"** collapse to the same on-map treatment (a help-confirm dot in
  Everything): a fresh single-rider submission awaiting corroboration, and a bulk **`[OSM]` import** we
  mirror but haven't confirmed. Neither is votable until it clears the verification gate.
- Curated best-of surfaces items risen by community **votes**, not editorial hand-picking —
  moderation is a separate spam/abuse gate, not a quality ranking.

**View modes** (Curated best-of ↔ Everything) are owned by
[map-and-search.md](../map-and-search.md) §4.2; in these terms, **Curated best-of** surfaces
top-voted votable items only (the inspiration / trip-planning map) and **Everything** is full
coverage (the on-the-road / completeness map).

Per-pin state carries the trust/vote signal (unverified dot → verified pin → votable → best-of marker);
the toggle no longer stands in for "trusted". Each per-type spec below tags its **Lifecycle** row
accordingly.

**Route carve-out (K).** Recommended routes follow the same *shape* but a route-specific machine
([route-domain.md](../route-domain.md) §3): `submitted` proposals are
reviewed in a dedicated **Routes queue** (an editorial desk with a per-region active cap and
retire-to-admit, not the item spam/abuse gate); curator-approved routes render a **"proposed"
badge** (not the help-confirm dot); the confirmation is **"I rode this"** (`route_ride`, X
independent riders) rather than a generic tap; votes are **typed** (season + bike type) and open
only at `verified`; and the state set adds `retired`. For routes, supply *is* editorially
hand-picked — the community ranks within the curated set; the "votes, not hand-picking" principle
above governs the item types A–J.

### Verification threshold (X)

X is **not one global number** and **not eleven per-type knobs** — it's a small set of **tiers**
(assigned per type via the catalog) plus **cross-cutting modifiers**. It's a trust ↔ throughput dial:
too low verifies false positives (spam, stale, wrong-location); too high starves coverage in
low-traffic regions.

| Tier | Types | Base X | Behaviour |
|---|---|---|---|
| **Objective utility** | water *existence*, bike services, getting there | ~2 | existence is binary → cheap to confirm |
| **Experiential / votable** | climbs, where to sleep, scenic views, history | ~2–3 | verification only confirms it *exists*; the **voting** layer does the quality filtering, so no punishing bar |
| **Routes (K)** | quality rides | X = ~3 "I rode this" | route-specific: confirmation asserts *I rode it*, not *it exists* — `route_ride` rows from independent riders (route-domain.md §6.2 / §5.1, config key `route.ride_verify_threshold`); config, not constant |
| **Safety / time-sensitive** | hazards, shelter & emergency, the water *potable* flag | ~1 to publish | publish fast, then rely on **freshness decay** (the `freshness` field) — auto-stale after N days unless re-confirmed |

**Modifiers** adjust the base (floor 1): `[OSM]` provenance −1 (imports arrive source-vetted, and may
seed as verified-by-source / community-unconfirmed); trusted contributor or curator −1; bootstrap phase
or low-density region −1 (seed coverage early, tighten as the community grows).

**Notes**
- **A · road surface sits outside the `[tap]` machinery** — it is *measured*, carries no
  confirmation stances ([../moderation-and-contribution.md](../moderation-and-contribution.md)
  §10.1), and is excluded from `CC_CONFIRMABLE`
  ([../map-and-search.md](../map-and-search.md) §6.3); its verification signal is data
  provenance, not tap-confirmations.
- **Config, not constants** — base X and modifiers are tunable per region / launch phase without a deploy.
- **Risk can live at the field, not the item** — "this fountain exists" (low X) ≠ "this water is potable"
  (never fully verifiable → *labelled* "Unsigned — use judgement", not gated). See [C-water-food](C-water-food.md).
- The numbers above are **starting points (TBD)** — the *structure* (tiers + modifiers + decay) is what's fixed.

## The items

| Letter | Type | Spec | Map | Votable? | Editable |
|---|---|---|---|---|---|
| **A** | Road surface | [A-road-surface.md](A-road-surface.md) | line (by surface) | utility | yes |
| **B** | Climbs | [B-climbs.md](B-climbs.md) | line + foot pin | **votable** | yes |
| **C** | Water & food | [C-water-food.md](C-water-food.md) | pin | utility | yes (2 fountains → 1 edit item) |
| **D** | Bike services | [D-bike-services.md](D-bike-services.md) | pin | utility | yes (default edit item) |
| **E** | Where to sleep | [E-where-to-sleep.md](E-where-to-sleep.md) | pin | **votable** | yes |
| **F** | Hazards & conditions | [F-hazards.md](F-hazards.md) | pin | utility | yes |
| **G** | Getting there | [G-getting-there.md](G-getting-there.md) | pin | utility | yes |
| **H** | Shelter | [H-shelter.md](H-shelter.md) | pin | utility | yes |
| **I** | Scenic views | [I-scenic-views.md](I-scenic-views.md) | pin | **votable** | yes |
| **J** | History & culture | [J-history-culture.md](J-history-culture.md) | pin | **votable** | yes |
| **K** | Quality rides | [K-quality-rides.md](K-quality-rides.md) | line + GPX | **votable** (typed: season + bike type) | **no — curator-only**; riders propose / vote / rode-it / suggest |
| **L** | Ride heatmap | — | derived overlay | — | **no** (auto/aggregate, never per-rider) |
| **M** | Public toilets | [M-public-toilets.md](M-public-toilets.md) | pin | utility | yes (**displayed** after C — letters are identifiers, not order) |

L is intentionally not editable: it is a derived, anonymized aggregate,
which is why M · Public toilets skips over it.
