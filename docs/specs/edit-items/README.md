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

## The items

| Letter | Type | Spec | Map | Editable |
|---|---|---|---|---|
| **A** | Road surface | [A-road-surface.md](A-road-surface.md) | line (by surface) | yes |
| **B** | Climbs | [B-climbs.md](B-climbs.md) | line + foot pin | yes |
| **C** | Water & food | [C-water-food.md](C-water-food.md) | pin | yes (2 fountains → 1 edit item) |
| **D** | Bike services | [D-bike-services.md](D-bike-services.md) | pin | yes (default edit item) |
| **E** | Where to sleep | [E-where-to-sleep.md](E-where-to-sleep.md) | pin | yes |
| **F** | Hazards & conditions | [F-hazards.md](F-hazards.md) | pin | yes |
| **G** | Getting there | [G-getting-there.md](G-getting-there.md) | pin | yes |
| **H** | Shelter & emergency | [H-shelter.md](H-shelter.md) | pin | yes |
| **I** | Scenic views | [I-scenic-views.md](I-scenic-views.md) | pin | yes |
| **J** | History & culture | [J-history-culture.md](J-history-culture.md) | pin | yes |
| **K** | Quality rides | [K-quality-rides.md](K-quality-rides.md) | line + GPX/FIT | yes (1 shared ride edit item) |
| **L** | Ride heatmap | — | derived overlay | **no** (auto/aggregate, never per-rider) |

L is intentionally not editable: it is a derived, anonymized aggregate.
