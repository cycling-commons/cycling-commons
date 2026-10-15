<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Map presets: the switches a rider wants, under a name

Canonical. Owner, 2026-10-15: "add the option in the user profile to let
users make presets for what filters they want on and off on the map", with
the visual approved the same day after four decisions (section 1). Owns what
a preset is (section 1), where it lives (section 2), the map's Presets group
(section 3), the settings section (section 4), the endpoints (section 5),
personal data (section 6) and the tests (section 7). The switches
themselves stay what [map-and-search.md](map-and-search.md) section 4.1 to
4.4 say they are; a preset only sets them.

## 1. What a preset is

One name and one set of map switches, a rider's own. The owner's four
decisions, taken on the mockup:

| Decision | Answer |
|---|---|
| What a preset holds | the data layers on or off, the two map overlays, the view mode, the season and bike filters of Best of, the per-layer facets (climb surface, traffic and effort; stay accessibility), and the key's rows: the surface classes and the route types (owner 2026-10-15) |
| How many | at most **6** per rider (`MapPresets::MAX_PRESETS`) |
| What happens when the map opens | the **Default** preset applies every time, over whatever the map remembered; without one, nothing changes |
| Linked to a tool (owner 2026-10-15: "presets should be linked to Scout, the route planner, Check my ride or general map use") | a preset is used for any of four tools, each tool takes **one** preset: **Default** (the map at open), **Plan a ride** (when the Plan panel opens), **Check my ride** (when the Ride tools panel opens), **Scout review** (when `/scout/review` opens, in place of Default) |
| Leaving a tool | the switches **go back** to what they were before the tool opened; opening Layers from inside the tool keeps its preset, so it can be tweaked, and so does a place drawer closing the panel (owner 2026-10-15: looking at a place is part of planning); any other panel, or closing, ends the tool |
| Add a climb | **left out for now**: the climb editor is its own page and its map draws only the climb |
| A visitor without an account | no presets; the Presets group shows one line, "Sign in to save presets" |

The settings, as stored and as sent (`MapPreset::settings`, JSON):

```json
{"mode": "curated",
 "layers": ["climbs", "water", "stays", "scenic", "experience"],
 "overlays": ["surface", "routes"],
 "seasons": ["summer"], "bikes": ["Gravel"],
 "facets": {"effortf": ["Steady", "Challenging"]},
 "surfaces": ["paved", "gravel"], "routes": ["regional", "knoop"]}
```

- `mode` is one of `curated`, `confirmed`, `all` (map-and-search.md section
  4.2). `layers` lists the catalogue keys that are **on**
  (`CategoryTable` keys, the twelve the Layers panel lists; the curators'
  pending layer is never part of a preset). `overlays` lists the overlay
  rows that are on, `surface` and `routes`. `seasons` and `bikes` are the
  Best of chips that are on (`BikeType` values; season words lower-case).
  `facets` maps a chip group id (`sqf`, `trf`, `effortf`, `accessf`) to the
  chip values that are on; a group that is absent is left as it is, every
  chip on. `surfaces` (`paved`, `gravel`, `pave`, `dirt`, `rock`,
  `unverified`) and `routes` (`national`, `regional`, `mtb`, `knoop`) list
  the key's rows that are on; absent, the key is left as it is, so an older
  preset changes nothing there. A name is 1 to 40 characters.
- `PresetInput::parse()` refuses anything else with 400 before any budget is
  spent: an unknown key, an unknown mode, a value that is not a string, a
  name too long. Unknown facet values are dropped, not refused: a chip the
  map no longer has must not make an old preset unusable.

## 2. Where it lives

`map_preset` (migration `Version20261015020000`): `id`, `user_id` (FK
`users`, `ON DELETE CASCADE`), `name` VARCHAR(40), `settings` JSONB,
`created_at`, `updated_at`; index `(user_id, created_at)`. `map_preset_use`,
which preset each tool uses: `user_id`, `context` (`map`, `plan`, `ride`,
`scout`, checked), `preset_id` (FK, `ON DELETE CASCADE`), primary key
`(user_id, context)`, so the database itself holds that a tool has one
preset. `App\Map\Entity\MapPreset`, `App\Map\MapPresets` (save, find,
listFor, rowsFor, useFor, delete, count: every one for one rider, another
rider's preset is a missing preset; using a preset for a tool moves the tool
off the preset that had it).

A preset is its rider's alone: no curator, public page, API or tile reads
it. It goes with the account, it is in the data export (`map-presets.json`),
and the purge test asserts that another rider's presets stay.

## 3. On the map: the Presets group

The first group of the Layers panel (`#p-layers`, above View mode), owned
by `web/assets/map/presets.js`:

- **One chip per preset**, in the order saved, the active one orange. Tap a
  chip and every switch below is set to it: the layer rows, the overlay
  rows, the mode, the Best of chips and the facet chips, each through the
  control's own handler (the row's click, `applyMode()`, the chip's click),
  so the map reacts exactly as to a rider's taps. The mode is applied
  without persisting it to the profile (`applyMode(m, {persist: false})`):
  a preset is a view, not a change of the rider's default mode.
