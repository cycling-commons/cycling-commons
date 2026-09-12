<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Moderation & Contribution

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This document owns the contribution intake and moderation machinery for catalog
items: the `/improve` wizard, the `SubmissionDraft` validation boundary, the
`submission` / `change_history` persistence contract, apply-on-approve
semantics, the moderation surfaces and their gating, the user-messages feedback
system (M1–M12), retention and Trash, moderator area scoping, the utility
confirmation loop, and country-interest/curator-application signup. Sibling
documents own the surrounding contracts:

- [edit-items/README.md](edit-items/README.md) — per-type edit contracts, media
  & consent rules, the lifecycle/votability funnel, verification threshold (X).
- [osm-data-architecture.md](osm-data-architecture.md) — materialize-on-edit
  (osm-data-architecture.md §6) and how the pipeline here becomes the boundary
  crossing for OSM-backed items.
- [route-domain.md](route-domain.md) — the R carve-out: route proposals,
  corrections and votes never enter the item pipeline; `/improve` refuses
  `type=R`. Route moderation reuses only the shared systems defined here
  (messages, Trash, retention, moderator areas).
- [catalog-data-model.md](catalog-data-model.md) — the `item` table, states,
  identity and serving.
- [account-and-auth.md](account-and-auth.md) — `ROLE_CURATOR`, mandatory 2FA
  for elevated roles, admin desk patterns.
- [security-architecture.md](security-architecture.md) — stateless CSRF,
  escaping rules, sanitizer.
- [translations.md](translations.md) — in-site non-English proposals; the
  curator desk is unscoped. The YAML catalogues and locale routing stay in
  [dev-environment.md](dev-environment.md) §7.

---

## 1. The `/improve` contribution wizard

`/improve` is a single-column 4-step wizard (`web/templates/contribute/improve.html.twig`,
`web/assets/contribute/improve.js`) for adding and editing items: **1 Locate →
2 Details → 3 Photos → 4 Review & submit**. Step 2 renders the type's
*Fix-details* + *Add-missing* registry fields (`App\Form\ImproveType` over
`CatalogFormRegistry`); step 3's media/consent contract is owned by
[edit-items/README.md](edit-items/README.md).

### 1.1 Entry-point matrix

The map drawer builds these URLs (`web/assets/map/map.js`) and `improve.js`
interprets them — a real cross-file contract:

| From (drawer) | Link params | Step 1 (LOCATE) behaviour |
|---|---|---|
| "+ add" on an empty field | `item`, `type`, `field=<key>` | **Skipped** — wizard starts on step 2, step-1 stepper chip hidden, the matching field scrolled to and focused |
| "✎ Edit this item" | `item`, `type`, `name`, `lat`, `lng` | **Compact confirm-map** (point types only): view-only reassurance, Next enabled without interaction, expandable via "◎ Change location" |
| "◎ Fix location" | as edit + `fix=location` | **Expanded editor** directly — pin pre-placed and repositionable, search visible |
| "Add a new place" | `mode=add` | Full locate editor (pin / two-tap segment per `LocationMode`) |

**mode=add server side.** `ContributeController::addPlace()` renders the wizard
(`ImproveType` `add_mode: true` — which injects a **required name** into the
details pane, since several field sets carry none) for every type except R
(/propose-route); N (climbs) has been included since 2026-08-25, when the
dedicated /add-climb wizard was retired (`/add-climb` is a 301 to
`/improve?type=climbs&mode=add`; [edit-items/N-climbs.md](edit-items/N-climbs.md)).
`CatalogContributionService::submitAdd()` persists it as a NewItem submission
(§3.3), with the two drawn endpoints of a segment-located type stored as the
`segment` attribute (added to `AttributeVocabulary` `EXTRAS['A']`), and for a
climb with the drawn `route`/`grad`/`steep` merged through
`ClimbGeometry::fromPayload()` and the profile measured by `deriveClimbProfile()`
(the foot of the route is the pin when the form sent none). A stale
deep link carrying junk `item` + `mode=add` degrades to the add wizard, not
the explainer (ImproveTest::testDeepLinkWithItemAndModeReturns200).
Covered end-to-end by `AddPlaceFlowTest`.

**?ref= arm — materialize-on-edit (same wizard).** The coverage
drawer's edit link for an uncurated OSM POI opens
`/improve?ref=<node|way/id>&type=<slug>`: the add wizard with the POI's name
prefilled and location given; submit is the `'add'` intake with `_osm_ref`
threaded through (re-read from the query string, never a form field), which
mints the item carrying the ref (osm-data-architecture.md §6). An
already-SERVED ref 302s to the bound item edit; a Submitted twin is rejected
at intake (`already_materialized`); unknown/malformed refs degrade to the
explainer. Covered by `MaterializeFlowTest`.

The mode gate in `improve.js`:
`LOCATE = (ADD || hasCoords || RELOCATE) ? locationMode : 'off'`, where
`RELOCATE` is `fix=location` and `hasCoords` means valid `lat`+`lng` params.

- **`field=<key>` contract:** the key is guarded to an alphabetic token and
  matched against both `improve[details][<key>]` and `improve[extras][<key>]`
  (registry fields are nested under the `details`/`extras` sub-forms). Unknown
  key: no-op, wizard simply starts at step 2.
- **Compact confirm-map:** pan/zoom allowed, pin **not** repositionable until
  explicitly expanded; once expanded it stays expanded for the session. Sizing
  is CSS state on `#wmap` (improve template inline styles): full map
  `clamp(420px,74vh,720px)`, `#wmap.confirm` `clamp(280px,40vh,420px)`.
- **"◎" is the shared location glyph** in the drawer and the wizard — unicode,
  not emoji (Chrome emoji rendering precedent). The drawer's "Fix location"
  label is localized via the `CC_I18N` drawer bundle (`D.fixLocation`), like
  every other drawer string.
- The "Fix location" link renders only when the item has coordinates *and* a
  real DB id (same guard as the edit link).

### 1.2 Gating and search

- **Step 1 is the only gated step**: Next is disabled until `WZ.loc` is set —
  a pin placed (point), both endpoints placed (segment), or the location known
  from the entry point. Steps 2–4 are never gated; step 4's Next becomes
  "Submit for review →".
- **Place search is keyless Photon** (`photon.komoot.io`, called client-side
  from `improve.js`), debounced **320 ms**. Mandatory offline fallback: on
  fetch failure the results dropdown shows "Search unavailable — tap the map
  instead" and map-tap keeps working — the geocoder is never load-bearing.
- **A pasted coordinate pair short-circuits the geocoder.** The map's
  right-click popup copies a spot as `lat, lng`, so the search box has to read
  that back: `web/assets/contribute/coords.js` (`window.Cc.parseLatLng`; it was
  shared with `add-climb.js` until that wizard was retired on 2026-08-25, and
  shared since 2026-09-12 with the map's own search box,
  map-and-search.md §7.4) parses
  `lat, lng`, `50.4920°N 5.8600°E`, `N50.49 E5.86`
  and `geo:`/`@` prefixes, and the query never reaches Photon. The pair is
  shown as a result row before anything moves, so the rider sees what was read
  out of the paste. Selecting it flies there **and drops the pin** — the
  coordinates *are* the location, and re-tapping the map would throw away the
  precision the rider just supplied. A climb is the exception: there the paste
  is a fly-to only, because one pair cannot say whether it is foot or summit.
- **Picking a geocoder result drops the pin too** (owner 2026-09-10). The
  camera and the pin are two different things, and every change test in the
  wizard reads the pin: `nothingChanged()` compares `WZ.loc` against the item's
  own position, and the server compares `improve[lat]`/`improve[lng]` against
  the row's geometry. A result row that only flew the camera therefore left a
  rider watching the map arrive at a new address and then being told "Nothing
  has changed yet" on step 4, with Next disabled. Both routes into the search
  box now end at the same `placeAt()`. `improve[place]` carries the name of the
  place the rider picked, and is a label the server never diffs; only the pin
  counts as a location change. The climb exception above still holds, and holds
  for the same reason: `placeAt` is null on that branch.

- **A nudge is a move** (owner 2026-09-10). "A move" is a change of more than
  `MOVED_EPS`, **1e-7 degrees, about a centimetre**, and the same number lives
  on both sides: `pinMoved()` in `improve.js` gates Submit, `pinMoved()` in
  `CatalogContributionService` decides whether the change set gets a `location`
  entry. Two thresholds that drift apart would give a rider an enabled Submit
  and a 422.

  It was 1e-5, about 1.1 m, matched to a `was`/`now` string of five decimals.
  Nobody corrects a pin at the zoom where a metre is small. Measured in a real
  browser: at z19 a 4 px drag travels 0.36 m and at z14 the same drag travels
  11.6 m, so the wizard worked at the default zoom and silently refused exactly
  the deliberate small correction it exists for. It recorded the new point,
  showed it in its own readout, and then said "Nothing has changed yet" with
  Submit greyed out.

  `FORMAT_DECIMALS` is 7 for the same reason and must stay in step with
  `MOVED_EPS`: the recorded string **is** the geometry an approval applies
  (`ModerationService::applyEdit`), so a move too small to survive the
  recording is not a move, and a curator must never approve a point identical
  to the one it replaces.

- **A pin released off the map still ends its drag** (owner 2026-09-10). A
  MapLibre `Marker` ends a drag on the MAP's own `mouseup`, which the map fires
  only from its canvas-container listener. `.navrow`, the Back/Next bar, is
  `position:sticky; bottom:0` over a map taller than the viewport, so a pin
  dragged downwards is released **on that bar** and the map never sees it. The
  drag then never ends: `dragend` does not fire, `syncLoc()` never runs, the
  hidden fields still hold the old point, step 4 says "Nothing has changed yet"
  with Submit greyed out, and the marker keeps the `pointer-events: none` its
  own drag handler set, so it is dead to a second attempt. A release over the
  drawer, the header or outside the window has the same shape.
  `endDragOffMap()` in `improve.js` forwards a release that started on the map
  into the canvas container, so MapLibre runs its own `_onUp` and the marker
  restores itself and fires `dragend` on the one path the wizard already
  listens on. It never re-derives the coordinate itself: two ways to end a drag
  would be two ways to disagree.

- **A coordinate pair is never silently swapped.** `5.86, 50.49` is refused,
  not read as lng-first: guessing would drop the pin in another country while
  looking authoritative. Out-of-range values, a third number, trailing text and
  decimal commas are all refused the same way, and the query falls through to
  the geocoder.
