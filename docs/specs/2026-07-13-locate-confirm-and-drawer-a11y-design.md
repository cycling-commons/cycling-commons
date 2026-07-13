# Locate Confirm-Map, "Fix Location", and Drawer A11y — Design

**Status:** Approved (2026-07-13). Follow-up to
`2026-07-13-registry-driven-drawer-fields-design.md`.

**Goal:** Fix three problems the registry-driven drawer surfaced: (1) the
empty-field labels and "+ add" prompts fail WCAG AA contrast; (2) clicking a
"+ ADD" prompt drops the contributor onto a dead, empty LOCATE map; (3) editing
an already-located item hands a full-screen blank map to something that is
usually already correct — with no quick "this pin is wrong" path.

**Tech:** vanilla JS (`web/assets/contribute/improve.js`, `web/assets/map/map.js`),
Twig (`web/templates/contribute/improve.html.twig`), CSS
(`web/assets/styles/map.css` + the improve template's inline `<style>`),
Symfony translations (EN/FR/NL/DE, strict parity).

---

## Background — current behaviour (verified)

- `improve.js` reads `?lat=&lng=` and, when present (`hasCoords`), centres the
  LOCATE map on the item and pre-places a draggable pin
  (`improve.js:14-36, 250-259`). `LOCATE = (ADD || hasCoords) ? _locMode : 'off'`.
- The **"✎ Edit this item"** drawer link already passes `&lat=&lng=` when the
  item's geometry is known (`map.js:1168`). The **"+ ADD"** prompt link does
  **not** — it is only `?item=&type=&field=` (`map.js:275`).
- When `LOCATE === 'off'`, step 1 is **not** skipped — the code only sets
  `WZ.loc = {type:'none'}` to satisfy the Next gate (`improve.js:518-520`),
  while `#wmap` is never initialised. The LOCATE pane still renders, showing the
  map div's `--paper-deep` (#E2D5BC) background: the "empty beige map" the user
  hit. The contributor must click Next to escape it.
- `#wmap` is `height:clamp(360px,68vh,640px)` (improve template inline CSS) —
  full-screen even when the location is already known and correct.
- The `field=` param on the "+ ADD" link is currently **unused** by `improve.js`.
- Drawer empty styles on the near-black drawer (`--ink` #101E16):
  `.cc-d-rec li.empty .k` = `rgba(239,230,212,.4)` (~3.3:1) and `.cc-d-add`
  = `rgba(239,230,212,.45)` (~3.8:1) — both below AA 4.5:1.

---

## Part A — Accessibility (contrast)

Bring the empty-row styles to **WCAG AA ≥ 4.5:1** against `--ink` (#101E16).
Ratios below are computed against #101E16 (relative luminance ≈ 0.0109).

- **Empty label** `.cc-d-rec li.empty .k`: `rgba(239,230,212,.4)` → **`.6`**
  (≈ 5.75:1). The "empty vs filled" distinction is carried by the row *content*
  (a value vs a "+ add" link), not by a dimmed label — so state is not conveyed
  by contrast/colour alone (a11y win).
- **Add-prompt link** `.cc-d-add`: resting `rgba(239,230,212,.45)` → **`.62`**
  (≈ 6:1), keeping the dashed underline; `--trail` (orange, ≈ 5.5:1) on
  `:hover`. Using a compliant neutral at rest (not orange) avoids a wall of
  orange when a type has many empty fields.
- Add a **`:focus-visible`** ring to `.cc-d-add` (keyboard affordance), matching
  the existing `.cc-drawer-x:focus-visible` pattern (`2px solid var(--trail)`).

No markup change; CSS only, in `web/assets/styles/map.css`.

---

## Part B — Skip the LOCATE pane for "+ ADD" (add-a-field)

When `LOCATE === 'off'` (a "+ ADD" click: no `lat/lng`, no `mode=add`), the
wizard must **start on step 2** rather than render a dead LOCATE pane.

- In `improve.js` init: when `LOCATE === 'off'`, set the initial step to 2
  (call `step(2)` at startup instead of leaving `WZ.cur = 1`), and mark the
  step-1 stepper `<li>` as skipped/hidden so it does not read as the active step.
  Keep `WZ.loc = {type:'none'}` so the (now unreached) gate stays satisfied.
- **Honour `field=`:** on load, if `field=<key>` is present, scroll to and
  focus the matching Details field (`fld(key)` already maps a field key to its
  form control). So "+ ADD Website" lands *on* the Website field. If the key
  is unknown/absent, no-op (start of step 2 as normal).
- No map is created on this path. The "+ ADD" link (`map.js:275`) is unchanged
  — it needs no coordinates because no map is shown.

---

## Part C — Compact confirm-map for "✎ Edit this item"

When `hasCoords` **and not** `ADD` **and not** `fix=location` (Part D), render
step 1 as a compact confirm-map instead of the full canvas.

- **`#wmap` gains a compact height** via a state class (e.g. `#wmap.confirm`
  ≈ 190px). Marker is pre-placed at the item coords (existing `improve.js:252`)
  and centred; add a **soft glow** to the pin (a `.cc-pin.confirm` / keyframe
  halo in the template's inline CSS) to draw the eye.
- Compact map is **view-only reassurance**: pan/zoom allowed, but the pin is not
  repositioned here. `WZ.loc` is pre-set from the known coords, so **Next** is
  enabled immediately with no interaction required.
- A **"◎ Change location"** icon-button (shared glyph with Part D) expands the
  map: remove the `confirm` class (grow to full height), reveal the search box,
  enable tap-to-place and make the pin draggable — i.e. the current full editor.
  Once expanded it stays expanded for the session.
- **Heading/help copy** in confirm mode: "Where is it?" / "This location is
  already set — check it looks right, or change it." `mode=add` keeps the
  existing "Search for the area, then tap the map…" copy and the full-size map.

---

## Part D — "◎ Fix location" affordance

A direct "this pin is wrong" path, in two places sharing one glyph (`◎`).

- **Drawer:** a **"◎ Fix location"** icon-button rendered with the item actions
  (beside "✎ Edit this item" in `map.js`), shown **only** when the item has
  coordinates *and* a real DB id (same guard as the edit link). It links to
  `/improve?item=<id>&type=<letter>&name=<name>&lat=<lat>&lng=<lng>&fix=location`.
- **New `improve.js` param `fix=location`** (`RELOCATE`): forces the LOCATE step
  on and opens it **directly in expanded change-location mode** (full map, pin
  pre-placed + draggable, search visible) — skipping the compact confirm, since
  the intent is explicitly to move the pin. Update the mode gate to
  `LOCATE = (ADD || hasCoords || RELOCATE) ? _locMode : 'off'`, and the
  compact-vs-expanded decision to: compact when `hasCoords && !ADD && !RELOCATE`.
- **Shared glyph:** `◎` is used for both the drawer affordance and the compact
  map's "Change location" button (unicode, not emoji — emoji render unreliably
  in Chrome here, per the flags precedent).

### Entry-point summary

| From | Link params | LOCATE step |
|------|-------------|-------------|
| "+ ADD" a field | `item,type,field` | **skipped** → step 2, field focused (B) |
| "✎ Edit this item" | `…,lat,lng` | **compact** confirm-map, expandable (C) |
| "◎ Fix location" | `…,lat,lng,fix=location` | **expanded** editor directly (D) |
| "Add a new place" | `mode=add` | full editor (unchanged) |

---

## i18n

Two different string surfaces, treated per their existing convention:

- **Improve form (translated):** the improve wizard already uses `|trans` keys
  (e.g. `improve.step1.label`). New keys, added to **all four** locale files
  (en identity value) to keep `tools/check-translations.sh` parity:
  - `improve.locate.confirm.help` — "This location is already set — check it
    looks right, or change it."
  - `improve.locate.change` — "Change location" (compact-map button).
  Reuse existing keys where wording already exists.
- **Drawer (English literal):** the map drawer is built in `map.js` from English
  literals today ("Edit this item", "Type", "Province", "Source" …) — the
  registry-driven *field labels* are localised via `CC_FIELD_SCHEMA`, but the
  structural action/section strings are not. So the **"◎ Fix location"** drawer
  affordance is an English literal alongside "✎ Edit this item" — **no new
  translation key**. Localising the drawer's structural strings is a separate,
  broader effort and is out of scope here.

---

## Testing

- **Contrast (Part A):** assert the new values compute ≥ 4.5:1 against #101E16
  (documented in the plan); visually confirm the empty labels and "+ add" links
  in the drawer.
- **Skip LOCATE (Part B):** open `/improve?item=<id>&type=E&field=web` for a
  located stay → wizard opens on **step 2** (Details), no map pane, Website
  field focused. `node --check` on improve.js.
- **Compact confirm-map (Part C):** "✎ Edit this item" on a located item →
  step 1 shows the ~190px map with a glowing centred pin and a "◎ Change
  location" button; Next is enabled without interaction; clicking the button
  expands to the full editor with a draggable pin + search.
- **Fix location (Part D):** the drawer shows "◎ Fix location" only for located,
  DB-backed items; clicking it opens the LOCATE step already expanded.
- **Regression:** "Add a new place" (`mode=add`) still shows the full-size
  interactive map; existing improve WebTests stay green; translation parity
  holds (all four locale files equal key count).

## Rollout notes

- No schema or server change; controller is untouched (all params are read
  client-side, as today). Pure front-end + translation-data change.
- `map.js` is verified by served-bundle grep + a live browser check (no JS test
  runner); `improve.js` likewise. PHP suite + `check-translations.sh` gate the
  translation additions.
