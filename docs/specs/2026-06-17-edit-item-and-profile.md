# Spec — "Edit this item" on every feature + contributor profile page

- **Status:** Draft for review
- **Date:** 2026-06-17
- **Scope:** Frontend demo only (no backend, no persistence)
- **Surfaces:** `atlas/demo/map.html` (drawer), `atlas/demo/improve.html` (made data-driven), `atlas/demo/edit-items.js` (new shared registry), `atlas/demo/profile.html` (new)
- **Author:** Cycling Commons / prototype team
- **Related:** [`2026-06-17-add-climb-flow.md`](2026-06-17-add-climb-flow.md), [`atlas/demo/improve.html`](../../atlas/demo/improve.html), [`atlas/demo/vote.html`](../../atlas/demo/vote.html)

---

## 1. Problem

Two gaps in the prototype, both about *contributing back*:

1. **The edit flow only represents one item.** [`improve.html`](../../atlas/demo/improve.html) is **hard-coded to
   "Repair station · Malmedy"** — its context block, map pin, current details, form fields and thank-you
   message are all the service station, no matter which feature you clicked on the map. So in the demo we can
   only honestly claim "you can edit a place" for **one** of the eleven catalog types. The todo:
   *"We need an edit-this-item for every item, not just the service station. In the demo this guarantees that
   we have thought about the design and implementation options."*

2. **"View profile" is dead.** The drawer's opt-in uploader line links `view profile → login.html`
   (a placeholder), and route uploaders have no profile at all. There is no example of what a public
   contributor's profile looks like.

## 2. Goals / Non-goals

**Goals**
- Every feature drawer on the map exposes an explicit **✎ Edit this item** action pointing at the edit
  surface for *that* feature (distinct from voting).
- [`improve.html`](../../atlas/demo/improve.html) becomes **data-driven**: it renders the context (name, coords,
  pin icon, tags, current details) and a type-appropriate "Fix details" pane for whichever item was opened,
  via a `?item=<id>` query param.
- **Multiple features may share one edit item.** Per the todo's simplification, the two water points link to
  the **same** edit target. The registry is a lookup keyed by id, so N features → 1 edit item is the natural
  shape, not a special case.
- A new **`profile.html`** example page: what an opt-in public contributor's profile looks like (stats +
  contribution list), on-brand, wired up from the "view profile" links.

**Non-goals**
- No real persistence, auth, or submission. Forms still end at the existing static "in the queue" screen.
- No new map data beyond the one extra water point needed to demonstrate the shared-edit-item case.
- No private/anonymous profiles — profile is **opt-in public contributors only** (consistent with
  improve.html's "provenance is kept; identity is not").

## 3. Design

### 3.1 Shared edit registry — `atlas/demo/edit-items.js`

A single global `window.CC_EDIT` keyed by edit-id. Each entry carries everything `improve.html` needs:

```js
window.CC_EDIT = {
  'malmedy-repair': {
    ey:'Improve this place', name:'Repair station · Malmedy', icon:'⚙',
    center:[6.027,50.426], co:'◎ 50.426°N 6.027°E · Wallonia, BE',
    tags:['tap','OSM','D · services'],
    cur:[ {k:'Type',v:'Public repair station'}, {k:'Pump',v:'Presta + Schrader'}, … ],
    fields:[ {label:'Pump valve', type:'select', opts:[…]}, {label:'Opening hours', value:'24/7'}, … ]
  },
  'water-fountain': { … shared by both fountains … },
  'redoute': { … }, 'mur-de-huy': { … }, …
}
```

- `fields` tailors **only the "Fix details" pane** per type (the part that proves we thought about each
  type's data). The **Add missing / Report a problem / Add a photo** panes stay generic — they apply to any
  feature and don't need per-type wording for the demo.
- One entry per catalog feature, **except** the two water points which both reference `'water-fountain'`.
- Default/fallback id is `'malmedy-repair'` so a bare `improve.html` (no param) still works as today.

### 3.2 `improve.html` — render from the registry

On load, read `?item=`, look up `CC_EDIT[id] || CC_EDIT['malmedy-repair']`, then populate:
- eyebrow, `<h1>` name, coords line, tag chips, current-details `kv` rows;
- the static MapLibre locator pin (recenter to `center`, swap the glyph to `icon`);
- the "Fix details" pane fields from `entry.fields`;
- the thank-you message name.

Implementation is a small render function replacing the hard-coded markup — the page keeps its existing
layout, styling, tabs and submit/thanks behaviour untouched.

### 3.3 `map.html` drawer — edit link on every feature

In `buildRecord(layer, f)`, replace the current either/or `act`:

- **Always** emit `✎ Edit this item → improve.html?item=<editId>`.
- **Additionally**, for curated features (`f.cur`), keep `▲ Vote in this round → vote.html`.

`editId = f.edit || slug(f.name)`. Features set an explicit `edit:` only when they want to **share** a target
(the two fountains both set `edit:'water-fountain'`). The registry ids in §3.1 use these slugs so links
resolve. Rides (J) get the edit link too, pointing at a shared `'ride'` edit entry (editing a contributed
GPX is one flow, not six).

### 3.4 Shared-edit demonstration — second water point

Add one more `water` feature (e.g. *Public fountain · Coo*) to the catalog, with `edit:'water-fountain'` —
the same id the Stavelot fountain uses. Clicking either pin → the **same** edit item. This makes the
"two water points, one edit item" requirement literally true and visible in the demo.

### 3.5 `profile.html` — contributor profile example

A static, on-brand page using the standard site chrome (brand top bar, `.foot` footer, `preview.js`,
`quicknav.js`). One worked example: **Hanne V.** (the opt-in public uploader of "Spa · Sankt Vith").

Sections:
- **Header:** monogram avatar, display name, "Contributing since 2024 · Wallonia", an *opt-in public* badge
  explaining only chosen-public contributions are shown.
- **Stats row:** routes shared · places improved · climbs added · confirmations (static demo numbers).
- **Contributions list:** a few cards (a shared route, an improved place, a confirmed hazard) linking back to
  `map.html` / `improve.html`.
- **Provenance note:** licence/governance line consistent with the rest of the site (ODbL data / CC BY-SA
  media; profiles are opt-in).

Wire-up:
- Drawer uploader line: `view profile → profile.html?u=hanne` (replacing `login.html`).
- The query param is cosmetic for the demo (single example page); unknown users fall back to the Hanne
  example.

## 4. Acceptance

- Opening the drawer for **any** catalog feature shows **✎ Edit this item**; curated ones also show
  **▲ Vote in this round**.
- Clicking **✎ Edit this item** opens `improve.html` showing **that feature's** name, coords, pin glyph,
  tags, current details and a type-appropriate Fix pane — verified for at least a climb, a water point, a
  stay and a scenic site (not just the service station).
- Clicking either water fountain opens the **same** edit item.
- **view profile** opens `profile.html` (no dead link), styled consistently with the site.
- `preview.js` sample-data badge present on the new page; footer/quicknav consistent.

## 5. Risks / Notes

- `improve.html` becoming data-driven must not regress the no-param default (still the Malmedy demo).
- Keep the registry small and flat; it is demo fixture data, not a schema. Real edit schemas per type are a
  later, backend-era concern.