- **The client's "nothing changed" gate covers geometry that is not a pin**
  (2026-08-03, owner-reported). `pinMoved()` only ever compared lat/lng, and
  only for `type === 'point'`, so a climb's route/steepest and a road surface's
  endpoints were invisible to it: moving a climb's summit left the wizard
  convinced nothing had changed and Submit disabled — on an edit the **server**
  would have accepted perfectly well, since `ClimbGeometry::fromPayload()` is
  merged into the change diff there. The gate now also diffs the `route`,
  `steep` and `segment` hidden fields against a snapshot taken once the editor
  has hydrated (`mountClimbEditor` writes the stored shape synchronously at
  mount and never re-snaps or re-profiles on its own, so anything different
  afterwards is the rider's doing).
- **An edit that changes nothing is refused.** `[] === $changes` with no photo,
  no photo link and an unmoved pin (~1 m tolerance, because the wizard posts
  the item's own coordinates straight back) is not a contribution: it costs a
  curator a queue row to read, tells the rider's dashboard a suggestion is
  pending, and applies nothing on approve. `CatalogContributionService` rejects
  it with `contribute.error.nothing_changed`; the wizard disables Submit on the
  review step and says the same sentence there, because a prefilled form walks
  straight to Submit and this is easy to do by accident.
- **The review shows the photos already on the place**, server-rendered and
  read-only, under their own heading. They are not part of the submission, but
  omitting them made the review read as "this place has no photos" two steps
  after the rider had been shown them.
- **The review card is a summary, not a control.** The site-wide `.card`
  (atlas.css) lifts and shadows on hover because it is clickable everywhere
  else; the wizard switches that off explicitly (`transition:none`,
  `:hover{transform:none}`). Overriding only the background and border left
  the whole review sliding under the pointer.
- **The lifecycle block is ONE paragraph.** It says what happens next and what
  this type means for the rider (votable, or confirmed-not-voted) in a single
  sentence per type — `improve.lifecycle.funnel_votable` /
  `funnel_utility`. The second paragraph that used to sit under it repeated
  the first in different words, and said "after a few of them agree", which is
  not what the code does: one counted confirmation promotes a dot to a full
  pin (map-and-search.md §12).
- **Every string the wizard renders itself is translated**, through
  `window.CC_IMPROVE_I18N` (`improve.step1.*`, `improve.step3.link_*`,
  `improve.review.*`, `improve.nav.submit`) — the readouts, the toasts, the
  climb states, the search notes, the review card and the photo-link source
  notes. The strings are text and are set with `textContent`; the wizard
  builds no markup at all (security-architecture.md §4.3). A missing key
  renders empty rather than printing its own name at a rider, and
  `tools/check-translations.sh` is what stops one going missing.
- The Back/Next bar is a sticky bottom row (`.navrow`, `position: sticky`) so
  the confirm control stays reachable under a tall map.

**A rider's needs-info answer is desk work, not personal mail** (2026-08-03,
owner-reported). The reply is still *addressed* to the deciding curator — that
address is a lookup key, because `SubmissionQueue`'s LATERAL join reads the row
to render "Rider replied" on the queue card and in the map drawer, and deleting
it would take the desk's copy of the answer with it. But it is now excluded
from `listFor()`, `unreadCount()` and `markAllRead()`, so it never appears in
the inbox a rider uses for their own contributions and never inflates the
account chip's badge. The rider still sees their own answer in their own
thread: the exclusion is skipped when the reader is the sender.

Because the personal inbox was carrying that notification, the desk now has to:
a replied submission shows an **Answered** tag on its queue card, beside the
type tag, so a curator can spot it while scanning. The reply itself is further
down the card, as before. The reply also flips the submission back to
`pending`, so it is already back in the queue it left.

**And the reply follows the submission into the history.** A decided
submission leaves the queue, which was the last surface still showing it — so
taking it out of the inbox made an answered-then-approved submission's answer
invisible everywhere (owner-reported immediately). `history()` now carries the
same LATERAL join and the settled row renders it.

**A settled row says what was decided, and opens it.** The history listed a
verdict and a title and nothing else — nothing a curator could check
(owner-reported 2026-08-03). It now renders the same before/after the queue
card does, from a `diffStrings()` helper both share so the two cannot drift.
The title links to **`/map?item=<id>`**, a new deep link resolved by DB id
(`resolveLocalFeatureById`, covering CATALOG features and the OSM pools). It
replaces `?pending=<id>`, which could not work by construction: a settled
submission is not in the pending payload, so the link opened the map at the
default scope with nothing selected. Only **approved** rows link — a rejected
item is not on the map, and a link that lands nowhere is worse than no link.

**The record is its own page under the submissions desk, and both desks page
and search** (2026-08-03, owner; chips 2026-08-26). `/moderate/history`
carries the settled submissions; `/moderate` stays about what is still to
do. Queue and History chips on both pages switch between them, the same
pattern as the translations desk — History is not a top-level moderation
tab. Both take **25 rows a page** (`SubmissionQueue::PER_PAGE`)
and a **title search**.

- The page query and its count share one WHERE builder per desk
  (`openFilters()`, `settledFilters()`), so a pager can never disagree with the
  rows it is paging.
- Search is `ILIKE` with the wildcards **in the bound value**, and `%`, `_` and
  `\` in what the curator typed are escaped — a `%` in the box matches a literal
  percent rather than silently matching everything.
- Trash audit rows are merged into the history only on **page one of an
  unfiltered, unsearched** view. They come from a different table with no shared
  cursor, so interleaving them across pages would drop or repeat rows as the
  pager moved; and being content-free by design they have no title to match, so
  under a search they would surface as unexplained hits.
- The pager is plain links carrying the active filters, so a filtered page can
  be bookmarked and sent to a colleague, and a decision redirect comes back to
  the same view.
- **Both desks carry the same filter row** — country, region, type, search —
  built from the same markup, with the search field and pager styled once in
  `account/_shell_styles.html.twig`. They began in one page's `<style>` block,
  so the other desk rendered an unstyled browser default beside a designed one.
- The history's country/region option lists describe the **settled** set, not
  the open queue (`statusTuple()`): a country with no open work can still have
  a record worth reading.

**The regions desk filters by country** (2026-08-03, owner). Options come from
the regions the curator can see, built **before** the filter narrows them — a
list that shrank to the picked country would be a one-way door — and the row is
rendered only when there is more than one country to choose between.

**The settled row folds out to the whole exchange** (2026-08-03, owner's
choice of two proposals). Every needs-info question and every rider reply,
oldest first, behind a disclosure on the history row — the row stays one line
because most submissions never had a conversation. This is the only surface
that carries it: the queue card and the settled row show just the latest reply,
`change_history` records what was *applied* rather than what was *asked*, and
the reply is no longer personal mail. One query per page (`threadsFor()`), not
one per row.

The owner's other proposal — grouping the desk history by item — was **not**
built because it largely exists: `change_history` is already served per item at
`/map/item/{id}/history` and rendered as "Recent changes" in the map drawer,
which the history row's `?item=` link now opens in one click.

**The account chip's open count says where it points** (2026-08-03,
owner asked twice what the number referred to; 2026-08-26 the total grew).
The bulb on the avatar is the sum of unread messages, open submissions
in the curator's area, and open translation proposals — `0` for any
queue the visitor cannot see. Each contributing row inside the menu
repeats its own count, from the same `{% set %}`s, so the two can never
disagree: the bulb says something is waiting, the row says where.

**The moderation tab strip no longer grows a phantom vertical scrollbar.**
`.dtabs` sets `overflow-x:auto` for the horizontal tab list, which makes the
other axis compute to `auto` as well (CSS overflow), and `.dtabs a` carries
`margin-bottom:-1px` to pull the active tab's border over the bar's — exactly
one pixel of vertical overflow, which is all a scrollbar track needs. It read
as the account menu having a scrollbar, since the open menu sits over that
strip. `overflow-y:hidden` pins it: this strip scrolls sideways or not at all.

**`hidden` must actually hide** (2026-08-03, owner-reported). An author rule
that sets `display` beats the UA's `[hidden]{display:none}` — author styles win
over UA styles regardless of specificity — so every element in the wizard whose
display came from a class silently ignored the attribute. `#wzChange` is the
one that got away with it for months: improve.js correctly keeps "Change
location" hidden for climbs (the three-point editor is directly editable) and
for non-confirm edits, and the CSS showed it anyway. The template had already
accumulated three separate one-off `[hidden]` patches, each added after that
element bit; they are replaced by a single `#wiz [hidden]{display:none}`.

**No "Fix location" on a climb, and no "Where is it?" on an existing item**
(2026-08-03, owner):

- The drawer's **Fix location** action is what unlocks a *point* item's pin: a
  point opens its edit form on a compact, view-only confirm map, and
  `fix=location` is the thing that expands it. **Letter N has no such gate** —
  the three-point editor is live the moment the form opens, foot, summit and
  steepest all draggable. Both links therefore landed on an identical page, and
  a second door into one room reads as a second room. Gated to `'B' !==
  layer.letter`.
- **"Where is it?" only asks a question nobody has answered yet.** An existing
  item's location was settled when it was created, so an edit's step 1 heading
  is *"Check the location"* over the already-written "This location is already
  set — check it looks right, or change it." Add mode keeps the original
  question. improve.js's confirm-help swap now writes the sentence the server
  already rendered, making it a harmless no-op.

**Placing a climb is explained before it is attempted, and it is undoable**
(2026-08-03, owner request). A climb is not a dropped pin: three points in
order, with the road between the first two snapped for you, and nothing on
screen would ever suggest that a *third* tap marks the steepest ramp. Step 1
therefore carries a four-line how-to for letter N — tap foot then summit, tap
again for the steepest ramp (optional), drag any marker to correct it, and Undo
takes back the last thing you did.

**The steepest marker stays where it is while the route changes**
(2026-08-03, owner-reported). It used to be re-derived from the gradient
profile on every resolve unless the rider had placed it by hand, so dragging
the summit a little further up the road made the steepest ramp jump elsewhere
on the climb — the rider changed one end and watched a different marker move.
Extending a climb does not relocate its steepest ramp; only the numbers around
it change. The position is therefore kept and the **%** is re-read from the new
profile at that fixed position.

**Its percentage stays too.** How steep a ramp is, is a property of the ROAD —
where the rider decided the climb starts and ends cannot change it, so any
movement in the printed figure is a measurement artefact, not new information.
Two artefacts produced one: the 11 display bars are equal slices of the *whole*
climb, so a longer climb widens every bin and averages a short ramp flat; and
the elevation profile is 100 samples spread over the route, so a longer route
samples the same ramp more coarsely. Re-reading through either made 19% print
as 10% (and 16% after only the first was fixed — exactly what an artefact looks
like). The number is now re-measured **only when the marker is actually
(re)placed**.

When it *is* measured, it is measured the way the maximum is — a ~150 m
sustained window (`profileFromRoute().sustainedAt`), never off the display
bars. The old bar lookup also mapped position→bar by **vertex index**, which is
not position along a climb at all: a router packs vertices through curves, so
it picked the wrong bar whenever the shape changed. It survives as a fallback for
a drag before the first profile resolves, now keyed on cumulative distance.

The one case that moves the marker is the route no longer passing it: shorten
the climb past the steepest ramp and it would otherwise float beside a road
that is no longer part of the climb. Then — and only then — it is re-derived,
**including a hand-placed one**, because a marker stranded off the climb is
wrong however it got there. "Still on the climb" is nearest-route-vertex within
**100 m** (`STEEP_ON_ROUTE_KM`); the snap returns roughly 40 m vertex spacing,
so a marker on the road sits well inside it while one left behind by a
shortened route is hundreds of metres out. (Measured against OSRM, which the
editor called until 2026-08-09; it snaps through our own Valhalla now and the
proxy keeps the same response shape, so the spacing this threshold was chosen
around is unchanged — worth re-measuring if that ever stops being true.)

A failed or timed-out elevation fetch no longer deletes the marker either: the
position is a fact about the climb, and only the % needs a gradient to refresh.

`mountClimbEditor` keeps a snapshot stack (25 deep) and exposes `undo()` /
`canUndo()`:

- Every act snapshots first: a map click, a marker drag (on **dragstart** — by
  dragend the marker has already moved, and a mis-drag is precisely what Undo
  is for), and **Reset**, which is the most expensive mistake on the editor.
- Snapshots are values, and restoring rebuilds every marker from state, so an
  undone drag cannot strand a stale pin. Route and gradient arrays are copied
  because the snap and elevation resolves mutate them in place, and an undo
  invalidates anything in flight — a late resolve must not paint the gradient
  of a route that no longer exists.
- The control is revealed by `onHistory` only once there is something to take
  back: a permanently dead button teaches nothing.

### 1.2b What is already mapped here, on the locate step

**The wizard's map shows the places of this kind already around the pin**, so
somebody about to add one can see they are about to add it twice. Fetched from
`/map/coverage/nearby` on every map move, filtered to the letter being added,
and it carries both halves of the atlas: rows we hold (`curated: true`, drawn
in trail green) and OpenStreetMap records we have not taken in (pale). The
distinction is the useful one for the question being asked, because adding on
top of one of ours is a duplicate while adding beside the other is often the
whole point.

**It drew nothing at all until 2026-09-12** (owner: "the map for the add new
point does not show any of our tile data nor our categories"). The endpoint
had always answered, with both arms: the overlay read `p.lng` / `p.lat` while
`CoverageRepository::entry()` emits `ll: [lat, lng]`, so every marker was
placed at `[undefined, undefined]` and none appeared. Nothing logged, because
that is not an error MapLibre raises.

**Below zoom 11 it says so** rather than going quiet. An empty map reads as
"nothing is mapped here", which is the opposite of the truth and exactly the
wrong thing to tell somebody about to add a place; the note now says to zoom
in. Above it the note counts what is in view and how many are already ours.
Climbs are exempt: a climb is a line, and a dot beside it answers nothing.

### 1.3 Segment carrier

Segment-located types (road surface, `LocationMode::Segment`) POST their drawn
endpoints through a **hidden `segment` field** added by `ImproveType`: the
wizard writes `{"a":[lng,lat],"b":[lng,lat]}` JSON when both taps are placed
and clears it on reset/incomplete. The value lands in `Submission::payload`.
The drawn endpoints must never live only in JS memory — that was a real
data-loss bug class this contract closes.

### 1.4 Edit binding and refusals

`ContributeController::improve()` binds the edit flow to a real item:

- The `item` query param must be a digit string resolving to an item in an
  editable state; a slug, missing id, or unknown id falls through to the
  **unbound explainer** (`improve.unbound_*` keys, CTA to the map) — never a
  fake default editor.
- A `type`/letter mismatch between the param and the resolved item also falls
  through to the explainer (editing the wrong item is worse than editing none).
- **`type=R` is always refused** (route/item id-collision guard): route ids
  live in `recommended_route`, a separate sequence from `item`, so resolving a
  R id against the item table would bind an unrelated item. Riders interact
  with routes via the community loop ([route-domain.md](route-domain.md)).
- The form is prefilled with the item's current `name` + attribute values;
  was → now is computed server-side at submit (§3.2), never trusted from the
  client.

### 1.5 The receipt offers the way forward, not only the way back (2026-08-31)

After submitting, the only thing on the page was "Back to the map". The
reference printed directly above it is a real submission id, and Contributions
(`/profile`) is the page that tracks it: where a rider answers a curator's
question and sees the decision. Sending them back to the map left the one thing
they might want to do next off the screen.

Two buttons, and the order is the argument: Contributions is the forward action
and carries the weight, back to the map is the way back and stays quiet. They
wrap rather than squeeze, because two full-width buttons on a phone read better
than two half-width ones with a label broken across lines.

The improve receipt only. The vote receipt is a different flow for a deferred
feature, and giving it the same treatment would be guessing at where a vote
should send somebody.

### 1.6 A curator's own edit is applied at once (2026-09-06)

Owner: "If I change anything on an item when I have curator rights I should not
have to approve it." Built the same night, and deliberately NOT a new
mechanic: `CatalogContributionService::applyIfCurator()` runs
`ModerationService::decide(approve)` on the edit it just filed, as the same
person, so the item change, the `change_history` rows, the OSM re-check on a
move and the area check are exactly the ones the desk button would have made.
Outside the curator's assigned areas the edit queues like anyone's
(`OutOfScopeException`). The receipt then reads "Change applied" instead of
"Suggestion submitted" (`ContributionReceipt::$applied`,
`improve.receipt.applied_*`).

**A NEW place too, once the wizard asks the identity question**
(2026-09-12). Approving a new place needs the OSM answer first (§5b of
catalog-data-model.md), and that question used to be asked only on the queue
card, so a curator filing their own place queued behind themselves and then
answered their own question on the desk a moment later (owner 2026-09-06).
The wizard now asks it on the locate step: `OsmLinker::nearby()` candidates
for the point, served curator-only by `ContributeController::osmNearby()`,
plus "Not in OpenStreetMap" as the last choice. A candidate this atlas
already holds is not offered as a refusal: it carries that row's id from
`OsmLinker::claimedBy()` and becomes a link to the entry, "Use the existing
entry", because somebody about to add a place that is already here wants the
place and not a grey box (owner 2026-09-12). **Answering is required**: the
locate step's Next stays shut until it is, which is why the question carries
no "you may skip this" line. One line says what the list is, same kind
of place and within `OsmLinker::LOOSE_M` of the pin, with the radius passed
from the constant so the sentence cannot drift from the query (owner
2026-09-12: a paragraph of explanation went, but "already linked to another
entry" without the scope reads as a rule about nothing in particular).

The known-places note from §1.2b is suppressed wherever this question is
asked. That note counts whatever is in the map view; this counts a fixed
250 m around the pin. Two different numbers about the same worry, on one
screen, is one too many. The answer rides in the `osmAnswer` form
field, and `CatalogContributionService::answerOsmIfCurator()` records it
before `applyIfCurator()` runs, with the desk's own exclusivity guard: a
taken ref is not recorded and the place queues, where a human sees the clash.
Unanswered, it queues exactly as before, so the question is an opening and
never a new barrier. A place taken from an OSM node answered it by
construction and needs none of this.

**A rider is never asked** (§5b). The block, the script and the endpoint are
all behind `ROLE_CURATOR`, and an `osmAnswer` sent by a rider is ignored by
the service rather than trusted.

The promise matches the result (owner 2026-09-07: the admin's own name edit
went live at once, but the wizard had said "Submit for review"). On an EDIT
by a user who reaches `ROLE_CURATOR`, the wizard's button reads "Apply
change" (`improve.nav.submit_applies`) and the lifecycle box reads
`improve.lifecycle.heading_curator` / `funnel_curator`, which also says an
edit outside their areas still queues. `improve.html.twig` sets
`self_applies` once at the top. On a curator's ADD the answer is not known
when the page renders, so both lifecycle blocks ship and
`assets/contribute/osm-answer.js` shows whichever the answer makes true.
Pinned by `CuratorWizardCopyTest`.

**Optional "Mark it confirmed"** (owner 2026-09-07: "the Eiffel Tower will be
there without a French rider confirming it, but a fountain the moderator
remembers from a holiday tour may be out of date"). On that same applying
edit, and only on a row that offers the `exists` stance, the last step shows
one tick box, OFF by default: "I know this place is there now. Mark it
confirmed." (`improve.review.confirm_now`, form field `confirmNow`, never
part of the change set). The box is not offered once the curator's own
drawer confirmation is already on the row (owner 2026-09-10: a box that asks
what you already did is noise). Ticked, `CatalogContributionService::confirmNow()`
records the SAME drawer confirmation the "Still here?" button writes
(`ItemConfirmationService::record()`, source `drawer`), so the curator's tick
verifies the row exactly as their click would; the receipt then reads
`improve.receipt.applied_confirmed_body` (`ContributionReceipt::$confirmed`).
The self-apply also runs when the edit MERGES into the curator's own open
submission on that place (§7.3b): the merge path skipped it until 2026-09-08,
so a curator who fixed a place that already carried their description
suggestion found their own words in the queue (the Shimano stand). The box is
ignored on an edit that queues, so a rider cannot tick past the review.
Pinned by `CatalogContributionServiceTest::testACuratorsEditCanAlsoConfirmThePlace`
and its two siblings.

**A curator's own new place, too (owner 2026-09-10: "I do not have to approve
my own actions").** The same `applyIfCurator()` runs on a curator's NEW place
at intake, under the same rule the desk button obeys: approval needs the OSM
question answered (catalog-data-model.md §5b). A place taken from an OSM node
(`/improve?ref=node/...`) has answered it by construction and applies at once;
the wizard then reads "Apply change" and offers the same "Mark it confirmed"
box, whose tick records the same drawer confirmation and verifies the row on
the curator's word. A place from a bare pin still queues, for everyone,
because the wizard does not ask the OSM question yet (docs/TODO.md); the
receipt says "submitted", not "applied", and the box is ignored. Section 6.3
still holds for riders: a submitter's own form answer never counts. Pinned by
`testACuratorsNewPlaceFromAnOsmNodeIsAppliedAtOnce`,
`testACuratorsNewPlaceCanAlsoBeMarkedConfirmed` and
`testACuratorsNewPlaceWithoutAnOsmAnswerStillQueues`.

## 2. Intake boundary: `SubmissionDraft`, validate twice

One envelope DTO, not eleven: `App\Contribution\SubmissionDraft` carries
`ItemType $type`, `title`, `lat`/`lng`, `array $attributes` (registry-keyed),
`?int $itemId`, `?string $note`. Per-letter field shape stays registry data
(`CatalogFormRegistry`), not classes. Constraints on the DTO:

- `title`: `NotBlank`, `Length(max: 200)`,
  `NoSuspiciousCharacters(locales: [en, fr, nl, de, es])`, plus a
  `Regex(/\p{Cf}/u, match: false)` that closes the lone-zero-width-character
  gap ICU's spoof checker leaves open (the same guard is centralized in
  `CatalogFieldConstraints` for registry text fields).
- `lat`/`lng`: `Range(±90 / ±180)`; `note`: `Length(max: 2000)`.
- Class-level `#[ValidAttributes]` validates the attribute map against
  `AttributeVocabulary` — the same vocabulary the importer enforces, so intake
  and import can never diverge.

**Validate twice, define once:** forms validate on submit (UX);
`CatalogContributionService::submitDraft()` re-validates the DTO via
`ValidatorInterface`. The service-side pass is the only validation a future
JSON API path would get, so nothing may rely on form-only checks.

**Rate limiting** (thresholds are config, not literals — TDD asserts against
these keys):

| Limiter | Policy | Current value | Where |
|---|---|---|---|
| `contribution_submit` | sliding window, per user | 20 / hour | `web/config/packages/rate_limiter.yaml` |
| `route_propose` / `route_suggest` / `ride_check` | own limiters | full inventory: [security-architecture.md](security-architecture.md) | same file; contract owned by [route-domain.md](route-domain.md) |

A 429 surfaces as a translated message (`contribute.error.rate_limited`). Note
an ordering asymmetry that is deliberate per channel: item intake consumes a
limiter token **before** validating the draft
(`CatalogContributionService::submitDraft()`), while the route-suggest channel
runs its cheap validation before consuming quota (a 422 must not cost a token
there — expensive GPX work still consumes first; see
[route-domain.md](route-domain.md)).

Geometry and jurisdiction are resolved **at submit**: `SpatialResolver`
derives `country_code` + `region_id` with the same parameterised spatial
containment logic as import. For edits on non-point items, a representative
point (first coordinate of the item's geometry) anchors the submission pin.

**What intake does not accept — `'vote'` is a deliberate non-goal.**
`CatalogContributionService::submit()` routes exactly two kinds:
`'climb'` → a new-item submission, `'improve'` → an edit submission. Every
other kind falls to a `default` arm that **persists nothing** and returns a
`ContributionReceipt` with `persisted: false` and a throwaway `CC-<random>`
reference. That arm is live, not dead code: `POST /vote`
(`ContributeController::vote()`, gated `ROLE_USER`) sends `'vote'` through it
on every submission.

This is intended. Voting is verification-gate machinery — a rider signalling
that a place or route is real — and that funnel is owned elsewhere
(edit-items/README.md for items, [route-domain.md](route-domain.md) §6 for
routes). It is not catalog intake, so it produces no `submission` row and no
curator task. The vote page states this plainly rather than implying a
recorded tally (`vote.receipt.stub_note`: "this is a preview — votes are
queued for review and not yet persisted to a live tally").

**Do not "fix" the `default` arm by persisting a submission for `'vote'`.**
Wiring voting to a real tally means building it on the verification gate, and
then `/vote` should stop calling this service at all.

## 3. Submission persistence

### 3.1 `submission` (entity `App\Catalog\Entity\Submission`)

| column | type | notes |
|---|---|---|
| `id` | bigint identity | receipt ref is `SUB-<id>` |
| `type` | varchar(8), enum `SubmissionType` | `new` \| `edit` \| `hazard` \| `photo`; queue renders all four, intake produces `new`/`edit` only (§8) |
| `letter` | varchar(1) | effective range A–G, N–Q (R bypasses this table) |
| `item_id` | bigint NULL | set for `edit` at submit; set for `new` when the item row is created in the same transaction |
| `user_id` | bigint | submitter — deliberately **no FK** (survives account deletion as anonymous data; see §5.6) |
| `status` | varchar(12), enum `SubmissionStatus` | `pending` \| `approved` \| `rejected` \| `needs_info` \| `withdrawn` (§3.4) |
| `title` | varchar(200) | queue/drawer display |
| `geom` | geometry(Geometry, 4326) | pending-pin location — always a Point in practice |
| `country_code` / `region_id` | varchar(2) / bigint NULL | resolved at submit (§2) |
| `changes` | jsonb | `{field: {"was": …, "now": …}}` (§3.2) |
| `payload` | jsonb | raw validated form data, durable record of what was submitted (includes the segment carrier, climb geometry, non-registry form fields) |
| `decision_note` / `decided_by` / `decided_at` | text/bigint/timestamp, NULL | decision columns live **on the row** — the submission row is the institutional audit record; no separate decision table. `admin_action_log` stays reserved for admin-on-user actions |
| `created_at` | timestamp | |

Indexes on `status`, `country_code`, `region_id`, `item_id`, `user_id`, plus
a GIST index on `geom` (`idx_submission_geom`).

### 3.2 The `changes` contract

- Computed **at submit against the item's then-current values**; only fields
  whose proposed value differs are recorded — unchanged fields never appear.
- Empties (`''`/`null`/`[]`) are normalised to `null`, so clearing a prefilled
  field is recorded as a removal (`was → null`), while an always-empty field
  records no phantom change.
- The `name` pseudo-field compares against `Item::name`
  (`Item::NAME_FIELD`), not the attribute map.
- For `new` submissions, `changes` is the proposed field set as
  `{field: {was: null, now: …}}`.

### 3.3 New-item intake

`new` intake creates the `item` row (state `submitted`, source `user`,
`source_ref` = `sub:<submission-id>`) and the submission **in one
transaction**. `submitted` and `rejected` items are excluded from the public
catalog payload (serving contract:
[catalog-data-model.md](catalog-data-model.md) §4, §9); `submitted` items feed
only the curator pending layer (§6).

### 3.3b The submitter keeps their hands on a pending item (built 2026-08-16)

Two owner rules from the same review: "while an item is waiting for review I
should be able to edit it", and the pending pin must be visible to its maker.
The improve wizard now binds a `Submitted`-state item for its SUBMITTER too
(not just curators): ownership is any submission of theirs on that item id,
nothing is exposed that is not their own data, and the edit lands as one more
submission on the same queue. And `/map` serves a rider their OWN
pending/needs-info rows (`SubmissionQueue::ownPendingForMap()`, scoped by
user - their rows are theirs wherever they are) as the same pending layer -
WITHOUT `CC_IS_CURATOR`, which is now set by the CONTROLLER only where the
2FA policy was applied (a setup-pending curator holds the role, not the
capability), so a rider's pending card is a preview: badge, proposed change,
shape switch, conversation - never decide controls. The /profile rows link
Map (pending deep-link) and Edit for every item-bound row, and carry the
region + country stamps as words (town-level waits on the gazetteer item).
Pinned by `MapCuratorInjectionTest` (own rows only, no chrome flag, no
capability before 2FA) and `MyContributionsTest`.

### 3.4 Withdraw — the rider's own exit (built 2026-08-16)

A rider may take back their own submission while it is `pending` or
`needs_info` (owner 2026-08-16): a Withdraw chip on the /profile
contributions row, POST + per-row CSRF to `profile_withdraw`, handled by
`ModerationService::withdraw()`. It reuses the REJECT mechanics on purpose -
same row lock, a `new`-item's minted row goes to state `rejected` (off the
map, OSM ref stays claimed for a possible revive, exactly like a rejection),
pending photos are rejected into the retention sweep - but it is NOT a
decision: only the submitter may do it (`NotTheSubmitterException`), there is
no scope check, no outcome message to the person who did it themselves, and
`withdrawn` never counts in the admin moderation-activity view (which filters
approved/rejected). `decided_by` records the rider and `decided_at` starts
the retention clock: the sweep and /profile's lazy filter treat `withdrawn`
exactly like `rejected`. A race with a curator ends in a flash and the real
outcome, never a half-withdrawal (`AlreadyDecidedException` under the lock).
Pinned by `WithdrawSubmissionTest`.

## 4. Apply-on-approve and change history

`App\Moderation\ModerationService::decide()` is **the only write path for
decisions** — one transaction with a pessimistic row lock (two curators racing
on the same submission: the second sees the decided status and gets
`AlreadyDecidedException`). Semantics per decision:

- **approve, `new`** → `item.state` flips `submitted → unverified`; one
  `change_history` row (`field: state`).
- **approve, `edit`** → each `changes` field is applied to
  `item.attributes` (or `Item::name`); a `now` of `null` **removes** the
  attribute; one history row per actually-applied field; `item.updated_at`
  bumps. **History never lies:** the history row's `old_value` is the item's
  *actual current value at apply time*, never the submitter's possibly-stale
  `was` snapshot; a field whose current value already equals `now` is skipped
  entirely. An edit whose target item has vanished fails loudly (transaction
  rolls back) rather than silently approving with no effect.
- **reject** → `submission.status = rejected`; for `new` submissions the item
  row flips to `rejected` but is **kept** (never served) until retention GC
  (§8). Rejecting an `edit` never touches the item.
- **needs_info** → mutates nothing on the item; the submission leaves the map
  layer but stays on the queue (§6.2). **The note is mandatory for this
  decision and only this one**: the note IS the question. Sent blank, the
  rider is told "a curator needs more information" with nothing to answer,
  while the submission disappears from the map until they answer — so an
  accidental empty needs-info takes the item off the map and leaves nobody a
  way to put it back. `ModerationService::decide()` refuses it with
  `MissingQuestionException`; `/moderate/decide` maps that to JSON 422
  `{"error":"needs_info_note_required"}` (distinct from
  `undecidable_submission`: the submission is still pending and still
  decidable), and the drawer blocks the click client-side, marks the note box
  and focuses it.

Every decision also fills `decision_note`/`decided_by`/`decided_at` and writes
the submitter's outcome message inside the same transaction (§7.2). Decisions
require `ROLE_CURATOR` (access-control rule `^(/(fr|nl|de|es))?/moderate` →
`ROLE_CURATOR` in `web/config/packages/security.yaml` — locale-prefixed) and
pass the moderator area guard (§9).

### 4.1 `change_history` (entity `App\Catalog\Entity\ChangeHistory`)

| column | type |
|---|---|
| `id` | bigint identity |
| `item_id` | bigint |
| `submission_id` | bigint NULL (NULL reserved for future curator direct-edits) |
| `field` | varchar(80) — attribute key, `name`, or `state` |
| `old_value` / `new_value` | jsonb NULL |
| `changed_by` | bigint (the deciding curator) |
| `changed_at` | timestamp |

**Append-only invariant:** no UPDATE or DELETE code path exists for this table
(tested invariant — a suite check, not just convention). No FK points *into*
it and `changed_at` is always set, so it is time-partitionable from day one.
It is the durable record behind the `[edit]` provenance tag, the drawer's
was → now diff and public history panel (`ChangeHistoryView`), and the
"last edited" freshness line. Routes have their own `route_change_history`
([route-domain.md](route-domain.md)); the two never mix.

## 5. Moderation surfaces

### 5.1 Decisions live off the queue lists

A curator must see the item in place before deciding:

- **Item submissions are decided ONLY in the map drawer** — the queue row
  links "View on map" → `/map?pending=<id>`, which flies to and selects the
  pending pin; approve/needs-info/reject live in the drawer's moderate block.
- **Route proposals are decided ONLY on their detail page**
  (`/moderate/routes/{id}` — [route-domain.md](route-domain.md)).
- The queue list pages render **no decision forms**
  (`ModerateController::renderQueue()`); `POST /moderate/decide` remains the
  single decision endpoint, content-negotiated: JSON for the drawer's AJAX
  POST (`X-Requested-With` / `Accept: application/json`), redirect-after-POST
  preserving the curator's active `country`/`region`/`type` filters for the
  HTML path.

### 5.2 `/moderate` — filterable world overview

The queue (`App\Moderation\SubmissionQueue`) lists `pending` + `needs_info`
submissions, filterable by country / region / type via query params. Filters
are **presentational**; the authorization boundary is moderator-area scoping
(§9), applied to rows, counts, and filter dropdown options alike. The row
shape returned by `SubmissionQueue` is a deliberate shared view-model consumed
by both `moderate/index.html.twig` and `map.js` (as `CC_PENDING` JSON):
`{id, itemId, type, letter, country, region, title, lat, lng, who, when,
body, was, now, riderReply, priorRejection}` — `who` is the stable pseudonym
`RiderPseudonym::for()` (`rider#<hash4>`), `was`/`now` are the server-joined
diff strings. Contributor identity is never exposed to curators beyond the
pseudonym.

**`priorRejection` — the decision this curator may be about to reverse
(2026-08-14).** A rejected place is **revived, not twinned**, when somebody
proposes it again (`CatalogContributionService::submitDraft()`, 2026-08-12 —
the unique key spans `(source, source_ref, letter)` and counts rejected rows,
so twinning was a constraint error in the rider's face), and the earlier
report and its rejection therefore hang off the
same item id. That is exactly what makes overturning possible, and it is also
what hid it: the row carried the new report and nothing else, leaving the
curator to reverse a verdict they did not know existed. `SubmissionQueue`
now reads the **newest** `rejected` submission per item id in one query
(`priorRejections()`, `DISTINCT ON (item_id)`, `decided_at DESC NULLS LAST,
id DESC`) and travels `{when, note}` — an ISO timestamp formatted at render
time by the reader's own date preference (`cc_date` on the desk,
`window.ccDate` in the drawer), and the previous curator's own words. `null`
for the ordinary case. It renders on **both** surfaces, because the desk is
where a curator triages and the drawer is where the decision is actually
made (§5.1): `.q-prior-reject` on the card and `.cc-mod-prior` directly under
the pending badge. It stays visible in list density, unlike body/diff/photos
— it changes what the decision *is*, not the context it is made in.

**One list system for every shell page (owner 2026-08-25).** The record card
below, the page head (eyebrow · title · count or lead), the container
(`.dbody`, 1120px, `id="main"`), the filter bar (`.mod-bar` holding
`.mod-filters` selects + Apply and/or a `.lfilter` row of `.lchip` chips), the
status pill (`.q-pill--ok|pend|conf|rej|ret|danger`), the quoted note
(`.q-note`, `--answer`, `--prior`), the was → now diff (`.q-diff`, as two lines
on the desk or as a `<dl>` on the rider pages, foldable as `details.q-diff`),
the density switch (`account/_density.html.twig`) and the pager are defined
**once**, in `account/_shell_styles.html.twig`, which includes
`moderate/_card_styles.html.twig`. Every list page draws from there: the
submissions desk, History, Routes, Takedowns, Translations, Data, Regions, and on the rider
side `/profile` (contributions, route proposals, curator applications, votes)
and `/messages`. A page's own `<style>` block keeps only what is truly its own
(the data desk's side-by-side pair, the takedown photo size). A rider reading
their own contribution and a curator deciding it are looking at one card; the
desks that had grown their own row shapes (History's one-liners, Takedowns'
tinted log, the data desk's green Yes) are on the card and the orange button
system like everything else. The empty state is left-aligned prose on every
page (`.empty-state`; the centred block and the ▲ ornament are gone).
`tests/js/shell-list-system.test.cjs` pins it: no shell page may define a
shared rule locally, use the public `.wrap`, or render a row that is not a
`.q-item`.

**Category icons have one home (owner 2026-08-25: "use the same as in the
map, and make sure these are the only ones in the system").**
`ItemType::icon()` is the glyph per type and `ItemType::svgPath()` the drawn
path for climbs, scenic views and toilets; `ItemType::iconSet()` packs both
by letter. The map page injects that set as `window.CC_TYPE_ICONS`
(`MapController`), and `catalog.js` (`TYPE_ICON`, `TYPE_SVG`) and `icons.js`
read it; server pages render it through `cc_type_icons()` in
`partials/_type_icon.html.twig`, which every record row (contributions,
votes, History) opens with. Never an emoji literal in a template or a module,
and never a second set. A row shows: icon · type tag (new / edit) · title ·
kind, then date · Map · status on the right; History is that same row with
its everyone/mine and approved/rejected chips on top (trashed rows join the
unfiltered first page, as before).

**Queue item layout (2026-08-02, owner).** The desk is a queue worked dozens
at a time, so the row is sized for that:

- **One action row, four buttons** (`.q-acts`), sharing the card's **top
  block** with the heading (`.q-top`): an ordinary submission is two lines
  tall, near enough the list view that the default card costs nothing to scan,
  and a card only grows when it carries something a curator must look at — a
  photo, an edit diff, the rider's words, a reply. The buttons are the primary
  **Review ↗**, then Message the rider / Escalate / Trash. The last three were
  link-ish `<summary>` text stacked in a two-column block below the primary
  action, which cost two horizontal rules and two extra rows for three
  controls. Since 2026-08-25 all five are icon-only (◎ review on the map,
  ✎ edit the form, ✉ message, ⚑ escalate, the bin), the word kept as the
  tooltip and the accessible name: five worded buttons repeated per row read
  as a wall on the list view (owner). A new place also carries the OSM
  identity chip in its meta line (open / linked / not in OSM,
  [catalog-data-model.md](catalog-data-model.md) §5b). On the map, a
  brand-new submission opens in its final form: `MapController` attaches the
  item's would-be feature (`CatalogProvider::featureForItem(id, anyState: true)`,
  the one place the served-state gate is bypassed, curators only) as
  `preview`, and the drawer renders it with the live-item renderers (rows,
  gradient strip, length) under "This item, as proposed" instead of the raw
  field dump; a climb's after-line is coloured by its bars (2026-08-25). They keep their order — **Escalate before Trash** (§6d of
  [photo-uploads.md](photo-uploads.md)), so a curator reaching for "destroy
  this" because it is illegal meets the right verb first.
- **Both destructive verbs open with WHEN to use them and close with a ticked
  acknowledgement** (`.q-act-when`, `.q-act-ack`). A button label cannot carry
  the difference between "this edit is wrong" and "this content is criminal",
  and the two mistakes are not symmetrical: Trash on a merely-wrong
  contribution destroys it for good, and Escalate on spam spends an
  administrator's attention. The tick is a UI speed bump — native `required`,
  no POST until ticked — and is deliberately **not** the real gate: the
  server's own checks are unchanged (a reason for Escalate, the typed `DELETE`
  for Trash, both re-checked server-side).
- **No link to the public wiki rulebook** (2026-08-03, owner). A world-readable
  page describing how moderators decide what to destroy is a social-engineering
  aid: it tells anyone which words get a submission trashed and which get it
  escalated. The link is gone from all three desks (submissions queue, routes
  queue, routes detail). The rulebook becomes a **moderators-only page reached
  from the moderation menu**; until that page exists these panels carry no link
  rather than a public one. The `moderate.trash.rulebook_link` string is kept
  for it.
- **The rulebook PDF is streamed to curators, never served from public/**
  (2026-08-25 link; 2026-08-26 owner: "the file should be streamed to the
  client, not via a hidden public link accessible to everybody who knows it").
  `CC_RULEBOOK_PDF_PATH` names the file on the server: a path, not a URL,
  relative paths taken from the project root, committed default
  `var/private/moderator-rulebook.pdf` (`var/` is git-ignored, so a fresh
  checkout has no file and no link). `ModerateController::rulebookPdf()`
  (`GET /moderate/rulebook.pdf`, `moderate_rulebook_pdf`) sits behind the
  class-level `ROLE_CURATOR` gate and the `^/moderate` access rule, answers
  with a `BinaryFileResponse` (inline, `Content-Type: application/pdf`,
  `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow`) and
  404s when nothing is configured or the file is missing; the rulebook page
  renders the "Download the rulebook (PDF)" link only when the file really
  exists (`rulebook_pdf` is a boolean, not a URL). The file is owner-managed:
  nothing in the app writes, uploads or validates it. Two homes were tried
  and rejected the same day: `assets/` (AssetMapper would compile it into
  the public build under a hashed name AND git would track it) and
  `public/moderation/` (nginx serves it to anyone holding the URL, so the
  page's gate would protect nothing). `RulebookPdfTest` pins anonymous →
  login, `ROLE_USER` → 403, curator → PDF with those headers, link present
  only with the file, 404 without it. **Deploy prerequisite:** copy the PDF
  to the configured path on each frontend and set `CC_RULEBOOK_PDF_PATH`
  (`.env.staging` carries `replace-me`).
- **The rulebook prints as a document, and that is how the PDF is made**
  (2026-08-25, owner). The template carries a `@media print` sheet so the file
  is the page itself: the shell (brand bar, tabs, chip, footer, skip link) and
  the download link are hidden, a print-only masthead shows
  `brand/logo-nav-light.svg` (the dark-bar `logo-nav.svg` paints most of the
  wordmark in paper colour, invisible on paper), the moderators-only badge
  text and the print date. Colours are forced (`print-color-adjust: exact`)
  so paper, clay and ink survive. The body's paper-grain overlay
  (`body::after` in `atlas.css`) is hidden in print because a fixed noise
  texture rasterises every page into a full-bleed bitmap (13 MB for four
  pages; 230 KB without it). Three more print facts, each learned from a
  wrong PDF (owner 2026-08-26):
  - **Paper to the edge of every sheet.** Chrome paints `@page` margins white
    whatever the canvas colour, so the page margin is 0 and the body is wrapped
    in `table.rb-sheet`, whose empty `thead`/`tfoot` rows repeat on every
    printed page and supply the 16 mm top and bottom gaps; the side gaps are
    cell padding. On screen the wrapper is plain blocks and the gap rows are
    hidden.
  - **The file carries no links.** Chrome turns every printed `<a href>` into a
    PDF link annotation, and a relative one makes Acrobat ask to "connect to"
    whichever host the file was opened from (`wsl.localhost` for a WSL path).
    The only in-body anchor (the curator room) is screen-only, with a plain
    `span.rb-print` twin for print. The logo is embedded as an image; nothing
    in the PDF references the site. Verified by decompressing the file: zero
    `/Link`, `/URI`, `/Annots`, `http`.
  - **Acrobat's "connect to wsl.localhost" on a clean file** is the file's
    location, not its content: a UNC path counts as a network site. Copy the
    PDF to a local Windows folder before judging it.
  To produce the file: log in as a curator, open `/moderate/rulebook`, print to
  PDF (A4, backgrounds on), copy it to the configured path. Nothing in the app
  writes or validates the PDF.
- **The rulebook covers every desk, and quotes no number** (2026-08-25,
  owner). The 2026-08-03 text described only the submissions queue and the
  takedowns desk, and four of its claims had gone stale: the typed `DELETE`
  (removed 2026-08-12), "currently 3 months" (a runtime system setting), "the
  photo comes off the map the moment they ask" (true for an uploader's own
  request and the intimate-or-child category only; a third-party report stays
  published until decided) and "never per moderator" (true of the admin
  activity table, not of the History desk's *Handled by me* filter). The page
  now has a section per desk (the OSM question and approve-and-confirm before
  approval; Routes with edit-before-publish, the region cap, retire and located
  corrections; Data with its two questions and dismiss-is-final; Regions with
  the map default and the about-text attribution tick; takedowns with the
  categories, the one-month reply clock, decline-is-final-per-category and the
  flood banner) plus account rules (mandatory 2FA, hard scope refusals, the
  unsafe-link marks). Every "ask" points at the **curator room**, the in-desk
  board at `/moderate/room` (§13), linked by `path('moderate_room')` since the
  route landed on 2026-08-26.
  Rule kept from the original: the rulebook names mechanisms, never settings'
  values, so it cannot rot when an administrator changes a threshold.
- **The four triggers never leave their line.** The server renders three
  `<details>`; a nonce script upgrades each into a real disclosure — a
  `<button aria-expanded aria-controls>` that stays in the row, with its panel
  moved to a `.q-panels` host below the card, one open at a time. This is an
  *enhancement*, not the markup, because a moderator without JS must still be
  able to message, escalate and trash; without it the native `<details>` opens
  inline and the trigger drops to the next line (`.q-act[open]`), which is the
  only cost of having no JS. `display:contents` on `<details>` is **not** an
  alternative — Chrome then renders the panel while it is closed (tested
  2026-08-03). Each panel carries an explicit hook — `.q-act--message` /
  `.q-act--escalate` / `.q-act--trash`, copied onto the upgraded button — so
  neither tests nor CSS depend on sibling order.
- The long sentence that used to *be* the trigger ("This is illegal content —
  escalate it") is now the panel's own heading, read at the moment it applies;
  the button says **Escalate**.
- **Header on one line**: type tag, title and age share a row, with the
  submitter and **where it is, in words** beneath — the region and country the
  row already carried, with the exact coordinates on hover (2026-08-12). Three
  decimals of latitude are not something a curator can picture, and the map is
  one click away in the same row for the case where the exact spot is the
  question. A nearby-town label would need a gazetteer we do not hold
  server-side (town names come from Photon, in the browser); pulling
  `place=city/town/village` into the coverage extract is the way in if it is
  ever wanted.
- **The submitter's chosen name, when they chose one.** `submitterLabel()`
  honours `public_profile`: a rider who has made their profile public is shown
  by display name, and everyone else stays `rider#<hash4>`. Showing the
  pseudonym to a rider who had deliberately gone public read as the setting
  being broken (owner-reported 2026-08-12).
- **Thumbnails in the row, in both lists** (2026-08-12). A photo is the fastest
  thing to judge and the queue row showed none, so a curator had to open the map
  to learn whether there was one at all. `pendingPhotos()` takes a `settled`
  flag: the open queue lists photos still awaiting a verdict, the history lists
  the **approved** ones — those objects are live and are what the row is a
  record of, while rejected media is deleted on expiry
  ([photo-uploads.md](photo-uploads.md) §6) and would leave a broken thumbnail
  on an audit trail.
- **Cancel actually cancels.** The desk replaces each `<details>` with a button
  and moves its panel into `.q-panels`, so a cancel handler that walked up to
  `details.q-act` matched nothing and did nothing (owner-reported 2026-08-12).
  The handler closes on `.q-act-panel`, which is true in both the enhanced and
  the JS-less shape.
- **The history shows trashed submissions too**, rebuilt from the content-free
  audit log (2026-08-03). A curator who trashes something and then cannot find
  it anywhere reasonably wonders whether it worked. The row is deleted, so the
  entry comes from `admin_action_log` (`trash_submission`) and shows **only**
  the reference, the type, who trashed it and when — never the title, because
  preserving that would preserve the spam Trash exists to destroy. It is
  unscoped (the audit carries no region, and a content-free row leaks nothing)
  and it is hidden when an approved/rejected status filter is active, since a
  trashed row is neither.
- **Density switch** (`cards` ↔ `list`, remembered in `localStorage` per
  browser, never in the URL — it is a view preference, not a filter). List
  folds every row to a single line and hides body, diff, photos and rider
  reply. That is safe precisely because **decisions are not made here** (§5.1):
  everything hidden is review context the map drawer shows again. The switch
  is revealed by script, so a JS-less curator keeps the full-context cards.

**The routes desk joined the shared card (2026-08-03).** It was the last desk on
the older `.msg-rider` stacked layout. A curator moves between `/moderate`,
`/moderate/routes` and `/moderate/takedowns` in one sitting, so the card must
not change shape under them — and one desk learning something the others do not
is exactly how the routes desk ended up months behind.

- **The card system is now a partial, not a copy.** `moderate/_card_styles.html.twig`
  holds the `.q-*` CSS and `moderate/_card_script.html.twig` the two progressive
  enhancements; every desk includes both. It began as the submissions queue's
  own `<style>` block, which is why it never reached the others.
- **The disclosure script is driven off `.q-acts`, not off the card.** A desk
  may put an action row somewhere that is not a queue row — a route proposal's
  DETAIL page has exactly one, belonging to the page — so the script finds every
  action row and hangs its panel host after it. The panel id falls back to the
  row's index when no `[data-item-id]` ancestor exists.
- **The density switch folds every `.q-list` on the page.** The routes desk has
  two sections (proposals and corrections) and a curator switching density means
  the desk, not one section of it.
- **Route corrections are one partial now** (`moderate_routes/_correction_item.html.twig`),
  shared by the overview and a route's own page. They had drifted into two
  different cards for the same thing: the overview carried Message + Trash, the
  detail page carried Trash alone — and only one of them told a curator *when*
  Trash is the right verb, or asked for the typed `DELETE`. The weaker of the
  two was guarding the destructive verb.
- **Trash on the routes desk now carries the WHEN copy and the ticked
  acknowledgement**, like the submissions desk. **Escalate is deliberately
  absent**: a route proposal is a GPX and a note, and the escalation path exists
  for uploaded imagery ([photo-uploads.md](photo-uploads.md) §6d).
- `tests/Messaging/CuratorMessageTest` reads its CSRF token from
  `.q-act--message` instead of `.msg-rider`. Per-panel hooks, not sibling order,
  so a desk gaining another action does not move it.

### 5.3 Curator payload gating on `/map`

`/map` stays public. `MapController::map()` emits the pending payload
(`window.CC_PENDING`, `window.CC_IS_CURATOR`, `window.CC_MOD_TOKEN`) **only**
when *both* hold:

1. `is_granted('ROLE_CURATOR')`, and
2. mandatory 2FA is complete — `!TwoFactorPolicy::requiresSetup($user)`. `/map`
   is on the 2FA-setup enforcer's bypass list (public, cacheable), so without
   this second gate a setup-pending curator would receive un-vetted submission
   data before finishing 2FA.

For anyone else the page source contains **zero** pending data — gating is by
absence, backed by the decision endpoint's own `ROLE_CURATOR` firewall rule.
The `?pending=<id>` deep link silently no-ops for non-curators.

### 5.4 Drawer decision UX

- `window.CC_MOD_TOKEN = csrf_token('submit')` — `submit` is the app-wide
  form token id ([security-architecture.md](security-architecture.md)), so
  the drawer POSTs the same token the queue form family uses; CSRF is never
  disabled. The drawer rejects if the token is somehow absent (fail loudly,
  not a confusing 4xx).
- One **optional note** may accompany ANY decision, including approve;
  approve-with-empty-note is the fast path. Note length: `Length(max: 2000)`
  on `ModerationDecisionType`.
- Keyboard: **A**/**R** *arm* the decision (ring on the button, focus moves to
  the note); **Enter** in the note sends it. Never instant-submit. Mouse
  clicks submit immediately.
- Pending pins are red-bordered teardrops (`#D92D20`, ⏳ icon) with a
  curator-only legend entry, on by default; tooltip = type · submitter
  pseudonym · age. The colour is deliberately outside the votability palette —
  pending items are not on the trust axis (moderation is the pre-public
  spam/abuse/duplicate gate, per the funnel in
  [edit-items/README.md](edit-items/README.md)).
- All submission-derived strings are HTML-escaped client-side in the drawer
  (first user-authored content rendered to other users).
- **Photos ride this same decision** ([photo-uploads.md §5](photo-uploads.md)).
  A submission's pending photos render in the drawer's moderation panel with
  the facts harvested from each file — capture month, and how far the shot was
  taken from the pin — each with a keep/drop tick that defaults to *keep*.
  The unticked ids travel as one more field on the SAME decide POST; there is
  no second endpoint and no second mechanism. Approving approves every photo
  except the unticked ones; rejecting rejects all of them with no per-photo
  escape; needs-info leaves them pending, because the rider is still being
  asked. The `/moderate` list shows the same thumbs as review context, and
  keeps routing the decision to the map.

**Approving keeps the curator on the item** (2026-08-03, owner-reported). The
decision used to close the drawer on a toast, so a curator had to reload to see
what they had just applied — and for an edit the map went on drawing the
pre-edit values. Now the `decide` response's `item` is used to update the map
in place *and* reopen the drawer on the applied result:

- **Pool letters** come back as a GeoJSON `feature`. `addCuratedFeature()` now
  **replaces** a feature whose id is already present instead of returning early
  — the early return was why an approved edit never refreshed.
- **N · climbs** come back as the `climb` object map.js consumes, rebuilt
  through the same mapper the bulk payload uses (`climbFromRow`), so the
  live-updated climb cannot drift from the served one. Climbs were previously
  excluded from `featureForItem()` along with A and R.
- **A · segments and R · routes** still send nothing and keep the old
  close-and-toast: a segment's geometry and the route domain are not worth
  half-supporting on this path.

**A pending card must name the change and show what it changes** (2026-08-03,
owner-reported). Two defects, one cause:

- The proposed-change block was gated on `was && now`, and the queue card's
  diff on `was` alone. A field with **no previous value** therefore rendered no
  change anywhere — and that is the entire "+ add a missing field" funnel, the
  commonest contribution there is. A curator got a card naming an item and
  never naming the change they were being asked to approve. Both are now gated
  on `now`; the struck-out line appears only when there is something to strike
  out.
- Even with the change shown, `shade: Exposed` means nothing on its own. An
  edit's card now carries **the target item's own rows** ("This item today"),
  read from the catalogue the map has already loaded and rendered with the
  drawer's own row renderer (`recRowsHtml`, extracted for exactly this — a
  second renderer is how an escaping rule gets forgotten in one of them).
  Edits only: a `new` submission has no prior item, and its values *are* the
  proposed change. Resolved at drawer-open time, not when `CC_PENDING` is
  built — that runs before catalog-load has populated the layers.

### 5.5 Map layer vs queue visibility

`SubmissionQueue::pendingForMap()` serves **strictly `pending`** rows —
needs-info pins are hidden from the map until the rider answers (§7.3), while
the queue list shows both `pending` and `needs_info`.

### 6.3 The submitter's own answer — `item_confirmation.source`

A rider adding a water point answers "Potable?" on the improve form. Asking
them the same question again the first time they open the place they just
added reads as though the answer went nowhere — so on approval it is carried
across as their stance (`ModerationService::carryOwnAnswer()`), and the drawer
shows it back as *"You answered: Potable — when you added this place"* instead
of putting the question.

It is recorded as **`source = 'form'`**, and form-sourced rows do not count:

- not in the public tally (`ItemConfirmationService::snapshot()`,
  `CoverageRepository`) — "2 riders confirmed" must mean two riders confirmed
  it, not one rider plus the person making the claim;
- not towards the verification threshold (`ItemConfirmationService::verifyIfEarned()`,
  §10), which is the load-bearing half. Counting a submitter's own answer
  would let them supply one of the confirmations their own contribution needs,
  with one stranger's tap then enough to verify it. The funnel in the lifecycle
  copy ("after a few of them agree it is really there") depends on that not
  being possible.

Answering in the drawer later **promotes** the row to `drawer` (the submitter
has now confirmed it as a rider, and it starts counting); a form answer never
demotes a real confirmation. "Unknown" is not a claim either
way and carries nothing, so the map still asks. Only new-item submissions carry
an answer across — an edit that sets `potable` on somebody else's item is left
to the ordinary drawer confirmation.

Owner decision, 2026-08-02: recorded but not counted, over counting it fully.

**An approval puts the item on the map straight away.** The decision response
carries the approved item as the catalog's own GeoJSON feature
(`CatalogProvider::featureForItem()`, the same per-row mapping the bulk payload
uses, so a live-inserted feature can never drift from the served one), and the
drawer inserts it into the pool it belongs to (`addCuratedFeature()`,
idempotent by item id). Before this the pending pin simply vanished on approve
and the place appeared nowhere until the curator reloaded — the map's pools are
built once, at boot. It joins as a **community** pin (dashed, `v` absent):
approved is not confirmed, and only a rider's confirmation flips that. Letters
whose payload is not a feature collection (A segments, R routes) send
no item and keep the reload behaviour.

**The decision buttons are delegated, not bound per element (2026-08-03).**
This is the part that actually broke, and it is worth stating plainly because
the failure was invisible.

`drawer.js` used to bind a click listener to each `.cc-mod-btn` immediately
after writing the card. The drawer body is re-rendered often — the async
"Recent changes" fetch alone rewrites it after open — and a per-element
listener does not survive that: the buttons come back looking identical and do
**nothing at all**. No request, no toast, no error, nothing to tell a curator
their click had not registered. It presented as intermittent, because whether
it worked depended on whether a re-render happened to land between opening the
drawer and pressing the button.

`community.js` already delegated every other community button off `document`
for exactly this reason, and says so in a comment: *"drawer is re-rendered
often"*. The moderation buttons now do the same. `submitModeration()` itself
was never at fault, and neither was the endpoint: it answers a real approve in
~150 ms with the full `item.climb` payload.

Verified after the change: approve → the pending pin goes, the drawer closes
and reopens on the applied result, the toast names the reference, and the
drawer's own attribute row shows the newly approved value.

**A note for whoever tests this next.** Playwright's `page.click()` dispatches
no events at all against this drawer in the dev browser (SwiftShader, no GPU) —
a document-level capture listener sees nothing, while `element.click()` works
normally. Any probe that concludes "the button does nothing" from `page.click()`
alone is measuring the harness. That mistake cost a full diagnosis here, and
produced a confident, wrong root-cause write-up (a CSRF-token theory) that this
paragraph replaces.

### 5.2a Geometry is summarised, never dumped (2026-08-03)

A climb edit used to fill the curator's card with the raw payload: two hundred
coordinate pairs, then the gradient array, then the marker JSON. Nobody can
decide anything from that. The one question a curator has about a redrawn climb
— did it get longer, and where does it end now — was the one thing the card did
not answer (owner: "this is pretty useless").

`App\Contribution\ChangeValue` formats a changed value for a human, and both
diff paths use it: `SubmissionQueue::diffStrings()` (desk cards, the map
drawer's pending card, the history) and `SubmissionChangeSummary` (the rider's
own contributions and messages). Geometry becomes:

| field | reads as |
|---|---|
| `route` | `2.5 km · foot 50.4832, 5.7039 → summit 50.4860, 5.6927 · 101 points` |
| `grad` | `11 samples · -2% to 16%` |
| `steep` | `~20% at 50.4908, 5.7058` (plus *(placed by hand)* when manual) |

The length is a real great-circle measurement of the polyline, not a point
count, so `2.0 km → 2.5 km` states the actual change. A negative low end on
`grad` is information rather than noise: it means the extension descends.

Everything else is untouched — a word stays a word, a multi-select joins with
commas. Malformed geometry degrades to something printable rather than throwing,
because a broken payload must not take the whole queue card down with it.

### 5.2b Before/after for a proposed shape (2026-08-03)

Summarising geometry as text (§5.2a) made the card readable, but it did not make
a redrawn climb *reviewable*: `summit 50.4860, 5.6927` still says nothing about
whether the summit moved somewhere sensible, and the map went on drawing the
climb exactly as it is today (owner: "a human can't handle this data — we need
to see it on the map").

A pending card for a climb now carries a **Shape on the map · Before | After**
switch, and the map draws whichever side is selected:

- **After** — solid, in the violet the change history uses for a new value. It
  is the default, because the curator is there to judge the proposal.
- **Before** — muted grey and dashed, the shape the item has today.

Both sides travel in the pending payload (`SubmissionQueue::shapeSides()`, keyed
`shape`) rather than being read from the loaded catalog: a brand-new climb has
no current feature to compare against, and a changed `route` with an unchanged
`grad` would otherwise be coloured from the wrong profile. The switch is
rendered only for the sides that exist — a new climb gets no dead "Before".

Opening the drawer fits the map to the drawn side, because a summit moved a
kilometre can otherwise land off screen, and an overlay nobody can see is not a
review.

**A switch, not both lines at once** (owner's call, stated twice). The two
shapes usually share most of their length and diverge near one end, so drawn
together they overlap into a single thick smear precisely where the difference
is. Flipping one line in place makes the change read as movement.

Implementation notes worth keeping: the overlay owns its own source and layers
(`cc-pending-shape*`) and clears them before every draw, so switching sides can
never leave a second line behind; **all layers are removed before the shared
source**, because removing a source still in use by a sibling layer throws, and
a throw there leaves the old side on screen while the switch says otherwise. The
switch is delegated off `document` like every other card control (§5.2).

### 5.2c The Before side never claims a road is unrecorded (2026-08-31)

A new stretch has a Before, and it is the same road: an unavailable Before
button told a curator nothing about what the proposal replaces (owner
2026-08-12). That Before used to be flagged `unrecorded`, which the client draws
in the legend's red "Surface not recorded" dashes.

`shapeSides()` cannot know that. A new stretch means WE held nothing for that
way; OSM's surface tags live in the tile artifact and never reach the query. On
a road nobody has tagged the flag happened to be right, which is why it survived
since August; on a road OSM describes it was a red line asserting ignorance that
the drawer beside it disproved, reading "Paved · asphalt · OSM" two inches away
(owner-reported 2026-08-31).

The Before is drawn in the neutral before-style instead: where the stretch sits,
without claiming what was known about it. **This reverses the 2026-08-12
decision and the cost is real:** a genuinely untagged road no longer stands out
here. That signal was only ever correct by luck. Getting it back means asking
the tile under the way what class it carries, which is a client-side question;
the `unrecorded` key stays in the shape's type and `pending-shape.js` still
styles it, so the answer has somewhere to land. §5.2d records the baseline that
would make it answerable.

### 5.2d What OSM said, so a curator can see what changed (2026-08-31)

A rider can turn an asphalt road into a gravel one. Usually they are right,
having ridden it; when they are not, the desk is the only place anybody would
notice, and the desk could not tell. `was` is what OUR catalogue held, which for
a new item is nothing, so changing what OSM already said produced exactly the
same submission as describing a road nobody had touched.

The map already knows: it draws the tile and prints the OSM values in the drawer
two inches from the edit button. It now carries them on the edit link
(`osm_surface`, `osm_highway`), `ContributeController::osmBaseline()` maps them
through the form's own vocabularies, and `CatalogContributionService::newItemChanges()`
records them as the side the rider changed FROM. The desk needs no new UI: the
card already draws `was → now`, so a contradiction reads as
**SURFACE Asphalt → Gravel**, which is the trigger to look twice.

Only surface and road type: they are the two with server-side OSM mappings
(`SurfaceVocabulary::fromTileClass()`, `RoadType::fromHighway()`). Smoothness is
mapped client-side only, so there is nothing here to map it with, and a guessed
baseline is worse than none. An OSM value the form cannot express is left out.

Safe on this path specifically, because `approveNew()` applies nothing from the
change map: `was` here is a display fact and nothing reads it as state. On an
edit `was` keeps its old meaning, which `applyEdit()` still compares against.
Showing the OSM value even when the rider AGREES with it needs its own field
rather than more weight on `was`, and is an optional item in docs/TODO.md.

### 5.2e A new item is reviewed as itself, not as a diff (2026-08-31)

There is nothing to diff a new item against, so the item **is** the proposal: it
waits in state `submitted` holding exactly what the rider asked for. The desk
therefore builds a NEW submission's rows from the item's own attributes rather
than from its `changes` map (`SubmissionQueue::changeRows()`), with `was` null
throughout, because everything is proposed from nothing.

That is also what makes the card survive a revision. A revision is diffed
against the item, and the item already carries the earlier round, so the second
round records nothing for those fields. `mergeChanges()` (§7.3b) keeps them in
the map from now on; reading the item is what shows the submissions filed before
that existed, without rewriting their rows. Two independent reasons for the same
answer, which is why this is the rule and not a patch.

Shape fields stay out either way. A stretch or a line is reviewed on the map
(§5.2a, §5.2b) and never as text, whichever side the rows are built from.

### 7.3a A rider can see WHAT they contributed (2026-08-03, owner)

Both rider-facing surfaces named the place and stopped there. "Your
contribution Côte de la Redoute was approved" and a contributions row reading
`EDIT · Côte de la Redoute · APPROVED` are the same text for every edit to that
climb — a rider who fixed the gradient on Monday and the surface on Tuesday saw
two identical rows and could not tell which one a curator had acted on. The
information was already in the submission; only the curator's desk was showing
it.

Both now render the change, as the same was → now shape the desk's `.q-diff`
uses:

- **`/profile` contributions list** — under every row that changed something.
- **`/messages`** — inside the decision card, headed "What you changed", **folded
  by default** behind a small "Show details" link (a native `<details>`, no
  script). The card says what happened; the diff is there for the rider who
  wants to check it, not pushed at everyone (owner 2026-08-25). The unread
  card is outlined, with no thicker left edge (same decision).
  **Not** on a needs-info card: there the curator's question is the point, and
  a diff above the reply box pushes it down the page.

**Field names are the form's labels, not the storage keys**, which is the one
place this deliberately differs from the desk.
`App\Contribution\SubmissionChangeSummary` resolves them through
`CatalogFormRegistry` — a rider reads "Road quality", never `sq`. A key the
registry no longer carries keeps the key rather than vanishing: showing
`hairpinsOld` is honest, showing nothing would hide part of what somebody
submitted. Multi-selects join with commas, because
`["Step-free","Handbike-friendly"]` is not an answer anyone gave.

The struck-out half appears only when there was a previous value — adding a
missing field is the commonest contribution there is and has nothing to strike
out. That is the same gate the desk's card learned on 2026-08-03.

**The messages lookup is scoped by `user_id`.** A message is addressed to its
recipient, so its `refId` should already be theirs — but this reads
contribution content out of the database on the strength of an id carried by a
row, and "should already be" is not an access rule.

### 7.3b A rider revises their submission — they do not file a second one

Owner decision, 2026-08-04: **"he needs to update his update."**

A rider asked a question about their proposal has to be able to go back, see
what they proposed, change it, and send the same submission again. Before this
they could only file another one, which left the desk holding two competing
proposals for one item with nothing to say which supersedes which — and left
the curator's question attached to the abandoned one.

- **The wizard opens on the rider's own proposal.** `/improve?item=…` overlays
  the `now` side of their undecided submission's `changes` onto the form
  defaults (`ContributeController`), so the fields show what they suggested,
  not what the item currently says. Answering "is that ending really right?"
  was otherwise impossible: the form showed the current climb, and re-submitting
  would have re-proposed the item's own values.
- **Submitting amends, and amending ADDS.** `CatalogContributionService::improve()`
  looks for the rider's own undecided submission on that item
  (`openSubmissionFor()`: status `pending` or `needs_info`) and updates its
  `changes` and `payload` in place. The new round is **merged into** the
  existing `changes`, never substituted for it
  (`CatalogContributionService::mergeChanges()`), because the second round is
  diffed against the ITEM and the item already holds the first round's values
  while the submission is still pending. Replacing left the curator reviewing
  only whatever the rider touched last: a road filed with a surface, a road
  type and a smoothness, then lengthened, showed a shape and no values at all,
  and would have been approved on values never shown (owner-reported
  2026-08-31). `was` keeps the value from the round that first touched the
  field, since that is what the item held before the submission began; `now` is
  always the newest. **The reference is unchanged**, so the
  message thread the rider and curator have already exchanged still names the
  thing they are discussing.
- **A revision of a NEW submission is written to the ITEM.** For a new item the
  item is the proposal (§5.2e), and `ModerationService::approveNew()` only flips
  its state because there is by design nothing to apply. So a revision recorded
  only in `changes` was read by nobody and dropped the moment a curator
  approved: a rider filed a road, went back and lengthened it, and the approved
  item kept the first, shorter line (owner-reported 2026-08-31). Not a display
  fault; the longer road was gone. The amend now writes the revised attributes,
  and the geometry when the revision carries a stretch, straight onto the item.
  Only while the item is still `submitted`, which is exactly the window in which
  it belongs to this one undecided submission; an edit to a live item keeps
  going through `changes` and the curator, as it always has.
- **It returns to `pending`**, and the previous round's `decision_note` /
  `decided_at` / `decided_by` are cleared — a stale "we need more information"
  sitting on a freshly revised submission reads as a new complaint.
- **`was` still diffs against the ITEM**, never against the previous proposal.
  A curator must see what approving would actually change.
- **A decided submission is never amended.** Approved or rejected is history; a
  further change to that item is a new contribution, which is what it is.

Both halves are pinned by `ImproveBindingTest` — the amend, and the refusal to
amend anything already decided.

### 7.3c The form says a change is already waiting (built 2026-09-12)

With a review backlog two riders can propose the same correction to the same
item without either knowing. It costs the second rider their time and a curator
a second reading of the same change, and until now nobody outside the desk
could tell: a curator saw every pending row, the rider who filed one saw their
own, everybody else saw nothing.

**Submission path only.** On the `/improve` form for that item, before ten
minutes go into it. NEVER near the confirm buttons: confirming is the path
where duplication is the POINT, and the visible tally there exists to invite
the second and third rider. The two look alike from outside, which is why this
is written down.

**Existence and age, never who and never what.** `CatalogContributionService::otherPendingSince()`
returns one timestamp or null. The content is unreviewed and stays unpublished,
and the age is the only part a rider needs in order to decide whether to
bother. Pinned by a test that fails if the author's name, their address or the
proposed value reaches the page.

**Warn, never block.** The form underneath still works: a second rider may have
something genuinely different to say, and a hazard often deserves re-reporting.

**Two sentences, never merged.** A rider's own waiting change is amended by
what they send next (§7.3b), so they are told that; somebody else's is a second
review of possibly the same thing, so they are told that instead. One generic
sentence would read as a lie to whichever rider got the wrong one, which is why
`otherPendingSince()` excludes the reader's own row rather than the caller
filtering afterwards.

**Open, and the owner's call**: whether to show a count ("2 waiting") rather
than only that one exists. Built without the count, which is the half both
answers agree on. The privacy question behind it is the same one: revealing
that a specific item has activity is thin, but in a small region it plus a
public contributor wall narrows who.

### 7.4 Curator → rider messages — M6a

`POST /moderate/message` (`ModerateMessageController`, `ROLE_CURATOR`
firewall, CSRF `moderate-message`) is the one endpoint for a free-form desk
message on any channel; a `channel` discriminator (`submission` / `route` /
`correction`) selects the referenced row and the **recipient is always
resolved server-side** from it, never trusted from the request. The target's
region must be in the curator's moderation scope (§9). Redirect-back trusts
only the Referer's *path*, and only when it points into `/moderate` (open
redirect guard). Imported routes (no proposer) and dangling author ids resolve
to "no recipient".

### 7.5 Unread bulb — M4/M5

Server-rendered per page load by a Twig extension (one COUNT query) inside the
shared `_account_chip.html.twig` partial — **no polling**, consistent with the
no-fetch header architecture. The map rail carries the chip, so the bulb
reaches the largest logged-in surface.

### 7.5a Paging, and read state that means something (2026-08-08)

Every unbounded list on the account side pages through
`App\Pagination\Pager::of()` and renders `partials/_pager.html.twig` — the
same partial and the same arithmetic the moderation desks use. It moved out of
`templates/moderate/` and its keys out of `moderate.pager.*` into a top-level
`pager.*` group the moment a second surface needed it.

- **Messages** — 20 per page (`MessageService::PER_PAGE`). Before this the list
  stopped at a hard 100 with nothing saying so.
- **Contributions and route proposals** — 20 each, on one pane, paging
  independently through `?page=` and `?rpage=`. A shared parameter would have
  moved both lists when the reader meant to move one, so the partial takes a
  `key`.

Two consequences worth stating, because both are places a naive pager gets it
wrong:

1. **A question and its answer never split across a page.**
   `MessageService::listFor()` returns thread HEADS only (the reader's own
   needs-info replies excluded), and `repliesBySender()` re-attaches the
   replies for the questions on that page. Paging the flat list would sooner or
   later put a reply at the foot of one page and its question at the top of the
   next, where each reads as an orphan.
2. **Only what was shown is marked read.** `markRead($userId, $ids)` replaces
   the old `markAllRead()` on the dashboard. Marking everything read on a visit
   would consume the unread state of messages sitting on page 3 that nobody
   opened — the unread marker would be a lie and the §7.5 bulb would drop to
   zero over unread mail. `markAllRead()` still exists for callers that really
   do mean all of it.

Unread is also *visible*, not just counted: a received message carries a state
marker in its header — a single tick for unread, a double tick for read, with
the state name in the accessible name — and an unread row keeps the heavier
left border and bolder heading. A message the reader **sent** carries no marker
at all; it was never unread to them.

### 7.5b The sweep — every unbounded list pages (2026-08-09)

§7.5a paged the account side. This closes the rest: **every list in the
application that grows without bound now pages**, through the same
`Pager::of()` and the same partial, so "page 3 of 12 · 240 in total" means one
thing everywhere and an out-of-range `?page=` lands on the last page rather
than on nothing.

| Surface | Rows/page | Constant |
|---|---|---|
| Messages | 20 | `MessageService::PER_PAGE` |
| Contributions · route proposals (`/profile`) | 20 each | `ProfileController::PER_PAGE` |
| Submission queue · decided history | 25 | `SubmissionQueue::PER_PAGE` |
| Routes desk — proposals · corrections | 25 each | `RouteQueue::PER_PAGE` |
| Takedown desk | 25 | `MediaTakedownService::PER_PAGE` |
| Withheld-photo recovery (admin) | 25 | `MediaTakedownService::PER_PAGE` |
| Legal holds — photos · submissions (admin) | 25 each | `MediaEscalationService::PER_PAGE`, `ModerationService::HELD_PER_PAGE` |
| Curator applications (admin) | 15 | `CuratorApplicationService::PER_PAGE` |
| Regions desk | 25 | `ModerateRegionsController::PER_PAGE` |
| Contributors wall (public) | 60 | `ContributorWallProvider::PER_PAGE` |

Five things this sweep settled that a page-size change alone would not have:

1. **Every list and its count read one shared WHERE.** `RouteQueue`,
   `MediaTakedownService` and `ContributorWallProvider` each grew a private
   predicate (or source builder) that the page query and the count query both
   use, the arrangement `SubmissionQueue` already had. A pager whose count
   comes from a second, hand-kept copy of the filter drifts the day either one
   is edited.

2. **A desk badge is not a pager count.** `RouteQueue::total()` and
   `pendingSuggestionCount()` deliberately ignore the region filter — a
   curator who has narrowed to one region should still see how much work the
   whole scope holds — so the pagers read new `pendingCount()` /
   `pendingSuggestionsCount()` methods that DO follow the filter. Both numbers
   are correct and they are different numbers.

3. **The takedown badge stopped building cards to count them.**
   `deskBadges()` called `count($this->takedowns->pendingCards())`, hydrating
   every pending upload and describing each one to arrive at an integer — on
   every render of every moderation page, since the badge rides the shared
   shell. It is `pendingCount()` now.

4. **The Regions desk slices before it measures.** A global curator sees every
   onboarded region on earth (Japan alone is 47 prefectures) and each row costs
   a readiness count, so the page is taken before `reportForRegions()` runs.

   **This is also what fixes the desk's ordering (2026-08-14).** The list is
   grouped **continent → country → region**, and because the slice happens
   before the reports are built, the `ORDER BY` has to *be* the display order —
   sorting after the slice would shuffle rows within a page while leaving the
   page boundaries wrong. So the grouping is a SQL sort (continent name,
   country name, then `area_km2 DESC`) with the nesting assembled from the
   already-ordered rows. Continent and country come from the World reference
   bundle, LEFT JOINed: a region whose country is missing from it groups under
   `—` at the end rather than disappearing off a moderation surface.
   Within a country the order stays area-descending — region *labels* are
   translated, so sorting by them would mean sorting in PHP, which the slice
   forbids.

   **Each country is a collapsed `<details>`** (owner, 2026-08-14): at 19
   countries the grouping alone still left several screens before a curator
   reached anything. It opens when the country filter has narrowed to it, or
   when it is the only country on the page — the two cases where a shut group
   would make the desk look empty. The `<summary>` carries `N of M ready`, so a
   closed group still answers *is there anything to do here*; a collapse that
   hides the number you came for is not a saving.

   The three mode buttons follow the **map's own order** — Best of · Confirmed
   · Everything (`map/index.html.twig`) — where the desk had them reversed. A
   curator setting a region's default now meets the rungs in the order the map
   presents them, which is also the order their gates unlock in.

5. **The contributors wall's filters moved to the server.** The name search and
   country select were JS over the rendered rows. Once the wall pages, a
   client-side filter answers "no such rider" about riders who are merely on
   page 4 — so both are query parameters (`?q=`, `?country=`) applied in SQL
   across the whole wall, the pager carries them, and the ILIKE escapes `%`
   and `_` so a rider called "100%" searches for themselves. The country
   options still come from the *unfiltered* wall, so narrowing never collapses
   the select to the one country already chosen.

Section counts show the pager's **total**, never the page's length: a "12" over
a section holding 25 of 40 is a lie about how much work is waiting.

Admin pages take `templates/admin/_pager.html.twig` instead of the shared
partial — same arithmetic, same parameters, Bootstrap's pagination classes,
because EasyAdmin pages never load the account shell's CSS that styles the
other one.

### 7.6 The FK exception — M10

`user_message.user_id` carries the schema's only real user FK, `ON DELETE
CASCADE` (migration `Version20260712150000`): messages are correspondence *to*
the person, not contributed catalog content, and a DB-level cascade covers
both account-deletion paths (self-service hook chain *and* the admin desk's
`removeAccount`, which bypasses deletion hooks). This is a documented
exception to the "contributed data is anonymised, never cascade-deleted" rule
(§5.6); a practical consequence is that decision-path test fixtures must
persist real users — fabricated ids violate the FK the moment a decision
writes a message.

### 7.7 Map links per kind

Messages link to their subject only when it is publicly on the map:
`submission_approved` → `/map?feature=<refLabel>`; `route_approved` and
`correction_done` → `/map?route=<refId>`. Rejected / retired / needs-info
kinds carry no link (subject not publicly visible).

### 7.8 Out of scope here

**M7 email delivery is BUILT (2026-08-08).** The dashboard is still the record;
email is the delivery channel on top of it, rendered per `User.locale`. Before
this, a curator could ask a rider a question and the rider found out only by
logging back in and looking — which made the needs-info loop, the one exchange
in the system that is *waiting* on somebody, the least likely to complete.

- `MessageMailer` renders from the **same** `messages.kind_*` / `messages.body.*`
  keys the dashboard uses, in the **recipient's** locale — not the locale of the
  curator who made the decision. One wording, so an email cannot drift from the
  page describing the same decision.
- The mail is a **pointer, not a copy**: headline, body line, the curator's own
  note, and a link. Never the photo, and never anything the dashboard would not
  show that same person. The note travels because on a needs-info the note *is*
  the question.
- **Sends are queued, not immediate.** `sendSystem()` runs inside the moderation
  decision's transaction, so mailing there would announce decisions that can
  still roll back and would hold row locks across an SMTP round trip.
  `MessageOutbox` collects them and `MessageMailSubscriber` flushes on
  `kernel.terminate` / `console.terminate` — after the response, after the
  commit. The mailer **re-reads the row** before sending, so a rolled-back
  decision sends nothing.
- **`RiderReply` is deliberately silent.** It is addressed to the deciding
  curator, who has a desk that already shows it; mailing every reply turns a
  queue into an inbox.
- A transport failure is logged and dropped. The row is the record.

There is **no Messenger transport**, so the terminate subscriber is the delivery
mechanism. When one is introduced the honest change is for the subscriber to
dispatch rather than send; the outbox and the re-read stay useful either way.

Not built: a per-user email preference. These are transactional messages about
a person's own contributions, so there is nothing to unsubscribe from without
also opting out of being asked questions — but if one is ever wanted, it belongs
on `User` beside `locale`.

The **M8 phase-2 scheduled GC runner** is still
**specified, pending implementation** — see `operations.md` §1, which carries
the systemd units. **M12
account lock/ban** is explicitly *not* part of this system: Trash handles the
content, the account is the admin desk's job
([account-and-auth.md](account-and-auth.md)).

### 7.9 Shelves — the inbox's three categories and its unread switch (2026-08-09)

`App\Messaging\MessageCategory` sorts the inbox onto three shelves, and the
split is by **who started it**, not by which subsystem wrote the row — because
that is the question a reader is actually asking when they reach for a filter.

| Shelf | Means | Kinds |
|---|---|---|
| **Contributions** | an answer to something the rider offered or asked for | submission approved/rejected/needs-info, route approved/rejected/retired, correction done/dismissed, **and** takedown granted/declined |
| **Notices** | something the platform did that the rider did not start | photo removed on report, hidden pending review, restored after review |
| **General** | a person wrote to them | curator message |

The takedown split is the case worth stating: a rider who *asked* for their own
photo to come down is being answered, so that files under Contributions; a
photo hidden because a stranger reported it is a notice. Same subsystem, two
shelves, because the reader's relationship to the two events is not the same —
and notices are precisely the ones nobody should have to dig for.

`RiderReply` is on no shelf by design: it is the rider's own answer to a
needs-info question, addressed to the deciding curator so the desk can find it,
and `listFor()` has always excluded it from the inbox.
`MessageCategory::allFiledKinds()` exists so `MessageFilterTest` can assert the
shelves cover the enum minus that one — a new kind nobody filed would be
invisible under every filter but "All", which is the sort of bug that surfaces
only when a rider says they were never told something.

**Unread is a switch, not a fourth shelf.** It composes with whichever shelf is
open, because "unread notices" is a real thing to want. It is recipient-only,
matching `unreadCount()`: a message the reader WROTE was never unread to them,
so it must not surface their own sent half.

The chips carry counts from one round trip (`countsFor()`, a single grouped
query) because "Notices 0" is the answer and a click to discover it is a click
wasted. Per-category totals count the same set the unfiltered list shows, sent
half included, or the chips would not add up to "All".

They are plain links, not a form: each shelf is a place the reader can bookmark
and the back button then means what they expect. An unknown `?cat=` shows
everything rather than erroring — it is a bookmark to a renamed shelf, not an
attack. A filter that hid everything says so, and does not read as an empty
inbox.

## 8. Retention and garbage collection — M8 phase 1

| Key | Value | Where |
|---|---|---|
| `moderation.retention_months` | 3 | `web/config/packages/moderation.yaml` (wired via `web/config/services.yaml`) |
| opportunistic-sweep throttle | 3600 s | `RetentionService::CACHE_TTL_SECONDS`, `web/src/Moderation/RetentionService.php` |

`RetentionService` deletes rows past the cutoff for exactly two kinds:
**rejected item submissions** (`decided_at < cutoff`) and **dismissed route
corrections** (`resolved_at < cutoff`). Three runners, no scheduler yet:

1. **Lazy point-of-use filtering** — reads exclude expired rows regardless of
   whether a sweep ever ran (e.g. `ProfileController`'s contributions list
   filters rejected-past-cutoff rows in the query). This is the correctness
   layer; sweeps are hygiene.
2. **Opportunistic sweep** on moderation desk renders — fire-and-forget,
   cache-throttled, never throws (a failed sweep must not break the desk).
3. **`app:moderation:gc`** — idempotent standalone console/cron entry point.

Deliberately **not** swept (open decisions, §14): rejected
`recommended_route` rows, never-answered `needs_info` submissions, and
approved rows.

## 9. Moderator areas — region/country scoping

### 9.1 Model

`moderator_area` (entity `App\Moderation\Entity\ModeratorArea`): `user_id`
(FK → `users`, ON DELETE CASCADE) plus **exactly one of** `region_id`
(FK → `region`, ON DELETE CASCADE) or `country_code` (VARCHAR(2), ISO 3166-1
alpha-2), enforced by the DB CHECK constraint
`(region_id IS NULL) <> (country_code IS NULL)` (migration
`Version20260714210000`); unique over `(user_id, region_id, country_code)` —
created `NULLS NOT DISTINCT`, without which PG would permit duplicate rows
through the NULL column.
A user's scope is the union of their rows. Rows are storable for any user but
inert without `ROLE_CURATOR`.

**Assigning them has two surfaces.** `/admin/user/moderator_areas` is the
per-user picker (the form `setModeratorAreas()` posts to), and
`/admin/moderator-areas` is the overview: every elevated account with the
regions it covers and a link into that picker. The overview exists because
assignment was reachable only from one user's own page, so "which regions have a
curator?" could be answered only by opening people one at a time - and nothing
in the admin menu pointed at either (owner-reported 2026-08-14). An empty
assignment renders as **Global (sees everything)**, never as "none": empty means
global, which is the opposite, and getting that backwards would misread the most
consequential row on the page.

**Neither surface may hydrate `Region` entities.** A `Region` carries its `geom`
polygon; the 271 rows onboarded by 2026-08-14 hold ~217 MB of geometry between
them, and the picker page died with `Allowed memory size of 134217728 bytes
exhausted` building a `<select>` that needs three columns. Both now select
`id, name, country_code` through DBAL - the picker rendering in ~8 MiB, grouped
into `optgroup`s by country. Raising `memory_limit` only moves the wall: every
onboarded country adds geometry, and this page is
[onboarding playbook](../../tools/divisions/README.md) step 7, so it sits on the
path of every new country.

### 9.2 Scope semantics

- **Unassigned = global**: a curator with zero rows moderates everything
  (rollout-safe, right for small teams). `ROLE_ADMIN` is always global
  regardless of rows. A 2026-08-25 security scan filed this as a fail-open
  default; it is a deliberate one, and it stays. Curators are appointed by hand
  through an audited admin action (§9.4), the appointment is the trust decision,
  and areas are a division of labour laid over it rather than the grant itself.
  Defaulting a new curator to moderating nothing would make every appointment a
  two-step act whose second step is easy to forget, and the failure mode of
  forgetting it is a silent one: a queue that looks empty. Revisit if curators
  ever stop being hand-appointed.
- **NULL-region items are in scope for every curator** — deliberate, so
  outside-all-regions submissions never fall through the cracks.
- The in-scope rule exists exactly twice, as verified twins:
  `ModerationScope::sqlFragment()` (parameterised SQL used by both queues;
  omitted entirely when global; empty lists bind impossible sentinels so the
  `IN ()` clauses stay valid; the table alias is allowlist-guarded because it
  is interpolated) and `ModerationScopeProvider::allowsRegion()` (the PHP
  write-guard predicate). Rule: `region_id IS NULL` OR `region_id ∈ regionIds`
  OR the region's `country_code ∈ countryCodes` (codes uppercased on both
  sides).

### 9.3 Enforcement — filtering AND hard guards

Hidden queue rows are not a security boundary. Scoping applies to:

- **Reads**: `SubmissionQueue` (rows, `pendingForMap` / `CC_PENDING`, totals,
  badge counts, filter dropdowns) and `RouteQueue` likewise.
- **Writes (403 out of scope, `OutOfScopeException`)**: `/moderate/decide`,
  `/moderate/trash`, `/moderate/escalate-submission`, `/moderate/escalate`
  (the photo's region comes from its owning submission; an unclaimed upload has
  no region yet, and a null region is in scope for everyone as above), every
  route moderation write (approve/reject/retire/save, trash, correction
  done/dismiss), and `/moderate/message` (the referenced row's region). The
  route detail page itself 403s out of scope. The 403-reveals-existence
  trade-off is accepted as consistent, documented semantics.

  **The two escalation paths were the gap** (security scan 2026-08-25). They
  were the only writes on the desk with no scope guard, and they are the
  heaviest verb it has: escalation puts a row into legal hold, hides its
  photos and mails a human. A region-limited curator could pull any submission
  on the platform out of its queue. Both endpoints take a bare id, so "the
  queue only shows you your own regions" was never a guard, which is the whole
  reason §9.3 opens by saying hidden rows are not a security boundary. Pinned
  by `App\Tests\Moderation\SubmissionEscalationTest`.
- **Visible scope**: the moderation shell label always shows the actor's scope
  — assigned area names via `ModerationScopeProvider::describe()`, or "All
  areas" (`account.mod_scope_all`).

### 9.4 Assignment

An audited support-desk action, not an EasyAdmin CRUD:
`UserAdminService::setModeratorAreas()` validates every region id and country
code against the reference tables, replaces the target's rows, and writes the
`moderator_areas` audit entry (resulting set as the note) in **one
transaction** — the same pattern as every other admin desk mutation
([account-and-auth.md](account-and-auth.md)).

## 10. Utility confirmations — potability & "still here?"

Utilities are **confirmed, never voted** — the complement of the votability
funnel in [edit-items/README.md](edit-items/README.md).

### 10.1 Stance model

`ItemType::confirmationStances()` (`web/src/Catalog/ItemType.php`) is the
source of truth:

| Type | Stances (`ConfirmationStance`) | `stanceKind` |
|---|---|---|
| B · Water & food | `potable` / `not_potable` | `potability` |
| B · Water & food, **authority row whose `potable` starts with "Yes"** (a register tap) | `exists` | `existence`. The register is the potability answer; asking a rider whether RIVM's tap is drinkable was the wrong question (owner 2026-09-06). `ItemConfirmationService::offeredFor()` is the one rule; the snapshot, the POST validation and `stanceKind` all read it. |
| C · Public toilets, D · Services, E · Hazards, F · Getting there, G · Shelter, N · Climbs, O · Where to sleep, P · Scenic views, Q · History & culture | `exists` | `existence` |
| A · Road surface, all remaining votable types | none — they vote, or are measured | — |

**Why the second row grew** (owner decision 2026-08-12). The old test was
"could this place vanish", which excluded a castle and a mountain. That was the
wrong question: a confirmation is a rider saying *I was there and this is
right* — that it exists, that it is where we say, that it is what we call it. A
climb can be wrong about all three, and an AI-generated photo of a viewpoint is
exactly what a second rider standing at the spot disproves. Everything a rider
can stand in front of is confirmable; voting (best-of) remains a separate,
additional funnel for the types that have it.

**Two ways to Verified, one destination.** `ItemConfirmationService::verifyIfEarned()`
promotes an item from Unverified to Verified and writes a `ChangeHistory` row
for the state change, on either of two grounds:

- **A curator's word settles it.** A single Drawer-sourced confirmation by a
  `ROLE_CURATOR`/`ROLE_ADMIN`. Three riders should not be needed to agree that
  a castle is a castle.
- **`map.item_verify_threshold` independent riders**, 2 by default
  (system-configuration.md §2). Repetition by strangers is the only real check
  this project has, since a photo can be generated and a place invented. It is
  deliberately lower than the route threshold of 3: "I rode this whole route"
  is a bigger claim than "this tap is here", and a region thin enough that
  three riders never meet at one fountain would keep a `?` on it forever. One
  row per rider is a database constraint, so a count of rows is a count of
  people.

Ruled 2026-09-09, after the owner found the hole: "if the `?` mark is gone, it
has verified state". Until then a lone confirmation removed the badge on the map
while the record stayed Unverified, so the map and the database disagreed about
what verified meant and a rider clearing a badge changed nothing a curator could
see. `CatalogProvider`, `PublicItemsProvider` and `CuratedReadiness` now all read
`state = 'verified'` and nothing else (map-and-search.md §12).

**The curator's word is recorded, never inferred** (owner 2026-09-10:
"curator confirmed, as this is stronger"). `item_confirmation.by_curator` is
written at the moment of the answer, from the roles the confirmer held then, and
never comes back off: a rider promoted to curator strengthens their standing
answer on their next tap, and a demotion cannot rewrite who was standing there.
`Version20260910170000` adds the column and backfills it from the roles each
confirmer holds today, which is the only evidence the older rows left.

Two readers used to reconstruct it from arithmetic instead, and both named the
wrong witness the moment `map.item_verify_threshold` moved or one more rider
confirmed:

- the drawer counted heads and said "1 rider confirmed" over a curator's
  answer. It reads `byCurator` off the snapshot now and says "A curator
  confirmed", or "{n} confirmed, one a curator" once riders stand behind it too
  (`d_confirmed_curator`, `d_confirmed_curator_many`).
- `ItemEvidenceResolver` read "verified with fewer rows than the threshold" as a
  curator's word. A recorded answer now settles `verifiedBy` outright; the count
  speaks only where no such row exists, which is what a pre-column row and an
  import-promoted row look like. The **rung** is unchanged either way, because
  rung 11 is a curator's word AND nothing else and `EvidenceRung` applies that
  test itself (data-provider-hierarchy.md §6.7.7).

**The rule does not read provenance.** An OpenStreetMap node, a national
register entry and a rider's own pin all start Unverified and all leave it the
same way: somebody stood there. `Version20260909210000` swept the rows that had
already collected the threshold so no rider's existing work was thrown away.

**It does not read type or region either. The threshold is one number, and it is
never scoped** (owner ruling 2026-09-09). `map.item_verify_threshold` is global:
not per item type, not per category, not per country, not per region. Per-region
scoping is the worse of the two, because it makes the same word mean different
things in different places, but neither is allowed. Three reasons, in the order
they cost.

1. **A shared dataset cannot afford two meanings of one word.** The whole value
   of publishing `verified` is that a consumer who has never met us can rank on
   it. If a Dutch tap earned it at 2 and a Spanish col at 4, nothing can compare
   the two, and the field stops carrying information at the exact moment
   somebody outside relies on it. That is the failure this project exists to
   fix, so it is not one to re-create in our own schema.

2. **It re-opens the hole that was closed on 2026-09-09, one layer down.** Until
   that ruling `verified` was derived three different ways in three files and
   they could disagree. A scoped threshold moves that same variation out of the
   queries and into the data, where it is harder to see: every row still reads
   `state = 'verified'`, but two rows holding it earned it under different bars,
   and no query can tell them apart.

3. **Every difference people will point at is real, and none of them is a
   threshold problem.** Each already has its own instrument, and reaching for
   the threshold instead answers a different question:

   - **Perishability.** A road closure goes stale in a week; a col does not.
     That is decay, and `web/src/Catalog/ConfirmationFreshness.php` carries it
     with a configurable `map.confirmation_stale_months`
     (system-configuration.md §2).
   - **Seasonality.** Dutch taps are shut off over winter; the same kind of tap
     in a warmer country runs all year. The place already answers that itself:
     B · Water carries a `seasonal` field with `Year-round`, `Summer only`,
     `Frost-shut in winter` and `Unknown`
     (`web/src/Catalog/CatalogFormRegistry.php`), and the confirmation window
     rolls rather than resetting on 1 January. A tap nobody can reach in
     January is not a tap two riders disagree about, so a cheaper Dutch bar
     would publish confidence where there is only an empty season, and it would
     do it in the one country whose register we already read.
   - **A region too thin for two riders to meet at one fountain.** That is a
     coverage problem, and the curator door above already answers it: one
     curator's word settles an item outright, precisely so a quiet region is not
     stuck behind a bar its population cannot clear.

   So a future change that appears to need a scoped threshold is a signal that
   the question belongs to decay, to a seasonal attribute, or to the curator
   door. Move it there rather than splitting this number.

**A confirmation outlives the row's custody.** A provider harvest may change an
item's attributes, its freshness and which tier keeps it
(data-provider-hierarchy.md §6.7.2), and it may never delete an
`item_confirmation` row. Custody moves both ways; evidence only accumulates. A
rider who confirmed a tap in May still confirmed it after a register publishes a
newer survey in September, the drawer says both (data-provider-hierarchy.md
§6.7.3), and the tally keeps counting the rider. Any harvest path that deletes
confirmations is a defect, not a cleanup.

`NotPotable` never promotes, and neither does `NotAsDescribed`: a warning is not
a vouching. Only `Potable` and `Exists` count towards the threshold
(`ItemConfirmationService::VOUCHING`). Form-sourced answers never count at all,
because a submitter is not a witness to their own submission. The POST response
carries `verified: true` on the transition so the map can flip the "?" badge in
place (`markItemVerified()`), instead of leaving a rider looking at a payload
fetched before their own confirmation. The same promotion runs from the wizard
when a curator ticks "Mark it confirmed" on their own applied edit (§1.6,
`CatalogContributionService::confirmNow()`): one confirmation path, two
buttons.

`ItemType::isConfirmable()` = "has stances". One stance **per rider per item**
(`item_confirmation`, UNIQUE `(item_id, user_id)`, tally index
`(item_id, stance)`), **switchable** — flipping potable ↔ not-potable updates
the row and never double-counts (`ItemConfirmationService::record()` rejects
stances the item's type does not offer).

### 10.1a A confirmation goes off, and the map says so (built 2026-08-16)

An item confirmed half a year ago is not the same claim as one confirmed last
week, and until this the map drew them identically (owner 2026-08-12). A
**stale item's pin gains an orange ring**, so a rider passing it can see,
without opening anything, that it is worth a look. **One confirmation resets
the clock** - the point is a nudge, not a chore.

**The failure this had to be designed against is not a wrong date.** It is
that six months after launch most of the map is orange, and an orange that
means "everything" means nothing. Three rules keep the signal narrow, and all
three live in `ConfirmationFreshness`:

1. **Never confirmed is not stale, it is UNVERIFIED.** An item nobody has
   stood next to already has its own state and its own signal (`v` absent in
   the payload, `stateUnverified` in the drawer). Ageing something that was
   never fresh would paint every harvested OSM row orange on day one.
2. **Only letters whose confirmations GO OFF**, which is a NEW and narrower
   predicate than §10.1's stance list: `ItemType::confirmationAges()`. Every
   place a rider can stand next to is *confirmable* - that is §10.1's point -
   but **a tap breaks, a shop shuts, a hazard clears; a viewpoint does not
   stop being a view.** So B, C, D, E, F, G, O and A age; N, P and Q never do.
   Reusing `confirmationStances()` here would have aged the whole map, because
   the 2026-08-12 decision deliberately widened it to nearly every letter.
3. **`source <> 'form'`**, as everywhere else: a submitter answering their own
   improve form ([§6.3](#63-the-submitters-own-answer--item_confirmationsource))
   is not somebody having checked, and letting it reset the clock would let a
   contributor keep their own pin fresh for ever without anyone visiting.

**Three bands, one dial.** `map.confirmation_stale_months` sits on
/admin/system-config beside the other editorial dials (default 6), because six
months is a guess about how fast the built world changes and a guess belongs
where it can be revised rather than redeployed. The middle band is half the
window - a six-month dial reads fresh for three months, ageing for three, then
stale - so `ageing` needs no second dial to explain it. Only **stale** gets the
ring: `ageing` is a state the drawer explains in words, and a second ring
colour would be one more thing to learn from a map that has to be readable at a
glance.

**The drawer's freshness line was dead code until now.** `drawer.js` has
rendered `f.freshness` since it was written and nothing ever produced it - the
map template says so in as many words ("`f.freshness` is produced by no server
path either"). `CatalogProvider::feature()` is that path; the drawer lit up
with no client change. The key is **absent** for every item rules 1 and 2
exclude, so those payloads stay byte-identical to what they served before.

**The rider's half.** The ring is a passive colour; `/profile` carries the half
a rider can act on - a short "worth a look near you" list, stale places inside
their own base-location radius, soonest-forgotten first. It is absent entirely
without a base location: there is no "near you" to answer, and a national list
of old taps is a chore, which is the thing this must never become. It applies
**the same three rules as the map** - two surfaces disagreeing about what stale
means would be worse than either being wrong - and cuts on a DATE
(`ConfirmationFreshness::staleBefore()`) rather than on a state computed per
row.

Related: `ClosureLifetime` is the same idea already built for one letter - a
closure retires itself, and a confirmation ages itself. Pinned by
`ConfirmationFreshnessTest`.

### 10.2 Endpoint contract (`ItemConfirmationController`)

- `GET /items/{id}/confirmations` — `PUBLIC_ACCESS` (explicit rule in
  `security.yaml`, for cacheability past the lazy 2FA firewall): public
  tallies keyed by the offered stances, `total`, `stanceKind`; the viewer's
  own `mine` stance and a CSRF token (**token id `item-confirm`**) only when
  authenticated.
- `POST /items/{id}/confirm` — requires `ROLE_USER` with a **clean 401**
  (never a login redirect) + CSRF (token id `item-confirm` — **not** in
  `stateless_token_ids`, so session-backed as built; the deviation and its
  resolution options are recorded in
  [security-architecture.md](security-architecture.md) §5.1/§8);
  unknown/unoffered stance → 422.
- Votable or unserved items **404** on both endpoints (served =
  `ItemState::SERVED`, serving contract:
  [catalog-data-model.md](catalog-data-model.md) §4, §9; mirrors the route
  community-loop API pattern).

### 10.3 Drawer surface

The generic vote CTA is gated to votable layers (`CC_VOTABLE`), the
confirmation panel to confirmable layers (`CC_CONFIRMABLE`) — both are layer
key sets in `map.js` mirroring `ItemType::isVotable()`/`isConfirmable()`. The
panel hydrates async on drawer-open; water shows both tallies, other utilities
a single confirm; anonymous viewers see the counts plus a "Log in to confirm"
prompt (counts public, recording gated).

### 10.4 Yes is not the only answer — condition reports

A confirmation says a place is right. Three more answers say it is not, and they
are **edits, not confirmations**: a claim about the place rather than a vote on
it, so they travel as ordinary submissions through the ordinary queue (owner
decision 2026-08-12).

| Button | Writes | Offered on |
|---|---|---|
| ⚠ Out of order | `condition = 'Out of order'` | B · water, C · toilets, D · services (`CC_BREAKABLE`) |
| ⌀ Closed | `condition = 'Closed'` | every confirmable type |
| ✕ Not there anymore | `condition = 'Not there anymore'` | every confirmable type |

`condition` is a registry field (`CatalogFormRegistry::CONDITION`) on every
confirmable point type, so the tap and the edit form write the same key with the
same vocabulary — a rider who wants to say more opens the form and finds their
own answer already chosen. It carries **no default**: a default would make every
untouched edit form assert "as mapped" about a place its editor never looked at,
and turn a no-op edit into a change the intake is meant to refuse.

Two endpoints, one gesture (`App\Controller\OsmConfirmController`):

- `POST /osm/confirm` — for an OSM place we do not hold yet. See
  [osm-data-architecture.md](osm-data-architecture.md) §6: it fills the letter's
  own form from the coverage row and files it, so a one-word answer no longer
  needs a form.
- `POST /items/{id}/condition` — for a place already ours. Materialising does
  not freeze a place: a water point added as existing and potable can be shut
  off, break, or be taken out next season. Files an `improve` submission with
  the same `condition` change; a second identical report answers `409
  pending_review`, because the map is not a tally of how many riders watched the
  same tap break.

Both take the `item-confirm` CSRF token, which the map page carries in its
**signed-in-riders** block — its absence is the auth signal the drawer reads to
show "Log in to confirm" rather than a button that cannot work.

A place reported `Not there anymore` leaves the map without handing itself back
to OSM: `CatalogProvider::itemRows()` stops drawing the item while
`curatedRefs()` still claims its ref. Both halves, or the report changes nothing
(first) or is undone a second later by the reference layer (second).

### 10.5 A nameless place can still be reported

`submitImprove()` titles a submission with the item's name and the draft
requires one — but most coverage-materialised POIs have none, a drinking-water
node in OSM being nothing but tags. Those reports died on a validation error the
rider could neither see nor fix. Callers that know a better word for the place
pass `_title_fallback` (the one-tap report passes the layer's own label). The
item is **not renamed** by it: the title is what the queue displays and nothing
else.

## 11. Curator applications — the two doors an empty map needs

Every country is empty at launch, so `/join/{cc}`
(`App\Controller\JoinCountryController`, `IS_AUTHENTICATED_FULLY`) is the one
page both signals funnel through: "I want it here" and "I'd curate it". One
route, two states, decided **server-side by whether the country already has
any `region` rows** — never by the client-submitted form — because a country
with no region has nowhere to anchor a submission and nothing to scope a
curator to.

The map rail's one-line invite (`#emptyScopeInvite`,
`web/assets/map/panels.js`) links here with the country pre-filled whenever
the active scope's curated count is zero. That count is a faithful reduction
of `featureVisible()`'s curated branch (render.js): only features on
experiential layers (`l.key === 'experience'` or `l.exp`) with `f.cur` set
count; utility layers (B/C/D/E/F/G) never carry a `cur` flag and can neither
suppress nor trigger the invite, so a stray hazard report or an unverified
route upload never silently hides it. This holds independent of the rider's
own view-mode toggle, and is computed once per map load.

### 11.1 `CountryInterest` — the demand signal

Entity `App\Community\Entity\CountryInterest`, table `country_interest`:

| column | type | notes |
|---|---|---|
| `id` | bigint identity | |
| `user_id` | bigint | no FK (house convention, §5.6) |
| `country_code` | varchar(2) | ISO 3166-1 alpha-2 |
| `willing_to_curate` | boolean, default false | the contact list for onboarding |
| `note` | varchar(280), nullable | hardened per §11.3 |
| `created_at` / `updated_at` | timestamp | |

`UNIQUE (user_id, country_code)`. `CountryInterestService::record()` upserts:
re-submitting for a country already on file updates `willing_to_curate`/`note`
and bumps `updated_at` rather than creating a second row.

### 11.2 `CuratorApplication` — the supply signal

Entity `App\Community\Entity\CuratorApplication`, table `curator_application`:

| column | type | notes |
|---|---|---|
| `id` | bigint identity | |
| `user_id` | bigint | no FK |
| `country_code` | varchar(2) | must already have `region` rows (`CuratorApplicationService::countryIsOnboarded()`) |
| `requested_region_id` | bigint, nullable | null = whole country; set = one division, validated to belong to `country_code` |
| `osm_username` | varchar(64), nullable | charset-checked before use (`OsmUserVerifier::isWellFormed()`) |
| `osm_verified_at` | timestamp, nullable | set only when the OSM lookup was *reachable* — null means unchecked or unreachable, never "checked and empty" |
| `osm_exists` | boolean, nullable — **tri-state** | null = never checked or OSM unreachable; true = handle found; false = checked and confirmed absent. Absence and unknown are deliberately two different values: collapsing them would let a fabricated handle render as verified on the review screen |
| `osm_changeset_count` | int, nullable | set only when `osm_exists = true` — a count is meaningless for a handle that doesn't exist |
| `about` | text | hardened per §11.3, cap 1200 chars — the only **required** form field |
| `social_url` | varchar(255), nullable | optional "where can we find you online" link. Scheme-less input gets `https://` prefixed (people paste `instagram.com/handle`); after that the scheme must be http(s) and the whole thing a `FILTER_VALIDATE_URL`-valid URL ≤ 255 chars, else `social_url_invalid`. The allow-listed scheme is the XSS boundary: the review screen renders it as a clickable `target="_blank" rel="noopener noreferrer nofollow"` link. Reviewer-only, never public |
| `status` | varchar(12), enum `CuratorApplicationStatus` | `pending` / `approved` / `declined` / `withdrawn` (`withdrawn` has no UI path yet) |
| `decided_by` / `decided_at` / `decision_note` | bigint / timestamp / text, nullable | mirrors `Submission`'s decision columns (§3.1) |
| `created_at` | timestamp | |

Partial unique index `uniq_curator_application_pending (user_id, country_code)
WHERE status = 'pending'` is the concurrency guard for "one pending
application per person per country" — `CuratorApplicationService::hasPending()`
is the friendly pre-check (a clean error before a flush); the index is the
invariant that holds under a race, since Doctrine's ORM mapping cannot express
a partial index.

**Evidence lives in the submission table, not on this row.**
`CuratorApplicationService::evidenceFor()` counts the applicant's submissions
by `user_id` + `country_code` **at review time** (`total`, `approved`
`WHERE status = 'approved'`), so the review screen always reflects current
standing — filing more submissions between applying and being reviewed changes
what the reviewer sees. Nothing is copied or snapshotted onto
`CuratorApplication` at submit. The form's `join.evidence_hint` copy
deliberately does NOT state this mechanic:
telling applicants their map edits "speak for them" read as a contribution
prerequisite, which decision 3 explicitly rejects. The copy now leads with
"you don't need to have added anything before applying", invites motivation
and prior experience (the `join.about_label` question), and only mentions
that existing contributions are gladly looked at. The reviewer-side
evidence pane is unchanged.

**The sending is acknowledged, not just the deciding.** Submitting sends the
applicant a dashboard message (`UserMessageKind::CuratorApplicationReceived`,
`join.message.received`), which `MessageMailer` then delivers to their inbox
with a link back to it. Approve and decline had always notified; submitting
told the rider nothing beyond a flash they lose on the next click
(owner-reported 2026-08-14). That is the wrong silence — a volunteer has just
handed over their name and their reasons, and review is a human step with no
promised time on it. Three details that are easy to get wrong:

- It gets **its own kind**, not `CuratorMessage`: the email subject is derived
  from the kind, and "Message from a curator" is not what an automatic receipt
  is. A new kind must also be shelved in `MessageCategory::kinds()` or it
  appears on no dashboard shelf.
- The message needs an **explicit flush**. The decision paths get one free from
  their `wrapInTransaction`; here the application's own flush has already
  happened, so without it the row is persisted and never written, and the
  mailer has nothing to deliver.
- All three messages interpolate `%scope%` — the **requested region's name**
  when there is one, else the country's — resolved by
  `CuratorApplicationService::scopeLabel()`. They used to interpolate the raw
  `country_code`, so a rider who volunteered for North Holland was told their
  application "to curate NL" had arrived: the wrong scope, and a database code
  rather than a place.

**The applications desk is a list that opens.** Every application used to
render expanded, so a reviewer scrolled past everything to reach the one they
meant, and it read `pending()` - a decided application simply vanished, leaving
nowhere to see what had been answered. It now lists **every** application,
newest first, one row each carrying the name, the scope and an
**Open / Accepted / Declined** pill, with the detail behind a `<details>`
disclosure (keyboard- and screen-reader-native, survives the CSP without a
script, and opened by find-in-page). A decided row shows the record - status,
when, the note - instead of the controls, because `approve()` guards on
`Pending` and offering buttons that can only error is worse than offering none.

**Two buttons fill the note; nothing sends until a decision is pressed.** The
note is a textarea, not a single line, because the approval carries a welcome
and the rulebook link. The templates are **English literals in the template, not
catalogue keys**: a translated default resolves in the *reviewer's* locale, so a
Dutch reviewer would post Dutch to a Spanish rider and call it localisation. The
reviewer edits freely before sending, and line breaks survive - the note is
plain text where it is typed, converted only at render (`nl2br` in the email,
`white-space: pre-line` on the messages page).

**A change of areas is told to the person it happens to.** A moderator's scope
decides what they can see and act on, so a silent change means finding out by
noticing a desk has gone quiet, or that somewhere new has appeared in it with no
explanation (owner 2026-08-14). `UserAdminService::setModeratorAreas()` compares
a sorted fingerprint of the areas before and after, and on a real change sends
`UserMessageKind::ModeratorAreasChanged` — a dashboard row that `MessageMailer`
then delivers by email, so one send covers both surfaces. Re-saving the same set
(or the same set in another order) says nothing. Sent AFTER the transaction: the
notice is not part of the assignment, and a mail-layer problem must never roll
back a scope change an admin has already made.

The message names the areas in **words**, resolved at send time, and an empty
assignment reads *everywhere* rather than "nothing" — empty means global, which
is the opposite. It is **English in every catalogue**, like the curator
decision templates: internal communication with moderators has one working
language, and a translated string would resolve in the *admin's* locale at the
moment of saving, so a Dutch admin would write a Dutch word into a message an
English or Spanish moderator then reads.

**`?by=<user id>` filters both desks** - the open queue and the settled
history - so "what has this person sent, and what of theirs is still waiting"
is answerable from one link. The queue says when it is filtered and offers a
way back to everyone; a desk silently showing four items when the real queue
holds twenty-four would be worse than no filter at all.

**Every history row names the item's TYPE.** The title alone hides what a row
is about: "Schellinkhouterdijk" says nothing, while an unnamed item reads
"Water & food" and was clear for free - so the type was visible only on the
rows that did not need it. `SubmissionQueue::history()` resolves it from
`submission.letter` via `ItemType::fromLetter()`, and the template omits it
when it would merely repeat the title.

**The desk links to the person's record.** The evidence line answers *how many*
submissions they have had approved; `?by=<user id>` on `/moderate/history`
answers *which ones*, which is the question a reviewer actually has in front of
an application. Filtered on `submission.user_id` by id, never by display name -
names stopped being unique on 2026-07-31. Trash rows are suppressed under this
filter for the same reason they are suppressed under a title search: the
`trash_submission` audit is content-free by design (§6) and records no
submitter, so merging it in would put strangers' trashed rows under "see their
submissions" while somebody is deciding whether to trust that person. The link
sits in the opened body, not on the name in the summary - a link inside
`<summary>` fights the disclosure for the same click.

**A pending application replaces the form.** `/join/{cc}` reads
`pendingApplication()` on every render, not off the success flash, and shows
the state instead of the form. It renders **`join.message.received` itself** —
the same key the email carries — rather than page-specific copy saying the same
thing: two wordings for one fact is how a page and an inbox start disagreeing,
and stacked under a heading that had already said it, the screen told the rider
three times that their application had arrived. For the same reason the success
flash is suppressed while that block shows (drained either way, so it cannot
reappear later). That answers the rider who returns
tomorrow as well as the one who just pressed the button: a second pending
application is refused anyway, so handing somebody a form that cannot be sent
is a worse answer than telling them theirs is already in.

**Applicant-facing status.** The profile dashboard's
Contributions pane closes with a **Curator applications** section
(`ProfileController`, [account-and-auth.md](account-and-auth.md) dashboard
section): the user's own applications with status pills
(pending/approved/declined/withdrawn), requested scope (region label or
"whole country") and the reviewer's `decision_note`; when none exist, a door
to the regions directory. Combined with the login target-path continuity
([account-and-auth.md](account-and-auth.md) §2 — an anonymous `/join/{cc}`
visit survives the register→verify→login detour), "where did my application
go?" always has an answer: either the form was reached and the profile shows
the row, or it never was and the profile shows the door.

### 11.3 Free-text hardening — `PublicNoteFilter`

Both `CountryInterest.note` (cap `PublicNoteFilter::MAX_NOTE` = 280) and
`CuratorApplication.about` (cap `MAX_ABOUT` = 1200) pass through
`App\Community\PublicNoteFilter::clean()` before storage. Neither field is
ever rendered on a public page — reviewer-only, escaped on render.
`clean()`, in order:

1. Unicode-normalises (NFC) so composed/decomposed duplicates cannot evade
   uniqueness.
2. Strips zero-width and bidirectional-override characters — the legacy
   embedding/override block (`\x{202A}`–`\x{202E}`) and the modern isolate
   controls (`\x{2066}`–`\x{2069}`), closing the Trojan-Source spoofing class.
3. Collapses whitespace runs and trims.
4. Rejects (`InvalidNoteException`) any remaining control character.
5. Rejects links: `scheme://…`, `mailto:` only when followed by an
   `@`-payload, `tel:` only when followed by `+`/digits, bare `www.`, and bare
   `domain.tld` — narrow enough that ordinary prose like "News:local closed"
   or "word:word" survives, because the pattern requires the shape of an
   actual link, not just a colon.
6. Rejects a length overflow **after** cleaning, not before — so a note that
   was invisible characters only is correctly judged empty/too-short by what
   survives, not by its raw length.

### 11.4 Review surface — `/admin/curator-applications`

A purpose-built `#[AdminRoute]` page
(`Admin\DashboardController::curatorApplications()`,
`admin/curator_applications.html.twig`), `ROLE_ADMIN`, following the
system-configuration.md precedent rather than an EasyAdmin CRUD: the reviewer
needs a person, their track record, their OSM standing and their words side by
side for one judgement, not sortable rows.

Per pending application: display name/email, email-verified badge, account
age, the evidence counts (§11.2), the requested scope (region name or "whole
country"), the OSM line (unverified / `<n> changesets` when found / "not found
on OSM" when `osm_exists === false` — the tri-state rendered faithfully, so a
fabricated handle never shows as verified), and the `about` text (escaped).
One decision form per row: `approve` or `decline`, an optional note, CSRF
token id `curator-applications` (session-backed — [security-architecture.md](security-architecture.md)
§5.3).

### 11.5 Approve/decline lifecycle

`CuratorApplicationService::approve()`/`decline()` are the only write paths,
each gated by an `assertPending()` guard — a `\DomainException` on an
already-decided application. The same check runs twice: the service enforces
it regardless of caller, and the controller checks status before dispatching
too, so a stale second tab or a double-submit flashes
`admin.curator.already_decided` rather than reprocessing.

`approve()` is **one transaction, all or nothing**
(`EntityManagerInterface::wrapInTransaction()`):

1. `UserAdminService::grantCurator()` — adds `ROLE_CURATOR`, and writes its
   own `grant_curator` audit row. Double audit granularity is intended: the
   role grant and the application decision are two separately meaningful
   audit facts, not one collapsed into the other.
2. A `ModeratorArea` is persisted via the ORM (not raw SQL), scoped to
   `requested_region_id` when set, or to `country_code` when the request was
   for the whole country — matching `ModeratorArea`'s CHECK constraint of
   exactly one of the two (§9.1). Flushed **inside** the still-open
   transaction, not deferred to commit, so a `uniq_moderator_area` collision —
   a second approval racing this one — rolls back the role grant with it,
   rather than leaving the applicant holding `ROLE_CURATOR` with the
   application stuck `pending` forever.
3. The application flips to `approved`, stamping `decided_by`/`decided_at`/
   `decision_note`.
4. An `AdminActionLogger` row (`curator_application.approve`).
5. A `join.message.approved` system message.

`decline()` is transactional over steps 3–5 only (no role/area writes):
status flip, an `AdminActionLogger` row (`curator_application.decline`), and a
`join.message.declined` message.

Both messages go through `MessageService::sendSystem()` inside the
transaction — the house pattern also used by `ModerationService::decide()`
(§7.2) — on **channel `curator_app`**: the natural `curator_application` name
overflows `user_message.channel`'s `varchar(12)`, so the abbreviated form is
the value actually stored.

**Approval scopes rather than promotes**: a Dutch applicant's `ModeratorArea`
is the Netherlands, never global curator. Granting stays admin-only
([account-and-auth.md](account-and-auth.md) §6.1), designed to extend to
trusted moderators later, not built.

## 12. Specified, pending implementation

- **M8 phase-2 scheduler** (§8) — the units are written in `operations.md` §1
  and not installed. (**M7 email delivery is built** — §7.8.)
- **M12 account lock/ban** — separate admin-desk work; recorded, unbuilt.
- **Hazard intake UI** — `SubmissionType::Hazard` is queue-renderable but has
  no intake flow; `ModerationService` refuses to approve it until an apply path
  exists. (Photo intake is **built** — see below.)
- **Pending route proposals on the curator map** — routes are decided on the
  detail page only (§5.1); serving them onto the map like item submissions is
  an accepted follow-up.
- **Materialize-on-edit** (osm-data-architecture.md §6) — this pipeline is the
  designated machinery; the coverage-side trigger is not yet built
  ([coverage-provider.md](coverage-provider.md)).

## Scout intake — a ride reviewed at home, one tag at a time

**Status: built 2026-08-12** (`/scout/review`, `ScoutIntakeController`,
`web/assets/map/scout-review.js`). Consolidated from
`Dated/2026-08-09-scout-cc-tagger-plan.md` tasks 1, 5, 7 and 8.

**FIT is the format.** Scout's own reader is vendored verbatim at
`web/assets/lib/scout-fit.js` (MIT, same owner as this project), copied between
the source file's own `===PARSER-START===` / `===PARSER-END===` markers —
markers that exist precisely because Scout's node harness extracts the same
span. Keeping it byte-identical makes a re-sync a copy rather than a merge, so
two implementations of a binary format cannot drift into disagreeing about
somebody's ride. Do not edit it here; fix it in Scout and re-copy.

What that buys over GPX is everything structured: the tag type, its sub-type
(which resupply, which surface), the moment it was dropped, Scout's own undo
rule for a double-tapped tile, and the surface stretches a start/END pair
describes. A surface tag therefore arrives carrying the OSM value the rider
chose on the device, and `SurfaceVocabulary::fromOsmValue()` turns it into the
declarable label so nobody picks the same thing twice. **FIT is the only format read**, and deliberately so: no Scout app writes GPX
(owner, 2026-08-12), so a GPX reader would be a path no real ride can take — and
one that quietly produced weaker tags, since `<wpt>` names carry no sub-type and
no surface value. A ride routed through it would arrive stripped of half of what
the rider recorded, with nothing to say so. Anything that is not `.fit` is
named as such rather than attempted and failed deep inside a binary parser.

**The ride never reaches the server.** It is read in the rider's own browser
with `FileReader`, drawn from memory, and what crosses the network is a single
tag the rider has approved. There is no upload, no server-side draft, nothing to
expire and nothing to delete. That is a property of the code, not a policy:
`ScoutIntakeController` **refuses** a payload carrying `track`, `polyline`,
`records`, `coordinates`, `gpx`, `fit` and the rest with a 422, rather than
ignoring the extra field — ignoring is how a trace starts arriving and nobody
notices for a year. Four of those keys are covered by tests.

The one deliberate exception is **`segment`** (task 6 below): the
rider-approved excerpt of ridden line between a surface stretch's two taps —
the same line they would draw in the map wizard, coordinates only (no
timestamps), accepted only for letter A and only when the rider presses send on
that stretch's card. The stretch is road data the rider chose to publish; the
timings stay movement data and never leave the browser.

**Surface stretches are A submissions (plan task 6, built 2026-08-18).** A
surface tag is a *transition*: a start type opens a stretch, END (or the next
transition, or the end of the ride) closes it. The review panel turns each
pair into its own card and its own line on the map, drawn in the **same
palette the map's surface legend uses** (`render.js` `SURFACE_STYLE` via
`scout-segments.js` `DEVICE_CLASS`) — a gravel stretch draws ochre in review
because it will draw ochre on the map once approved. Points and stretches
share ONE chronological numbering (a stretch sits at its start tap), and a
stretch wears its number on BOTH ends — a green circle where it starts, a red
one where it ends (owner, 2026-08-18) — so "4" on the map reads as "stretch 4
runs from here to here" and matches card 4 in the list. Both circles **drag,
along the ride only** (owner, same day): a tap is where the thumb reached the
bars, not always where the surface changed, so dragging an end snaps it to
the nearest track point and re-cuts the line from the ride (`sliceTrack`) —
start can never pass end, and a sent stretch locks. Deliberately the opposite
of point pins (which drag free): a stretch endpoint IS a place on the ridden
road. The card is titled
"Surface" and is fixed to A
(a stretch cannot be re-filed onto a point letter, and surface *transitions*
no longer appear as bogus point cards defaulting to Water & food —
owner-reported 2026-08-18); its surface dropdown is the A form's own
vocabulary, preselected from the device's OSM value, and an explicit choice
outranks the device at intake. Sending posts the ordinary tag wire plus
`segment` = `{a, b, line}` in `[lng, lat]` pairs, cut from the ride strictly
between the taps (`scout-segments.js` `cutTrack`, tap points win the
endpoints, ≤ 3000 points) and re-validated by the same
`CatalogContributionService::decodeSegment` the map wizard uses (endpoint
drift ≤ 1 km). A stretch with **no END tap** runs to the next transition or
the ride's end and its card says so, telling the rider to check the line
before sending — the plan's "ask in the viewer", answered by showing rather
than prompting. The raw OSM value (`surface=gravel`) is kept **verbatim in
the submission payload** beside the declarable label, because this data is
meant to be fit to hand back to OSM one day (owner, 2026-08-18) and the OSM
spelling is the OSM-side fact. A surface tap with no stretch is still refused
as an A point (`segment_required`), and a point letter carrying a `segment`
is refused outright. Tests: `ScoutIntakeTest` (stretch → A submission with
segment + surface, choice outranks device, refusals both ways) and
`tests/js/scout-segments.test.mjs` (cutting, endpoints, cap, no timestamps).

**It runs on the real map**, not a stripped editor map (owner, 2026-08-12): a
rider fixing a tag needs to see what is already mapped around it — the water
point twenty metres away that makes theirs a duplicate, the surface line they
are about to contradict. That judgement is the task.

**Tags are red until they are resolved.** Red is the one colour the map does not
use for a place, so a rider scanning a ride can find what still needs them; a
sent tag turns green. Dragging a tag is **free** (owner 2026-08-18, reversing
the 2026-08-12 clamp): the ride is where the rider *was*, not where the thing
*is* — a castle tagged from the road stands beside it, and the clamp made the
correct position unreachable. The rider reviewing their own ride is the
authority on where it belongs.

**One tag, one request, one decision.** No batch verb: a ride is thirty separate
judgements by the rider, and a bulk call makes partial failure unreportable
("nine of thirty went in — which nine?"). And one tag, ONE submission: the
in-flight/sent guard lives on the entry itself, not the button (owner
2026-08-18) — the server mints a fresh submission for every POST, so *Send the
rest* racing a still-in-flight single send would otherwise post a duplicate a
curator has to reject. Every tag becomes an ordinary
submission in the ordinary queue, and nothing about the moderation side changes
— *one way to moderate* binds here too.

**Provenance is `scout`**, its own `ItemSource`. It says HOW a contribution
arrived and deliberately not that anything was checked: the server never saw the
ride file and cannot verify a thing about it. A Scout tag is worth exactly one
ordinary submission, and a Scout ride-claim exactly one *I rode this*.

**The tag vocabulary is stated once.** `App\Scout\ScoutTag::LETTERS` maps each
of Scout's six tag types to the catalog letters it may become, the review panel
offers exactly that list, and the endpoint validates against the same constant —
so the panel can never present a choice the server refuses. `resupply` may be
water or a bike service. `other` is the exception since 2026-08-18 (owner):
it carries no category on the device and asks for none in review — its card is
a free-text description only, and the intake auto-files it as an **E notice**
with `hazardType: Other`, the curator's read of the text being the filing
decision. An empty description refuses to send: "Other" as a name tells the
curator nothing. A **bare surface tap** (the device's type picker timed out:
"surface, here", no type, no stretch) is **hidden from the review and counted
in the panel's fact lines** (owner 2026-08-18: "if it times out just hide
it") — it cannot become an A point, and its old fallback dropdown defaulted
to Water & food inside a card titled Surface, which read as a bug because it
was one. Counted, never silent: a rider who counts thirteen taps and sees
twelve cards deserves the difference named.

**The review screen shows everything at once** (2026-08-12). Rows are open, not
collapsed: a rider came to check that thirteen tags are the right thirteen
things, and hiding that behind thirteen clicks defeats the screen. Each row
carries its letter and one compact control row — the name field with a camera
and a send icon button at its right (owner 2026-08-18; the words live in the
buttons' title/aria-label, and the send icon walks send → sending → sent as a
plane, a dimmed plane, a check); **Send the rest** clears whatever is still
outstanding, in order.

- **A tag can be waved away** (owner 2026-08-18): a ✕ on each card removes
  it from THIS review — card, pin(s), and a stretch's line — and nothing
  else. Nothing is sent and nothing is deleted anywhere: the tag lives in the
  rider's own ride file, so opening the file again brings it back. Dismissed
  tags leave the counts and the close-confirm's "unsent" number. Cards are
  addressed by their ride-order number (`data-n`), never by list position —
  dismissals and interleaved stretch cards make positions lie, which is also
  why *Send the rest* walks the shared order.
- **Dragging a pin needs no save.** The position updates the moment the drag
  ends and travels with that tag's own Approve — there is no separate commit,
  and no state that can be left behind. Dragging is free (see above).
- **An unnamed spot is still sendable.** A blank name becomes the tag's own kind
  ("Scenery", "Surface · gravel") rather than blocking the send or writing
  "Untitled" — the type already says more than a placeholder can, and the rider
  can type over it.
- **Photos attach per tag, several per spot.** That is the point for a camera
  that writes no GPS: the tag knows where it was even when the picture does not.
  One uploader for the panel, not one per row — `media-upload.js` owns the
  licence consent gate, and consent must fail closed in a single place
  ([photo-uploads.md](photo-uploads.md)); every id not yet attributed belongs to
  the row that asked last, which is how one queue serves many spots. Each chip
  is stamped with its tag's number, because a filename says nothing about which
  of thirty places it belongs to. Ids travel as `mediaIds` — a **JSON array**,
  the shape `MediaClaimService` parses — and are claimed in the same transaction
  as the facts. The panel's queue cap is 30 (a whole ride); the per-submission
  cap is unchanged.
- **The sub-menu decides the letter and fills the fields.** Scout's second tap
  is the half that says what the rider meant, and it does not always land where
  the tag type alone would put it. **One pick, one home** (owner 2026-09-07):
  every SCENERY pick lands on exactly one letter with `type` already answered.
  VIEW is P · Viewpoint / high point, NATURE is P · Natural feature, HISTORY is
  Q · Heritage site, CULTURE is Q · Museum / culture, ARCHITECT is Q ·
  Architecture; UNKNOWN offers P then Q and fills nothing. The two Type lists
  hold only these values plus Q's Monument and Religious site, so Monument
  and Heritage site no longer sit on both letters. NOTICE · POTHOLES arrives
  as E with `hazardType` already answered; CLOSURE · WEEKS as E with
  `closedFor`, which is what lets the map retire it by itself.
  `ScoutTag::DETAIL_LETTERS` and `DETAIL_FIELDS` own both tables and the panel
  reads them from the server, so it cannot offer a letter the endpoint refuses.
  A sub-menu answer never follows a tag the rider re-filed onto another letter
  (the field would not exist there): `ScoutIntakeController` fills the fields
  only when the letter is the pick's first offer. Pinned by
  `ScoutIntakeTest::testEveryScenerySubmenuPickHasOneHomeAndItsTypeExistsThere`.
  Vocabulary rename `Version20260907210000`: Nature reserve to Natural feature
  on P, Museum to Museum / culture on Q, in items and undecided payloads.
- **`hazardType` gained Potholes, Junction / crossing and Bad corner**, because
  the device offers them and every notice tapped on the bars was otherwise
  flattened to "Other".
- **The panel closes, and closing ends the review** (owner 2026-08-12). The card
  covers the top-left corner of the map, which is exactly where a tag often is.
  A ✕ in its own corner — sticky, so it does not scroll away behind a long list
  — hides the card *and* clears the ride: the route line, the numbered pins and
  the overtake markers all go, and the file input is reset so the same ride can
  be opened again. A first attempt left a "Review a ride" chip behind, which
  still occupied the corner and read as not-closed. Nothing is stranded by it:
  the tags live in the **rider's own ride file**, which we never had a copy of,
  so opening it again another day brings back everything not yet sent — and the
  confirm dialog says so, naming how many are outstanding. Reading a new ride
  clears first, so two rides can never be drawn over each other.
- **The Scout mark is a fixed small square beside the title** (owner
  2026-08-18: half the old mark, and the lead paragraph is gone — it explained
  what the panel already shows, and the privacy promise lives on `/scout`
  where a first-time rider actually reads it). This replaced the
  measured-width mechanism the taller mark needed; the ResizeObserver went
  with it.
- **The category dropdown is opaque.** A translucent control mixed with the
  system white behind the native popup and rendered pale cream on pale grey;
  both the closed control and its `option`s now state their own colours.

**The cars that passed you land on the map** (owner 2026-08-12). "15 vehicles
passed you on this ride" was true and impossible to act on; a radar reading only
means something as a place — this corner, that bridge. Every counted pass is a
small car marker at its own coordinates, carrying **ground speed** (the closing
speed plus the rider's own), because that is what the car was doing rather than
the difference between it and the rider.

- No tooltip. Closing speed and nearest range are the radar's working, not the
  fact a rider wants off a map — and hover does not exist on the bike computer
  the reading came from.
- A pass with no usable speed shows **`?`** rather than disappearing: it still
  happened, at a place the rider can point at. The unit goes with the number —
  "? km/h" reads like a measurement that failed to render, and this one was
  never taken.
- Passes with no GPS fix cannot be placed and are **counted in the panel**, so a
  rider who sees nine cars under a line reading fifteen is told why.
- Speed follows the rider's **distance** preference
  ([account-and-auth.md](account-and-auth.md) §9); there is deliberately no
  separate speed setting.
- Still **measured, never sent**: nothing on the server can hold a measurement
  yet, and showing it while saying so is honest.

**Curator-facing naming follows the rider's own choice** (2026-08-12). The desk
is pseudonymous by default — a decision should turn on the contribution, not on
who sent it — but `public_profile` is an explicit opt-in that already puts a
name on the contributors wall and on `/riders/{uuid}`, so hiding it from the one
person who has to read the work was inconsistent rather than protective. A
private account still shows `rider#<hash>`, which is where the protection
matters. Public change history is unchanged.

**A curator can open a pending item in the wizard.** `/improve?item=` binds only
publicly-served states for everyone else, and the pending drawer was offering an
"Edit this item" link that could never resolve. A curator is already reading
that submission on the desk, so nothing is exposed that they cannot see — and
the alternative was bouncing a typo back to the rider as a needs-info.

### A curator's confirmation verifies the item (2026-08-12)

Repetition by strangers is the only real check this project has — a photo can be
generated, a place invented — which is why several unrelated riders mean
something and one does not.

It is the wrong instrument for a castle. Some entries a curator settles by
looking (a listed monument, a station, a fountain in a town square), and making
them wait for three riders to pass by is ceremony rather than verification
(owner 2026-08-12). So a curator's own confirmation moves the item from
`unverified` to `verified` in one press, recorded in the item's change history as
an act of theirs rather than happening quietly.

**Not a new moderation mechanic**, deliberately: it is the same confirm control
every rider uses, weighted by who pressed it. Nothing queues, nothing is
approved, and a curator who is wrong is corrected like anything else. Three
guards, each of which would otherwise be a quiet way to launder a claim:

- a `form`-sourced answer never verifies, even from a curator — a submitter is
  not a witness to their own submission;
- a **warning** never verifies (`not_potable` says the water is bad, not that the
  entry is good);
- an already-verified item is not re-promoted, so the history carries one row.

Note what this exposed: **items never became `verified` from rider
confirmations at all.** Only routes have a threshold (`route.ride_verify_threshold`).
The tally is shown in the drawer and promotes nothing — a gap worth its own
decision, not one to settle inside a curator shortcut.

### Confirming widened, and reached OSM places (2026-08-12)

**A place that can be gone can be confirmed** — not only utilities. A viewpoint
gets built out, a monument fenced off, a gîte closed, and the rider standing
there is the only person who knows (owner-reported: a second rider could do
nothing at a viewpoint). So `ScenicViews`, `HistoryCulture` and `WhereToSleep`
gained the `Exists` stance, and are now **both votable and confirmable** —
voting ranks a region's best, confirming says the place is still there, and a
letter can want both. Climbs stay vote-only: a mountain does not go anywhere.
`CC_CONFIRMABLE` in the map mirrors it, and had also been missing `toilets`,
which the server had allowed all along.

**An OSM place can be confirmed too, through the door that already exists.**
Confirmation is keyed on an item, and an OSM pool point is not one — so the
drawer said "confirm on the spot" with nothing to press. It now offers *Confirm
it's here*, which is the same bridge the surface tiles use: the improve wizard,
prefilled, opening on the details because agreeing includes agreeing with where
it is. Submitting mints our item carrying the OSM ref, and from then on the
ordinary one-tap panel applies. No second confirmation store keyed on a ref, and
no new moderation mechanic.

### A moved pin is a change (2026-08-12)

It used to count only towards *"did anything change"* and was then thrown away:
the submission was filed at the item's OLD point, the diff never named the move,
and approving it moved nothing. The rider did the work, the wizard accepted it,
and the system dropped it silently — the worst of the three outcomes.

A move now travels as `Item::LOCATION_FIELD` ('location'), a pseudo-field like
`name`: it is not an attribute, because the position lives in `geom`, but an
edit has to be able to carry it. It appears in the desk diff as a coordinate
pair, is applied by `ModerationService::applyEdit` on approve, and lands in the
item's change history like any other field. The submission itself is filed **at
the proposed point**, not the current one — the desk pins submissions on a map,
and a curator judging a move has to see where it is being moved TO.

Moving nothing is still nothing: an edit whose pin has not moved, with no other
change, is refused as before.

**And it is DRAWN, not printed.** "52.62142, 5.13569 → 52.62117, 5.13448" tells
a curator that something moved and nothing about whether it moved to the right
place (owner 2026-08-12), so `location` joins the shape fields: it leaves the
textual diff entirely and feeds the same before/after switch a redrawn climb
uses. Two rings on the map — dashed grey for where it was, violet for where it
is proposed — with the map framed on the pair, because a curator flipping
between two off-screen points learns nothing. A ring rather than a pin so the
item's own marker stays visible underneath: the comparison is the question.

**The desk's confirmation gates changed** (owner 2026-08-12). Trash no longer
asks the curator to type DELETE or to tick a box — opening the panel and
pressing Trash inside it are already two deliberate acts on a control that is
one icon among five, and a word typed fifty times is a reflex rather than a
check. The server-side re-check went with it, on both desks, rather than being
left as a hidden constant that always passes. What protects the row is what
always did: a valid CSRF token, a POST, and the curator role. Escalation keeps
its tick-box — it alerts an administrator and cannot be undone by anyone — and
both panels gained a Cancel, because a panel that says "nothing can delete it
afterwards" is not one to feel trapped in.

### What a Scout ride carries that intake cannot yet take (2026-08-12)

Stated concretely, because "mostly works" is how a gap survives a release:

| From the ride | Today |
|---|---|
| **SURFACE tags (any sub-menu)** | **Refused.** A is segment-located and this endpoint carries one tapped point; an item minted from it gets Point geometry, which `CatalogProvider::surfaceSegments()` skips by design — it would succeed, say so, and never appear. A is not offered and the endpoint returns 422. The panel counts the stretches the parser found and says to add them from the map. |
| **Start/END stretch pairing** | Computed by the vendored parser (`buildSurfaceSegments`), displayed as a count, **not submitted**. This is plan task 6 and the only thing standing between a Scout surface tag and a real A segment. |
| **Overtake counts** | Read and **shown to the rider** — their ride, their number — and sent nowhere. There is no table to hold them: measurements live in their own store behind a five-rider gate, moderator-only (plan §2 / D2), and none of that is built. |
| **Tags with no GPS fix** | Counted and named in the panel. They used to be dropped silently, which meant a rider who tapped thirteen times and saw eleven rows had no way to learn why. |
| **An unterminated stretch** | The parser closes it at the ride's end and flags it; nothing reads the flag yet. |
| **A stray END with no start** | Ignored by the parser, as on the device. |
| **The observation date** | Travels in the submission's raw payload, which the moderator reads. Not yet a first-class attribute (plan task 6a). |

Everything else — NOTICE, CLOSURE, SCENERY, RESUPPLY, OTHER, with or without a
sub-menu value — goes through the ordinary intake, with the sub-menu deciding
the letter offered first and filling the fields it answers.

**Open:** the observation date rides in the submission's raw payload (which the
moderator reads) rather than as a first-class attribute — making it one is task
6a of the plan and a registry change of its own. And approving every tag by hand
is the right default for a stranger and the wrong one for a rider with two
hundred approved submissions behind them; contributor standing is in
`docs/TODO.md`.

## 13. The curator room, the in-desk board

**Status: specified 2026-08-26 (owner), building on `feat/curator-room`.**

### 13.1 Why it exists

The rulebook (§5) tells curators to ask before acting, and forbids taking
pending content anywhere outside the moderation desk. Both sentences need a
place to point at. An external chat link (an environment variable naming a
Signal/Matrix/Discord room) was considered and **rejected by the owner** on
2026-08-26, for exactly the reason the rulebook gives: a chat app is outside
the desk, and unapproved material would be pasted into it as screenshots.
Do not re-propose it.

The room is that place: an in-desk board at `/moderate/room`, readable and
writable by curators only, sharing the desk's session, its 2FA gate and its
CSP. Nothing leaves the application.

### 13.2 What it is not

Named here so the next reader does not build them by accident: no push, no
email, no threads, no replies-to-a-reply, no reactions, no attachments, no
presence, no typing indicators, no JavaScript. The desk already has a
notification channel for riders (§7), and the room deliberately does not reuse
it for curators. A room that emails is a room people answer from their phone,
and the rulebook's whole point is that this conversation stays at the desk.

### 13.3 Model: a board, not an inbox

The room does **not** reuse `user_message` (§7). That table is a per-recipient
inbox row, `user_id` plus `read_at`, one row per person. A post addressed to
every curator would be one row per curator, a pinned post would have to be
edited in each copy, and the room's counts would collide with the inbox's
unread bulb. The room stores one row per post instead.

`curator_post`:

| column | type | meaning |
| --- | --- | --- |
| `id` | bigint | |
| `author_id` | bigint, null | who wrote it. `ON DELETE SET NULL`, so the room's record of a decision survives the author's account and renders as a former curator. |
| `category` | varchar(32), null | a `CuratorRoomCategory` value, or `null` for the root. |
| `recipient_id` | bigint, null | `null` means addressed to every curator. Non-null makes it a direct message. `ON DELETE CASCADE`: a direct message to a deleted account has no second reader. |
| `pin` | varchar(8) | `none`, `category` or `room`. See §13.5. |
| `body` | text | trimmed, 1 to 2000 characters, the same bound `MessageService` applies to a curator note. |
| `about_submission_id` | bigint, null | optional link to the queue card the post is about. `ON DELETE SET NULL`. |
| `created_at` | timestamptz | |
| `edited_at` | timestamptz, null | set when the author rewrites the body. |

Indexes: `(pin, created_at DESC)` for the board read, `(recipient_id,
created_at DESC)` for the Direct view and the badge.

`curator_room_visit`: `user_id` (primary key, `ON DELETE CASCADE`) and
`last_seen_at`. One row per curator, written when the room is opened.

### 13.4 Categories, a fixed list in code

`App\Messaging\CuratorRoomCategory`, a backed enum with translated labels, in
the shape of `MessageCategory` (§7.9). Curators do not create categories: a
group this size does not need an admin screen, a merge story or an
empty-category rule.

| value | label key | for |
| --- | --- | --- |
| `general` | `room.cat.general` | anything that does not fit below |
| `ask` | `room.cat.ask` | asking before acting, the rulebook's own case |
| `escalations` | `room.cat.escalations` | hard cases and their aftermath, including the rulebook's line about what sits with you afterwards |
| `rules` | `room.cat.rules` | how the rules are read, and where they are unclear |
| `tools` | `room.cat.tools` | the desk misbehaving, which is a bug to report and not a rule to bend |

Two views are **not** categories and have no stored value: **All** (every post
the reader may see) and **Direct** (posts where the reader is sender or
recipient). A post whose `category` is `null` belongs to the root: it appears
in All, and when pinned to the room it appears at the top of every category.

### 13.5 Pinning

`pin` has three values:

- `none`: an ordinary post, ordered by `created_at DESC`.
- `category`: pinned to the top of its own category view only.
- `room`: pinned to the top of every view, category views included.

Any curator may pin, unpin, or move a pin. There is no separate pinning right,
because curator and moderator are the same role today (§9.4), and a group
trusted to destroy a contribution is trusted to pin a note about it. A direct
message cannot be pinned: the form refuses it, and the server refuses it again.

### 13.6 Visibility

Three rules, enforced in the query and again before any write:

1. Every curator sees every post whose `recipient_id` is null.
2. A post with a `recipient_id` is visible to its author and its recipient, and
   to nobody else, administrators included. It is a private note between two
   curators, and the room does not pretend otherwise.
3. `ROLE_USER` gets 403, anonymous gets the login redirect. The controller
   carries `#[IsGranted('ROLE_CURATOR')]` like the rest of `ModerateController`.

**The room is not region-scoped, and that is a decision, not an oversight.**
Every other moderation query filters by `ModerationScope` (§9), and the
region-scoping rule is that each new region query filters or says why it does
not. The room does not filter, because the rulebook's "if an item is out of
reach, ask the room" only works if the room reaches past the asker's area. A
room per area would silence exactly the question it exists to answer.
`curator_post` therefore holds no region column at all, so there is nothing a
later query could accidentally filter on.

### 13.7 The badge

The Room tab on the moderation bar carries the count of posts the reader has
not seen: `created_at > curator_room_visit.last_seen_at`, excluding the
reader's own posts, and excluding directed posts not addressed to them. A
curator with no visit row counts nothing on their first load, because the
room's whole history is not unread, it is history. `last_seen_at` is stamped on
every room load, whatever category is showing: the room is one room, so seeing
it is seeing it.

### 13.8 Routes

All on `ModerateRoomController`, locale-prefixed and `ROLE_CURATOR` like the
other desks.

| method | path | name | does |
| --- | --- | --- | --- |
| GET | `/moderate/room` | `moderate_room` | the board. `?c=<category>` filters, `?c=direct` shows the reader's direct messages. An unknown `c` falls back to All rather than 404ing. |
| POST | `/moderate/room/post` | `moderate_room_post` | write a post: body, category, optional recipient, optional submission id. CSRF-protected. |
| POST | `/moderate/room/pin` | `moderate_room_pin` | set a post's `pin`. CSRF-protected. |
| POST | `/moderate/room/delete` | `moderate_room_delete` | delete **your own** post. A hard delete with no tombstone: this is a staffroom note, not a moderation record, and §8's record-keeping principle covers decisions, not conversation. |

Every POST redirects back to the room with the active category preserved,
matching `ModerateController`'s existing redirect discipline.

### 13.9 Surface

`templates/moderate/room.html.twig`, inside the account shell with
`active = 'moderate_room'`. `_shell_chrome.html.twig` gains `moderate_room` in
its `in_moderation` list, and a Room tab carrying the §13.7 badge.

Category chips across the top, pinned posts in their own block, then the list
newest first, then the composer. **No JavaScript at all**: plain forms, so the
strict CSP has nothing to allow, and a curator whose script tag never loaded
can still ask their question. `about_submission_id` renders as a link to the
queue card. The reader's own scope still decides whether that card opens, so a
link to an item outside their area refuses at the target, as §9.3 requires.

### 13.10 The rulebook's link

`templates/moderate/rulebook.html.twig` links the room by literal path, with a
comment saying to switch once the route lands. When it lands, the screen-only
anchor becomes `path('moderate_room')` and the comment goes. The
`span.rb-print` twin stays: §5's PDF must carry no link annotations, and the
route existing does not change that.

## 14. Contributors and curators, the public explainer (2026-09-08)

One page, two halves: `/contributors-and-curators` (`LocalizedPath::ROLES`,
`PageController::roles()`, `pages/roles.html.twig`, slug localised in all
five, on the sitemap and the public cache list). Owner: "a specific page
about what a contributor/moderator does"; option 1 chosen over two pages,
because riders rarely read both and one page cannot drift from itself. The
left half is the contributor (any rider with an account: adds, fixes,
confirms; a curator checks the edit before it goes on the map, owner 2026-09-08: "it shows on the map at once is not true, there is moderation"), the right half the curator
(a rider accepted for a region or a country, "the person who brings that
place to life"). The curator's list is what a curator can do, never must
(owner: "things a curator can do, not what they must do"; the line above it
says so: "as much or as little as suits you, none of it is a duty"), and it
leads with the living work and ends with the checking (owner: "now it is all
policing"): finds open data to
import there, knows the place and fills its gaps, makes the Commons known
locally (clubs, shops, tourist offices), welcomes new contributors; then
checks, confirms or declines with a note, and adds or fixes directly with
no wait for a check. The two buttons are the site's `.btn.btn-p`, orange
with ink text (owner: "orange buttons have black text unless they are in
the header"). One worked example
under both, with local names and a local place in each language (owner:
"depending on languages use local names"; Bath and England in English, Hoorn
and Noord-Holland in Dutch, Annecy in French, Freiburg in German, Girona in
Spanish), and
the line that every curator is also a contributor. Two roles only; "curator"
is the public word for what the code calls the moderator role. Linked from
the curator application's lede and from Get involved. The application's
story field asks for hints, not credentials (owner: "sounds too much like it
is needed"): where you ride, what you know about the place, what you have
mapped before. Pinned by `RolesPageTest`.

The same night the application page (`/join/{cc}`) took the site's full
width with a 760px reading column from the left, its intro became two
columns, the words left and one outlined `.btn.btn-g` to the roles page on
the right, and the "add or fix something" link under it went. Get involved
(`/join`) lost its "Riders and local knowledge" block and its five how-to
lines (25 `join.howto*`/`heart_*` keys removed): that story lives on the
roles page, and Get involved is "about all things needed beyond curators
and contributors", opening on the skills the project needs; its closing
block is left-aligned like the rest. On `/regions` the curator link on each
country row is an icon (a person with a plus), quiet grey, orange on hover,
named by title and aria-label.

## 15. Open questions

- **Confirmations vs the verification threshold (X):** whether
  `item_confirmation` tallies are the counter feeding the
  Unverified → Verified gate in
  [edit-items/README.md](edit-items/README.md#verification-threshold-x) or a
  parallel freshness signal. Nothing in the code consumes the tallies for
  state transitions today; the funnel wiring is undecided.
- **Rejected route-proposal GC:** joining the 3-month sweep means deleting
  catalog rows whose `source_ref` provenance assumed permanence and orphaning
  their `route_change_history` rows — needs an M9-style content-free snapshot
  or a tombstone before inclusion.
- **Retention for never-answered `needs_info` rows** (live forever today —
  auto-reject after N months?) and for **approved** rows.
- **Read-message expiry** (do old read messages ever GC?).
- **GDPR story for free-text bodies on no-FK user rows** (correction bodies
  specifically): anonymised-by-decoupling is the deliberate default (§5.6),
  but whether authored *text* should join a deletion hook is unconfirmed.
- **`/map?feature=<refLabel>` on approved-submission messages:** `ref_label`
  for the submission channel is the `SUB-<id>` receipt, not the item name the
  map's `?feature=` lookup matches — whether this deep link resolves for all
  approved-submission messages is unverified.