- **Edited**: after any change in the panel while a preset is active, the
  chip shows a dot and a bar under the chips says "Changed since you picked
  {name}" with **Save changes** (writes the current switches into that
  preset) and **Reset** (applies it again). `presets.js` compares the
  current switches (`readState()`, from the panel's controls) with the
  preset's settings (`same()` in `presets-model.js`).
- **+ Save as preset**: a name field under the chips; saves the current
  switches as a new preset and makes it active. Hidden at the cap.
- **A pencil beside the heading** (tooltip "Edit your presets in Settings")
  links to the Map presets tab of Settings (`/account/settings?tab=presets`,
  section 4).
- **At open**: the preset used for the page applies once, after the panel
  is built (`initPresets()` runs after `initChips()` in `map.js`): Opens the
  Default on `/map`, Scout review on `/scout/review` (`CC_PRESETS.page`). Its chip
  is active. Without one, the panel opens as before.
- **A tool's preset**: on `cc:panel`, opening Plan a ride or Ride tools
  applies the preset used for that tool (`TOOL_OF_PANEL`), and a line under
  the chips says "Plan a ride uses Gravel weekend. Leaving it puts your
  switches back." Opening Layers from there keeps it, and so does a place
  drawer closing the panel (`cc:panel` carries `byDrawer`, set by
  `closeRailPanel()`); any other panel or closing puts back the switches the
  map had before the tool opened.
- **A visitor** sees the group with one line, "Sign in to save presets",
  linking to the login page, and no chips.
- The chip strings and the bar's copy come through `MapController::mapI18n`
  under `presets`.

## 4. In Settings: Map presets

A tab of its own on `/account/settings`, **Map presets**, beside Profile,
Security and Your data (owner 2026-10-15: "put the map presets in their own tab";
`settings/index.html.twig`, pane `#map-presets`, opened directly by
`?tab=presets`, the link the map's pencil follows), rendered and edited by
`web/assets/settings/map-presets.js` against the endpoints of section 5. It
sits outside the profile form: a preset saves on its own, never with Save
profile.

- "+ New preset" and "{n} of 6 presets" sit on the left, under the list; with
  no preset yet the list says "No presets yet."
- The list, one card per preset: the name, an "Opens the map" tag on the
  default, the switches it holds as small chips with the category's own
  icon in its colour (`CC_PRESET_CATALOG`, from `CategoryTable` and
  `ItemType::svgPath()`), and Edit, Make default, Delete. "+ New preset" is
  disabled at the cap.
- The editor: the name, the view mode, the twelve layers and the two
  overlays as switches with their icons, the season and bike chips, the
  facet chips, and "Use this preset for" (Default, Plan a ride, Check
  my ride, Scout review), each card showing its tools as tags. Save and
  Cancel. An off switch is not faded: the switch says it.
- The page works without the script in one sense only: it shows the list;
  editing needs it, as the public-profile toggle does.

## 5. Endpoints and limiters

| Endpoint | Who | CSRF id | Limiter |
|---|---|---|---|
| `GET /map/presets` → `{presets: [{id, name, uses, settings}]}` | signed in, 401 for a visitor | | |
| `POST /map/presets/save` `{id?, name, uses?, settings}` → `{id}`; `uses` lists the tools this preset is used for and replaces its list, absent leaves it; 400 `bad_request` on shape or an unknown tool, 409 `too_many` past the cap, 404 for another rider's id | signed in | `map-preset` | `map_preset_save` 30 per 10 minutes |
| `POST /map/presets/{id}/delete` → 204, 404 when it is not the rider's | signed in | `map-preset` | |
| `POST /map/presets/{id}/use` `{context}` → 204; the tool moves to this preset; 400 for an unknown tool, 404 when it is not the rider's | signed in | `map-preset` | |

`App\Controller\MapPresetController`, the stateless-JSON pattern of
security-architecture.md section 5.1, as `PlanRideController`. The map page
hands `presets.js` the list, the endpoint and the token as
`window.CC_PRESETS`; the settings page hands the same to its script, plus
the catalogue.

## 6. Personal data: the privacy notice

A preset is personal data: a rider's preferences under a name, linked to
the account. What we keep: the table of section 2. Who sees it: the rider,
nobody else. How long: until the rider deletes the preset or the account;
deletion is immediate. Export: `map-presets.json`. Keys, five locales:
`privacy.collect_map_presets`, `privacy.retention_map_presets`,
`privacy.change.v7_presets`, shipped in privacy notice version 3, the one
version that holds everything since the published version 2
(privacy-notice.md section 7a). Not significant: optional, only when a rider
saves one, nothing changes for data already given.

## 7. Tests

Scope first (the project's rule): `MapPresetControllerTest` opens with the
test that one rider's presets are never listed, changed, defaulted or
deleted by another rider or a visitor. Then: 401 for a visitor, 403 for a
bad or cross-site token, save inserts and answers the id, save with an id
updates, the seventh preset is 409 but an update still lands, the shape
checks are 400 (`PresetInput`), each tool takes one preset and using one
moves it, an unknown tool is 400, delete is 204 and gone, the limiter's
429.
`MapPresetDeletionTest`: the purge removes a rider's presets and nobody
else's. `DataExportTest`: `map-presets.json` carries the rider's presets and
not a stranger's. Node: `presets-model.test.mjs` (the state read from a panel
and the clicks that take one state to another, `same()`, the cap), and the
wiring pins in `map-presets-wiring.test.cjs` (the group above View mode, the
pencil, the visitor line, `initPresets()` last in `map.js`, the settings
section and script).
