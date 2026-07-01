# Edit-item specs — one per editable catalog type

Per the project rule that **every catalog item must have a designed edit flow** (not just the
service station): each editable type gets its own spec here, documenting what a contributor can
change, the field-level provenance, and how it's built in the demo vs. production. Having one per
type is the guarantee that we've thought through the design and implementation options for each.

These specs are the **design source of truth** for [`atlas/demo/edit-items.js`](../../../atlas/demo/edit-items.js)
(the registry rendered by [`atlas/demo/improve.html`](../../../atlas/demo/improve.html)) and for the catalog
[`2026-06-18-catalog-v2-and-per-type-forms.md`](../2026-06-18-catalog-v2-and-per-type-forms.md).

## Common to every type
These panes behave the same across all edit items, so the per-type specs don't repeat them:

**Setting the location (add mode, `?mode=add`).** The *first* action is always to set the location, and
it varies by type:
- **point** types (water, services, stays, hazards, getting-there, shelter, scenic, history) — tap the map
  to drop a single pin; the eyebrow coordinates update to the dropped point.
- **segment** (road surface) — tap the **start**, then the **end**; the segment line is drawn between them.
- **none** (quality rides) — no pin; the **GPX/FIT** track sets the whole route.
- **climbs** use the dedicated `add-climb.html` flow (draw the **foot**, then the **summit**).

**Report a problem** always includes an **Other** option (free-text) alongside the type-specific reasons.

**Media.**
- **Add photos** — several at once: JPG · PNG · WebP · HEIC (iPhone), CC BY-SA 4.0.
- **Add video** — MP4 · MOV · HEVC (iPhone), short clips, CC BY-SA 4.0.
- **First-time consent/donation gate:** the first time a rider adds a photo (and separately, the first
  time a video) they must explicitly confirm they own it and are **donating** it under CC BY-SA 4.0
  before the action enables; on accept they get a big thanks (and, in the demo, a "nothing is actually
  uploaded" note). Consent is remembered thereafter. Uploads simulate into a visible queue.
- **Link instead of upload:** paste a media URL — **known sources** (Wikimedia, Flickr, Unsplash,
  YouTube, Vimeo) have their rights-holder & licence read and validated automatically; **unknown
  sources** require the contributor to confirm rights-holder & licence manually. First-time rules
  consent applies here too. **Multiple links** are supported — the input clears after each so you can
  add as many as you like; all collect in the queue.
- Every upload/link gives **visible feedback** (a toast) and lands in a visible queue — even after the
  first-time consent is remembered, so the action always confirms ("added to your upload queue · demo,
  nothing is actually uploaded").
- Photo credits link **both** the licence deed and the image source (the Wikimedia Commons file page),
  and link the author to their Wikimedia user profile.
- **Drawer galleries** support **multiple photos** per feature (main image + thumbnail strip), opened as
  a **slideshow** in the lightbox (‹ › buttons + ←/→ keys, per-photo credit, N/M counter).
- **Location metadata is stripped.** On real upload, photos and video have their embedded location
  metadata (EXIF GPS, and the equivalent in video) removed server-side before storage or publishing —
  the Commons maps places, not riders. (Also stated on the public privacy page.)

## Provenance tags
- `[OSM]` — already lives in OpenStreetMap (ODbL); we mirror/enrich it.
- `[auto]` — derived by the pipeline (e.g. gradient from DEM, quietness from traffic model).
- `[tap]` — one-tap community confirmation ("still here / still true").
- `[edit]` — a richer community edit (typed fields, ratings, notes).

## Item lifecycle and votability

Every catalog item moves through a lifecycle. The **map's view-mode toggle (Best-of ↔ Everything) is
driven by _votability_, not verification** — verification is only the gate that lets a votable item
start collecting votes.

```
Submitted → [moderation: spam / abuse / duplicate — ROLE_CURATOR]   ← off the public map
   → Unverified  — public but unconfirmed; shows only in Everything as a small
                   "unverified · help confirm" dot; NOT votable
   → [verification gate: ≥ X independent community confirmations ([tap] "still here / still true")]
   → Verified    — a full pin.                     ◀── utility / coverage types stop here
   → Votable     — votable types only; now accrues votes
   → Best-of     — top-voted; this is what Best-of mode surfaces
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
- The word **_curated_ is deliberately avoided** for this axis — items rise by community **votes**, not
  editorial hand-picking. (Moderation is a separate spam/abuse gate, not a quality ranking.)

**View modes, restated in these terms:**
- **Best-of** = top-voted votable items only — the inspiration / trip-planning map.
- **Everything** = full coverage: all utility + every votable item at any funnel stage + unverified
  dots — the on-the-road / completeness map.

Per-pin state carries the trust/vote signal (unverified dot → verified pin → votable → best-of marker);
the toggle no longer stands in for "trusted". Each per-type spec below tags its **Lifecycle** row
accordingly.

### Verification threshold (X)

X is **not one global number** and **not eleven per-type knobs** — it's a small set of **tiers**
(assigned per type via the catalog) plus **cross-cutting modifiers**. It's a trust ↔ throughput dial:
too low verifies false positives (spam, stale, wrong-location); too high starves coverage in
low-traffic regions.

| Tier | Types | Base X | Behaviour |
|---|---|---|---|
| **Objective utility** | water *existence*, bike services, getting there, road surface | ~2 | existence is binary → cheap to confirm |
| **Experiential / votable** | climbs, where to sleep, scenic views, history, quality rides | ~2–3 | verification only confirms it *exists*; the **voting** layer does the quality filtering, so no punishing bar |
| **Safety / time-sensitive** | hazards, shelter & emergency, the water *potable* flag | ~1 to publish | publish fast, then rely on **freshness decay** (the `freshness` field) — auto-stale after N days unless re-confirmed |

**Modifiers** adjust the base (floor 1): `[OSM]` provenance −1 (imports arrive source-vetted, and may
seed as verified-by-source / community-unconfirmed); trusted contributor or curator −1; bootstrap phase
or low-density region −1 (seed coverage early, tighten as the community grows).

**Notes**
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
| **H** | Shelter & emergency | [H-shelter.md](H-shelter.md) | pin | utility | yes |
| **I** | Scenic views | [I-scenic-views.md](I-scenic-views.md) | pin | **votable** | yes |
| **J** | History & culture | [J-history-culture.md](J-history-culture.md) | pin | **votable** | yes |
| **K** | Quality rides | [K-quality-rides.md](K-quality-rides.md) | line + GPX/FIT | **votable** | yes (1 shared ride edit item) |
| **L** | Ride heatmap | — | derived overlay | — | **no** (auto/aggregate, never per-rider) |

L is intentionally not editable: it is a derived, anonymized aggregate.
