<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Plan a ride: the one-day route planner

Canonical. Built 2026-10-14 (the owner reviewed the clickable prototype the
same day: "build this into our existing system"); the account's Rides page
and the three wiki passages of section 2 ship with it. Owns
what the planner is and is not (section 1), what it reverses (section 2),
where it lives on the map (section 3), how a ride is drawn and costed per
bike (section 4), what is listed along it (section 5), saving and the GPX
(section 6), the personal data (section 7), the endpoints and limiters
(section 8), the tests (section 9), the build order (section 10) and the
decisions the owner still takes (section 11). The map shell is
[map-and-search.md](map-and-search.md)'s; the routing engine and the heights
are [climb-elevation.md](climb-elevation.md) section 2's; the bike vocabulary
is [route-domain.md](route-domain.md) section 8.3's. A ride the rider keeps
is a file of their own (section 6.1), never a row of ours.

## 1. What it is, and what it will never be

Owner, 2026-10-14: "simple routing", "just a one day planner", "this will
never be full blown like RideWithGPS or Komoot", "one important thing is
users plan according to their selected bike type". Riders asked for it; the
project had chosen not to build one (section 2).

The planner answers one question: **which road takes me from here, past
these places, to there, on my bike.** Everything in the left column exists so
that question gets a true answer; everything in the right column is a
different product and stays out.

| In | Never |
|---|---|
| A start, stops and an end, set by clicking the map or by adding a place the Commons knows | Turn-by-turn directions or navigation |
| The line follows roads, chosen by our routing engine for the rider's bike type | Alternatives, "avoid this area", "prefer quiet roads" sliders |
| Distance, metres climbed, metres descended, and the height profile | Calories, training figures, a personal pace or level |
| The riding time of one general reference rider per bike type, the flat-equivalent distance, and what makes the day tougher: steepness, water gaps, busy roads, daylight, the forecast (section 4.4) | Comparing a ride against the rider's own history or fitness; there is no usage data and none is collected for it |
| What the Commons has along the ride (a tap, a toilet, a climb, a view), each addable as a stop | Multi-day plans, round-trip generation |
| Keep the ride as a file on the rider's own device, the GPX, and open it again to carry on; open another app's GPX, converted to checkpoints (section 6.1) | A copy of a ride on our server; public or shared plans, comments, ratings, a feed |
| Download the ride as a GPX (GPS Exchange Format) file for a device or another planner | Sending to a device or an app for the rider |

The ride check (map-and-search.md section 9) stays what it is: a file a
rider already has, read once and discarded. The planner is the other way
round: a line the rider draws here.

## 2. What this reverses, and what stays true

The record of every place that said "no route planning", and what each one
becomes:

- **This spec's own "no time or speed estimates"** (2026-10-14). Reversed
  2026-10-15 for one general pace per bike type (section 4.4), after the 1
  to 5 level proved uncalibrated: a time is the number a rider plans a day
  by. A personal pace stays out.
- **map-and-search.md section 11**, "No route planner on the map (owner
  2026-08-02)". Reversed 2026-10-14. The reason behind it still binds the
  planner: a control that looks like a planner and is not one is refused, so
  the line comes from the routing engine or there is no planner. Starting a
  ride from the rider's own position stays deferred.
- **The wiki describes what is**, so three passages change in the change that
  ships the planner, not before: `wiki/developers/gis/routes.md` ("Not in the
  Commons, yet"), `wiki/developers/gis-beyond/routing.md` ("What this project
  actually does", and its "Try it" exercise, whose grep still finds no browser
  file calling a routing engine, since the browser talks to our server, but
  whose sentence that nothing under `web/assets/map/` routes becomes false),
  and `wiki/developers/gis-beyond/index.md` (chapter 4 has no routing engine
  to demonstrate).
- **The roadmap item** "Take a route with you" (Next) becomes "Plan a ride on
  your bike" (Now), by migration `Version20261014010000`: the GPX export it
  promised is section 6 of this planner.
- **The privacy notice already says** that the route planning runs on our
  machines (`privacy.share_selfhosted`): true today for the climb editor's
  snapper, true for the planner. The banner "No ride tracking" stays true: a
  planned ride is a line the rider draws and asks us to keep, never a line
  they rode (section 7).
- **The developers' worked example** (`wiki/developers/api/worked-example-route-planner.md`)
  is about somebody else's planner adding our overlays through the public API.
  It stays as it is.

## 3. Where it lives: the map's fourth section

The planner is a rail section of the map (map-and-search.md section 4.0),
not a page of its own: the rider plans while looking at the places the map
shows, which is the point.

- **The rail** gets a fourth icon after Ride tools: **Plan a ride** (two pins
  joined by a line), key `plan`, `/map?panel=plan`. The home page's
  "Plan a ride" link opens it the way "Check your route (GPX)" opens the
  tools. While a stop is on the map the icon wears the rail's orange dot,
  the one Layers wears for an active filter (map-and-search.md section
  4.0), so a closed panel still says a ride is being planned (owner,
  2026-10-15).
- **The panel**, in the 320 px drawer, top to bottom: the bike chips
  (section 4.2), one instruction line ("Click the map to set a start, then
  click again for each stop"), the stops (section 4.1), the figures
  (section 4.4), the profile (section 4.5), what is along the ride
  (section 5), then Download GPX and Clear (section 6). **Open a GPX** sits
  under the ride's name, there from the empty planner on (owner 2026-10-16).
- **The line on the map** sits above every other line layer, the routes, the
  surfaces, the climbs, and below the place icons, in the one stacking order
  of map-and-search.md section 4.1 (owner 2026-10-15: "these routes must go
  over any other layer, but stay below spot icons"). It is thin, 1.8 px at zoom 10
  to 3 px at zoom 18 over a faint cream edge, so the surface band under it
  stays readable on both sides (owner 2026-10-15). Chevrons along it, from
  zoom 11, show which way the ride goes (`plan-arrows`, owner 2026-10-15);
  the line's eye hides them with it.
- **Show or hide the line** (owner 2026-10-15): an eye beside "Your ride"
  in the panel (`#planLineToggle`) hides the ride line and shows it again,
  so the surface under it can be read on its own; the stops, the profile and
  the figures stay. The choice is a per-browser convenience
  (`localStorage` `cc-plan-line`), kept across visits, never with a ride.
- **Opening the planner with a ride on the map** takes the map to the ride
  (`fitRide()`, clear of the drawer and the strip; owner 2026-10-15).
- **The stops fold** once the ride has a start and an end: one line says
  "5 stops, Petit Coo to Thier de Coo" with "show" beside the heading; the
  list opens on it (owner 2026-10-15). Folded, that line is a tinted card
  with a chevron, a click on it opens the list, and the open list sits in
  the same tint, so the stops read as one block (owner 2026-10-15).
- **One name**: the name at the top of the panel names the GPX; there is no
  second name field (owner 2026-10-15).
- **The ride outlives the panel.** Closing the panel keeps the line and the
  stops on the map. While a ride with at least two stops is on the map and the
  panel is closed, a **ride bar** sits at the foot of the map: "42.3 km ·
  610 m ↑ · 3 stops", with Open and Clear. Opening a place closes the panel
  as always (map-and-search.md section 4.0); the place drawer then carries
  **Add to ride** (section 4.3), and that button reopens the panel.
- **On a phone** the panel is the sheet (map-and-search.md section 6.6) and
  the ride bar sits above it.
- **Leaving the page** asks nothing: the draft stays in the browser for
  seven days (section 6.0), and the file is how a ride is kept longer.

**As built.** The rail button `ib-plan` (`data-panel="plan"`) and the panel
`p-plan` sit in `templates/map/index.html.twig`; `shell.js` titles it and
tells every panel change on the document as `cc:panel`; `plan-state.js` is
the leaf that says whether a ride is being planned (the panel open, or a
stop on the map), which `scope-ui.js` reads so a map click while planning is
a stop and never a scope change. A click on a line or an area of ours (a
route, a surface stretch, traffic, street-view coverage) is a stop too: its
own drawer stands aside (`plannerTakesLineClick()` in every such handler),
and only a point of ours (a pin, a coverage point) is a click on that place,
with its Add to ride (owner 2026-10-17: the road under a surface line took no
stop; `tests/js/plan-line-clicks.test.mjs`). `plan.js` owns the DOM and the map:
`plan-model.js` is the pure model (stops and legs, where a place goes, the
figures, the profile bins, the GPX), tested under node
(`tests/js/plan-model.test.mjs`); `tests/js/plan-wiring.test.cjs` and
`rail-shell.test.cjs` pin the wiring. An empty map click is one that hits no
feature of ours: the basemap's own sources are remembered when the style
loads, and anything rendered from another source is a pin, a route or a
stretch. They are remembered where the map is made (`catalog-load.js`,
`window.__ccBaseSources`, on every style load), because the map script may
start after the style has loaded and the pin layers are in (owner
2026-10-15). The page hands the script its endpoints and tokens as
`window.CC_PLAN`; it has no save endpoint, for anyone: nothing of a ride is
stored on our server (section 6). The home page link
is not built yet.

### 3.1 The guide

When the planner opens, once a page, a short guide sits in the middle of
the map (owner 2026-10-15; the close button takes the keyboard, Escape
closes it): naming the ride and finding the start, clicking
stops, dragging and right-clicking them, places and Add to ride, undo and
redo with the Mac's keys, and the seven days in the browser with the GPX to
keep a ride. **Show this guide when I open the planner** is ticked; unticking
it keeps the guide closed. Signed in, the choice is a closed map hint on the
account (`MapHint::PlanGuide`, `POST /map/hint/plan_guide/close`, and
`POST /map/hint/plan_guide/open` to tick it on again), so it holds on every
device; a visitor's browser keeps it (`localStorage` `cc-plan-guide`).
**How it works** at the top of the panel opens the guide at any time.

**The map's credits stay visible** (owner 2026-10-16): while the height strip
is open, the map's attribution (OpenStreetMap, the elevation notice, Esri on
the satellite map) and the zoom buttons sit on top of the strip, and the ride
bar, the legend and the filter mark move up to clear them. Redo
is named as Ctrl+Shift+Z, the usual key, with Ctrl+Y beside it.

## 4. Drawing the ride

### 4.1 Stops

A **stop** is a point the ride goes through. The first is the start, the
last is the end, the rest are stops. A stop is either a **map point** (a
click on the map, named by its rounded coordinates until a place is added
there) or a **place**: a Commons item (`letter:id`) or a coverage point of
the tiles (`osm:<ref>`), which keeps its name and its category. A place stop
is the place's own point; the engine snaps it to the nearest road when it
routes.

- **A click on the map** adds a stop at the end.
- **Add to ride** on a place drawer or **Add as stop** on the along list
  (section 5) inserts the place **at its km along the ride**: between the two
  stops whose leg passes it. With fewer than two stops it is added at the end.
  A place already in the ride is not added twice; the button reads "In your
  ride" and removes it.
- **A right-click on a place's pin**, or a click with the command key on a
  Mac, does the same while a ride is planned (owner 2026-10-15): in at its
  km along, or out again when it is in the ride. The rider stays on the map;
  the numbered stop is the answer. Outside planning the click keeps its usual
  meaning. One rule says what a place is to the ride (`drawer.js`
  `planPlace()`), for the drawer button and the pins alike, and one
  synchronous event carries it from a pin to the planner (`util.js`
  `offerToRide()`, `cc:place-menu`). The stops' info box says so (below).
  A right-click on a tile pin looks 10 px round the pointer (owner
  2026-10-16: the narrow tip of a drop fell through), and a right-click the
  planner takes shows no coordinates popup: the pin's handler marks the click
  `ccTaken`, which the popup reads (MapLibre cancels every right-click's
  default itself, so `defaultPrevented` says nothing).
- **Reorder** by the row's up and down buttons; **Remove** per row. On a
  pointer device a stop can also be dragged on the map, which re-routes its
  two legs when dropped, and removed on the map by a right-click, or a click
  with the command key on a Mac (owner 2026-10-15); undo brings it back. A
  click on a stop never adds a stop. The stops' info box says so, and the
  stop's tooltip says it too.
- **At most 50 stops** (owner decision, section 11; 25 until 2026-10-16). The 51st click is
  refused with the reason.
- **Undo**: Ctrl+Z, or the command key with Z on a Mac (owner 2026-10-14),
  restores the stops as they were before the last change (a stop added,
  removed, moved, dragged, a place added, a clear), up to 30 steps back, while
  a ride is being planned; inside a text field the key keeps its own meaning.
  Each step is a full copy of the stops before the change; undo puts them
  back and the legs are routed again from them. Opening a ride file and
  Clear replace the whole ride, so their step keeps the name and the bike
  too; a stop change keeps the stops alone, so undo never takes back a bike
  the rider picked. A change to the stops takes
  the keyboard off a text field first (owner 2026-10-15): a drag or a
  right-click on the map leaves the focus where it was, and Ctrl+Z in the
  ride's name would otherwise undo the name, not the ride.
- **Redo**: Ctrl+Shift+Z or Ctrl+Y, or the command key with shift and Z on a
  Mac (owner 2026-10-15), puts back what undo took, one step at a time, the
  five steps nearest the ride at most; a new change to the stops forgets
  them, as in any editor.
- **The stops' info box** (owner 2026-10-15): an info icon beside Stops
  (`#planHelpWrap`, the pattern of the bike's explanation: hover shows it,
  a click keeps it, the next closes it) holds how to plan with the mouse and
  the keys: a click on the map adds a stop; a right-click on a stop removes
  it; a click on a place opens it, with Add to ride; a right-click on a place
  adds it at once; Ctrl+Z and Ctrl+Shift+Z or Ctrl+Y; and on a Mac the command
  key with a click for a right-click and in place of Ctrl.
- **Clear** empties the ride after a confirmation, and undo brings it back.

**A new ride starts with its name** (owner 2026-10-15: "first step a user
must add to a new ride plan is give it a name; then they can search for a
start town and we pan/zoom to that location; there the user sets the first
location"). The first block of the panel (`#planStart`):

1. **Name your ride** (`#planRideName`, up to 80 characters, kept with the
   draft and written into the GPX). The map takes no first stop without
   it: a click, or Add to ride on a drawer, opens the panel on the name with
   "Give your ride a name first."
2. **Where do you start?** (`#planStartSearch`), shown once the ride has a
   name and until it has a stop: a town or a street, through Photon in the
   browser as the map search is (map-and-search.md section 7.2), with
   `layer=city|district|locality|street` instead of the map search's towns
   only, biased to the map's centre rounded to 0.1° (about 10 km; the privacy
   notice's Photon row says so), towns listed before streets. Picking a
   row flies the map there (zoom 13 for a town, 16 for a street) and says
   "Click the map where you start." The scope follows the start picked as it
   follows a ride (section 5, `followRideScope`): Hoorn picked while the map
   looked near Spa takes the scope to North Holland, and clearing the ride
   gives the rider's own scope back (owner 2026-10-17).
3. **The first click** sets the start, as before.

### 4.2 Legs, the engine, and the bike

What each bike does to the route ("A road bike stays on paved roads...")
sits behind an info icon beside "Your bike" (`#planWhyBtn`): hover or focus
shows it, a click keeps it open, the next click closes it (owner
2026-10-15). "From your settings." heads that bubble. Under the chips one
switch stays: "Show every bike type" adds the other types, "Show fewer bike
types" takes them away again, keeping the bike the ride is planned for.

Two consecutive stops make a **leg**. Every leg is routed by our own
routing engine (climb-elevation.md section 2b: Valhalla, one instance per
continent, chosen by the leg's start), through one server endpoint,
`POST /map/plan/leg` (section 8). The browser never talks to the engine.
One call per changed leg: a new stop at the end routes one leg; a stop
inserted or dragged routes two; a removed stop routes one; a bike change
routes every leg, in ride order, one request at a time.

**The bike decides the roads.** `App\Elevation\BikeCosting` is the one place
the Commons' bike types (`App\Catalog\BikeType`, route-domain.md section
8.3) become the engine's `bicycle` costing options. The engine silently
falls back to its default (`Hybrid`) on a value it does not know, and
measured on the same three points that moves a route by about 2.5 %, so an
unknown bike is refused with 400, never passed on.

| Bike type | `bicycle_type` | Other options | Why |
|---|---|---|---|
| Road | `Road` | | asphalt, the engine's road-bike surface tolerance |
| Gravel | `Cross` | | unpaved tracks allowed, the cyclocross profile |
| MTB | `Mountain` | | paths and trails allowed |
| E-bike | `Hybrid` | `use_hills: 1.0` | a motor does not mind a hill; hybrid surfaces |
| Handbike | `Road` | `use_hills: 0.0`, `avoid_bad_surfaces: 1.0` | cannot stand on a climb or absorb a rutted track |
| Recumbent, Trike, Tandem | `Road` | `use_hills: 0.2`, `avoid_bad_surfaces: 0.8` | long or wide: steep and rough roads cost more |

The table is an owner decision (section 11); the shape of the rule is not:
one bike type, one costing block, named once, tested once.

**The chips.** The panel offers the bike types the rider set in their
settings (account-and-auth.md section 9, `users.bike_types`), in that
order, the first selected. A rider with none set, and a visitor, sees Road,
Gravel, MTB and E-bike with Road selected and, for the rider, a line to
settings ("Set your bikes once in your settings"). Changing the chip
re-routes the ride under the new bike and the figures change with it, which
is the honest way to show that the bike decides the roads.

**Never a road a cyclist may not use** (owner, 2026-10-14: "user must not be
able to lay down a route on a road a cyclist is not allowed on"). The engine's
`bicycle` costing rides only roads open to bicycles: a motorway, a trunk road
closed to cycling or a way tagged `bicycle=no` is never part of a leg, under
every bike type in the table, and a stop placed on such a road is snapped to
the nearest road open to bicycles. No option in the table, and no option a
future change may add, loosens that: `shortest`, `use_roads` and the like
weigh roads a cyclist may use, never roads they may not. Measured on the dev
engine on 2026-10-14: a road-bike leg across the E42 near Francorchamps uses
no motorway or trunk edge, and a stop dropped on the E42 carriageway starts
on the nearest ordinary road. The only line on the map that is not a road is
the "no road found" marker below, and it can never be downloaded.

**When the engine finds no road** (unreachable, or no connection between the
two points: an island, a motorway-only stretch), the leg is a **straight
dashed line** on the map, its row reads "No road found between these stops",
its distance and heights are left out of the figures and the profile shows a
gap there. A ride with a straight leg **cannot be downloaded** until
the leg routes (owner decision, section 11): a GPX with a straight line across
a lake is a lie a device would follow.

**Heights ride with the leg.** The leg endpoint asks the engine's `/height`
for the routed line resampled every 30 m, with the distance along
(`range`), and answers `heights: [[m, ele], ...]`. The engine fails by
returning zeros, not errors (climb-elevation.md section 2d), so a leg whose
every sample is exactly 0, or none is known, has no heights: the line stays,
the figures and the profile leave it out, and the row says "No heights here".
Land at sea level keeps its heights, zeros and all (owner 2026-10-17: a leg
near Hoorn, heights -2 to 8 m, lost its profile under a rule that wanted
nine samples in ten non-zero). A missing sample (null or the engine's large
negative sentinel) takes the height between its known neighbours along the
line, never 0 m (`ElevationClient::profile()`).

#### 4.2a Why this way?

A leg that clearly goes round, at least 1.3 times the straight distance
between its stops and at least 500 m more (`legGoesRound()`), carries **Why
this way?** beside its distance in the stops list (owner 2026-10-15: a road
bike went round a forest track and the rider could not see why). A click
asks the leg endpoint once more with `shortest: true`: the engine's shortest
way for the same bike, its `shortest` on top of the bike's own costing block,
so it still never takes a road a cyclist may not use. `whyThisWay()` compares
the two and one sentence under the leg names the main reason, in this order:
gravel or dirt the bike avoids ("The shortest way is 4.4 km, with 3.1 km of
gravel or dirt. Your bike avoids that, so this way is 7.6 km."), climbing it
saves (40 m or more), busy roads it keeps off (500 m or more), cycle paths
it keeps to (500 m more and a 20-point larger share: "The shortest way is
5.3 km, only 0.6 km of it on cycle paths. This way is 6.6 km, 5.4 km of it
on cycle paths, away from cars."), or traffic lights it keeps clear of (4 or
more fewer); within 3 % or 200 m it says this is about the shortest way
already. When nothing measured stands out it claims no reason: it says the
planner weighs the kind of road, not only the distance, and offers both on
the map (owner 2026-10-16: the old fallback, "roads or paths that suit your
bike less", named asphalt against asphalt through Hoorn). The cycle path
metres (a cycleway, or a road with a separated lane; a painted lane is not
one) and the traffic lights (on the edge that ends at them, now and then on
the node) come from the same engine walk as the surfaces
(`LegSurfaces`), as `surfaces.cycle` and `surfaces.signals`. The answer is kept per leg and bike in the tab, so a
second click asks nothing; a failed ask is not kept and the question comes
back. Nothing of it is stored on our server. With the stops folded, one line
under their summary says how many legs go round ("2 legs go round: see
why", `#planRoundSum`); a click opens the list and asks the first answer.

**See the shorter way and choose it** (owner 2026-10-15). The answer keeps
the shorter leg it compared with. Hovering the answer draws that way on the
map, dark and dashed (layer `plan-alt`); **Show on map** keeps it drawn and
frames both ways; **Ride the shortest way** makes the leg take it: the stop
it arrives at carries `way: 'shortest'`, the leg key carries it, the leg is
routed with `shortest: true`, the line already fetched is reused, and the
ride file keeps it (`<cc:way>shortest</cc:way>` on that waypoint). The leg
row then says "shortest way" with **Back to your bike's way**. Both are
changes to the stops, so undo takes them back.

### 4.3 "Add to ride" on the place drawer

Every place drawer (a Commons item, a coverage point, a town card's nearby
places) carries one more action (`cc-d-act`), **Add to ride**, as soon as a
ride has at least one stop or the plan panel was opened in this session. It
adds the place as a stop (section 4.1) and reopens the panel. It is the
same button on the along list rows (section 5), so a rider learns it once.
A place stop's row in the panel shows the category's kind icon and the
place's name. Clicking any stop's name, a map point's as much as a place's,
takes the map to the stop (owner 2026-10-14); hovering a row rings it.

### 4.4 Figures: the day

- **Distance** is the sum of the routed legs' lengths, from the engine.
- **Climbed and descended** are read from the leg heights with one rule:
  the samples are smoothed by a three-sample moving average, then every
  positive difference between consecutive samples is climbed and every
  negative one is descended. The rule is simpler than a climb's
  (climb-elevation.md section 4b counts only the climbing of one hill) and
  it is the same for every ride, so two rides compare.
- The figures follow the rider's distance and elevation units
  (account-and-auth.md section 9).

**Riding time at a steady pace** (owner 2026-10-15: "what else could we use
for toughness instead of a 1 to 5 scale; as we have no usage data we can't
compare the ride against someone's personal level; do we take into account
all the ingredients that make a ride tougher; all, generally speaking"). The
1 to 5 level of 2026-10-14 went: a "3" means nothing to a rider until they
have ridden a "3", and there are no rides to calibrate it on. What a rider
already knows is hours, and how many flat kilometres they can do in a day.
So the tile holds one number, the riding time of one **reference rider** per
bike type, and under it the ride's **flat equivalent**. No personal input,
no account data: the pace is general, and it stays general (section 1).

- **The model**, one pure function in `plan-model.js`, `rideTime(legs, bike)`.
  For every height sample step of every routed leg (the engine's 30 m
  resample, smoothed as the climbed rule smooths), the gradient of the step
  and the leg's rolling resistance (its surface mix, section 4.4a, weighted
  by metres) give the speed the reference rider holds at their power:
  `P = m g v (sin θ + Crr cos θ) + ½ ρ CdA v³`, with ρ 1.2 kg/m³, solved for
  `v`. On a descent where gravity alone gives more than the cap, the speed is
  the cap: the bike's on paved road, 30 km/h on gravel, 22 km/h on rough
  road; a leg's rolling resistance and cap are its surface mix's weighted
  means, since the engine answers per leg. Below 4 km/h the rider walks at
  4 km/h. An E-bike's motor stops
  helping at 25 km/h: above that only the rider's own power counts. A leg
  without heights is flat. The time is the sum of the steps, rounded to five
  minutes: "4 h 45". It is riding time, not the day: stops, photos and lunch
  are the rider's.
- **The reference riders**, `PACE` in `plan-model.js`: the power the rider
  holds all day, the drag area, the mass of rider plus bike plus a day's
  bag, the rolling resistance on smooth asphalt, the paved descent cap.

  | Bike | W | CdA m² | kg | Crr paved | cap km/h |
  |---|---|---|---|---|---|
  | Road | 150 | 0.36 | 90 | 0.005 | 42 |
  | Gravel | 150 | 0.40 | 92 | 0.006 | 38 |
  | MTB | 140 | 0.45 | 94 | 0.009 | 35 |
  | E-bike | 100 own, 250 motor to 25 km/h | 0.50 | 105 | 0.007 | 35 |
  | Handbike | 90 | 0.30 | 95 | 0.006 | 35 |
  | Recumbent | 150 | 0.25 | 95 | 0.005 | 42 |
  | Trike | 130 | 0.35 | 100 | 0.007 | 35 |
  | Tandem | 300 | 0.45 | 170 | 0.006 | 42 |

  Gravel rolls at 0.012, rough road at 0.020, an unknown surface at 0.007,
  whatever the bike. These are round numbers for a steady day on a loaded
  bike, not racing figures; they are the same for everyone, which is the
  point.
- **Feels like N flat km.** The same model, the same bike, on smooth level
  asphalt: the ride's time divided by the flat speed. The tile's second line
  reads "feels like 125 flat km", a number a rider sets beside their own
  flat days. Example: 85 km with 1,200 m up and 20 km of gravel on a gravel
  bike is about 4 h 45 and feels like 125 flat km; the same 85 km flat on
  asphalt is 3 h 10.
- **Time or distance** (owner 2026-10-15): a switch above the bars shows
  both splits in minutes or in kilometres (`rideTime().metres`), and the
  legends are text under each bar, big enough to read and wrapping, never
  cut off.
- **The graphic under the tile** (owner 2026-10-15: "show an infographic
  about the toughness based on all these elements"): two bars of the same
  riding time, each part with its minutes. **By surface**: on asphalt, on
  gravel, on rough road, over every step, from each leg's surface shares
  (`surfaceBars()`). **By gradient**: climbing (a step steeper than 2 %), on
  the flat, descending (steeper than −2 %) (`gradientBars()`). Two splits,
  not one, because a gravel climb is both (owner 2026-10-15: a leg from
  Thier Antoine with 955 m of gravel, mostly climbing, read "0 min on
  gravel" when climbing took the gravel's minutes). A part under half a
  minute reads "under 1 min", never "0 min".
- **What makes it tougher**, under the bar, one line each, only when it
  applies, so a flat ride on a mild day shows nothing:
  - **Steepest kilometre**, `steepestKm()`: the 1 km window of the profile
    with the highest average gradient, from 4 %: "steepest km: 9 % at km 31".
  - **Longest climb**, `longestClimb()`: the longest run of 100 m profile
    bins at 2 % or steeper, a dip of up to 300 m not breaking it, from 1 km:
    "longest climb: 6.2 km, 410 m up, 6.6 %".
  - **Longest stretch without water or food**, `waterGap()`: from the along
    list (section 5), the largest gap along the ride between the start, the
    letter B places and the end, from 15 km; not shown while that letter's
    list is capped, since the gap could be smaller than it looks. The line
    carries **Add the nearest place for water or food** (owner 2026-10-15):
    the middle of that stretch (`waterGapSpan()`) asks `/map/coverage/nearby`
    for letter B within 5 km, ours or OpenStreetMap's, and the nearest
    (`nearestWaterStop()`)
    goes into the ride where the dry stretch is: the stretch is sampled every
    200 m and each place measured to its nearest sample; the one that leaves
    the shortest dry part wins, the longer of the stretch before and after it
    plus the way there and back (`bestWaterStop()`; the nearest one could sit
    at an end and make the gap longer), and it goes in after the leg that
    holds its sample (`stopIndexAt()`), not into the leg it happens to lie
    nearest; none within 5 km says so, a place that would not leave a
    shorter dry part than the stretch is not added, nor one already in the
    ride, nor any on a ride that holds its 50 checkpoints: each says why.
    Reaching a place off the line can change the route itself, so once the
    new route and its along list are in, the planner checks: shorter, it
    says how long the longest dry stretch is now, in the status line; not
    shorter, it takes the stop out again and says why. Every "not added"
    shows in a dialog in the middle of the screen, "No water stop added"
    with the reason and OK (`#planNotice`, owner 2026-10-16: not a small
    line in the drawer). A place it names says what it is and where
    (`fillWhere()`, owner 2026-10-16: "Who is Koning?"): "Koning (Food stop)
    is 2.1 km from your ride, level with 74 km along it", its kind in the
    legend's words from the nearby answer's `food` and `potable`
    (`waterKind()`), with **Show on map**, which closes the dialog and flies
    to the place. A dry stretch runs from the last water or food the ride
    passes to the next one, so a water point stands at each end of it. The check takes back only the fill's own stop, and
    only while it is still the newest change (`pendingFill` holds the stop
    and the history size), without a redo step (`history.discard()`): a
    check left behind by a stop that never went in undid the rider's own
    last change, and the next Ctrl+Z took the opened file away (owner
    2026-10-16). The ride's own place stops
    count at the distance the ride reaches them (`stopRows()`), so a water
    stop whose pin lies off the road its leg ends on still splits the gap. The button is a drop with a plus, its words in its
    title and accessible name, and the line itself, hovered or focused,
    paints that stretch on the map as a yellow highlighter band under the
    ride (`sliceAlong()`, layer `plan-gap`; blue read as a river).
  - **Busy roads**: the kilometres on trunk, primary and secondary roads
    without a dedicated or separated cycle lane, from 1 km, read per edge
    from the engine (`LegSurfaces`: `road_class` and `cycle_lane`), carried
    with the leg as `surfaces.busy` metres.
  - **Daylight** for the ride's date: a date field under the figures, today
    by default, kept with the browser's draft and never in the ride file
    (a kept ride is ridden on many days). Sunrise and sunset at the start
    are computed in the browser (`sunTimes()`, the NOAA solar equations, a
    minute's precision), shown in the rider's clock: "daylight 10 h 40,
    08:12 to 18:52". When the riding time is longer than the daylight the
    line says so.
  - **The forecast** for that date, through our server (section 8): from 15
    km/h, the day's dominant wind direction and its highest wind speed, the kilometres
    ridden into it and with it (`windShares()`: the bearing of every profile
    step within 60° of the wind's origin is headwind, within 60° of its
    destination tailwind), the day's highest temperature from 28 °C, the
    rain from 2 mm. "Wind 28 km/h from the south-west: 42 km into it, 25 km
    with it · 31 °C · 6 mm rain". The forecast reaches 16 days; a later date
    shows no line. Credited "Open-Meteo" on the line and on the credits page.
- **The ride bar** (section 3) adds the time: "42.3 km · 610 m ↑ · 2 h 05 ·
  3 stops".

#### 4.4a The surfaces

The surfaces come from the engine itself: `POST /map/plan/leg` walks the
routed leg with `/trace_attributes` (`App\Elevation\LegSurfaces`) and folds
its surface words (paved_smooth and paved are paved; compacted and gravel are
gravel; paved_rough, dirt, path and impassable are rough) into metres per
class, plus the busy metres above, carried as `surfaces` with the leg and
summed over the ride (`surfaceMix`). The model reads them as rolling
resistance; the graphic shows them as the surface bar's parts.

**The engine guesses where OpenStreetMap is silent** (owner 2026-10-15,
measured on a leg through Chevrouheid: four roads with no `surface` tag the
engine called asphalt and dirt, 2.15 km the map draws as "surface not
recorded"). Until the server knows (the to-do below), the browser reads the
map's own surface lines where they are drawn: every 50 m of a leg, the
point is looked up among the drawn surface layers (`surftile-*` for a
recorded class, `surftodo-*` for none recorded), and the class found there
replaces the engine's word for that stretch (`tileSurfaceClass()`,
`mergeSurfaces()` in plan-model.js). A stretch keeps what it was seen as, so
the reading grows as the rider looks along the ride; a stretch never drawn
keeps the engine's split. Only with the Road surface overlay on, and only
for what has been on screen at a zoom that draws the lines. "Surface not
recorded" is its own part of the surface bar, in the map's red.

**Street names** (owner 2026-10-15: "what about street names, I miss
that info"). The same walk asks the engine for `edge.names` and the leg
carries `streets`: each name with the metre it starts at, a run of one name
merged, an unnamed road `null` (`LegSurfaces::read()`). A stop set on the
map shows its street under its name instead of its coordinates
(`stopStreet()`: where its next leg starts, the last stop where the last leg
ends), and the profile's hover says the street at that point beside the km
and the height (`streetAt()`). The names are OpenStreetMap's, from our own
engine: nothing leaves our servers for them.

An unnamed road says what it is instead (owner 2026-10-15: the hover was
empty on a road OpenStreetMap names nothing, way 10744244 at Coo): each
`streets` entry carries the road's kind from the engine's `edge.use`, road,
track, path or cycleway, and the hover and the stop row read "unnamed road",
"track", "path" or "cycle path" (`roadAt()`). The hover's marker sits at the same
distance the name is read at: `pointAt()` walks the drawn line in metres,
scaled to the engine's length, not by counting vertices (owner 2026-10-15:
at Coo the marker sat on Thier de Coo while the ride was 600 m further, on
the unnamed road, because the village part of the line has many vertices).

**To do: the recorded surfaces on the server** (owner 2026-10-15, "add
option 1 to the todo"). The weekly pipeline writes which OpenStreetMap ways
carry a recorded surface, way id and class, to a table, and
`POST /map/plan/leg` looks up each edge's way id (`edge.way_id` from the
engine's `/trace_attributes`); an edge whose way is not there is "not
recorded". Exact for the whole ride and every rider, with or without the
overlay, from the same data and classes as the map's lines. Measure the
table's size per onboarded country before building it; the browser reading
above then goes.

### 4.5 The profile

The profile is the strip along the foot of the map; the panel draws none of
its own (owner 2026-10-15: "remove the height panel in the left drawer").
A closed strip folds into a tab at the foot of the map, "Show the height
profile" (`#planStripTab`), and a click opens it again; the same button sits
under the figures in the panel (`#planStripOpen`) (owner 2026-10-15: closed,
it could not be opened again). The strip slides down into the tab and back up
(0.3 s), and what sits above it (the ride bar, the filter "!", the legend)
moves with it; the legend folds into its corner the same way, its content at
one fixed width so the text never rewraps mid-fold (owner 2026-10-15: it
shot up to a tall shape before settling). Riders whose
system asks for reduced motion get no animation (owner 2026-10-15).

An SVG profile under the figures, drawn with the climb profile's grammar
(climb-elevation.md section 6, `gradColor` bands: purple up, blue down,
darker as it steepens): one distance axis in the rider's unit, the height
axis in the rider's unit, a gap where a leg has no heights. Hovering or
touching the profile puts a dot on the map line at that distance, and the
stops are marked on the axis with their names. Nothing is re-measured here:
the chart draws the leg heights as they came. The same profile also runs as
a **strip along the foot of the map**, the map's width wide, with its own
close button; the panel keeps its profile too while the owner compares the
two (2026-10-14). The ride bar and the legend step up above the strip.

While the strip is open, the map's bottom-edge furniture moves up with it:
the ride bar, the filter "!" (just above the strip, at the left) and the
surface legend (just above the strip, at the right; the bug button sits on
the strip, so the legend keeps no gap for it there; owner 2026-10-15).

## 5. Along the ride

As soon as every leg is routed, and 1.2 seconds after the last change, the
planned line goes to `POST /map/plan/along`, which answers with the ride
check's lists (map-and-search.md section 9, `RideCheckService`): the
Commons items inside a 250 m corridor of the line, letters B to G and O to
Q, with their km along and metres off; the climbs the ride rides and the
climbs near it (route-domain.md section 6.4, `RouteClimbService::climbsAlong()`);
and the coverage points of the tiles that the browser reads along the same
corridor. The corridor is fixed at 250 m (owner decision, section 11), not
the ride check's picker: the planner's question is "what can I stop at",
not "how wide shall I look". The letter B rows also feed the longest
stretch without water or food (section 4.4).

**The ride moves the map's scope when it leaves it** (owner 2026-10-16: a
GPX of North Holland opened while the map looked at Wallonia showed no
places on the map). The answer also names the regions the line crosses, in
ride order (`regions`, `RideCheckService::crossedRegions()`, the ride
check's own list). When the scope does not already show every one of them
(`scopeCovers()` in `ride-scope.js`: a region scope holding them all, their
country, or Everywhere), the planner sets the scope to the ride's regions
(`rideScopeFor()`), never stored as the rider's own, the camera kept on the
ride, and the scope chip says "from your ride", as the ride check does.
Clearing the ride gives the rider's scope back, unless they picked another
since. A file just opened is framed by its checkpoints at once, before any
leg is routed (`fitRide()`).

The list renders with `along-list.js` as the ride check renders it, one
group per letter with its count, the first rows shown and the rest folded,
each row the name on its own line and "12.4 km along · 40 m off" under it
(every along list on the map is two lines per row, owner 2026-10-14, so a
name is written out). Every row carries **Add as stop** (section 4.1); a
row that is already a stop reads "In your ride". Hovering a row rings its
place on the map, as the ride check's rows do; the planner writes its own
rows (`plan.js`) because an add button cannot sit inside the row button
`along-list.js` writes.

## 6. Keeping a ride: the browser and the file

### 6.0 Work in progress, in the browser

The ride being planned lives in the rider's browser, never on our server:
the draft (`localStorage` `cc-plan-draft`: name, bike,
stops, the day) and its undo and redo steps (`cc-plan-history`), each with
the time of the last change. Both are kept **seven days after the last
change** (owner 2026-10-15, `plan-model.js` `WORK_KEEP_MS`, `workFresh()`),
so a closed tab or browser loses nothing; on the first visit after that the
browser forgets them; the panel says so under Keep it ("A ride you are
working on is kept in this browser for 7 days, including its history of
changes."). A cleared ride keeps its steps, so undo still brings it
back. A draft from before the time stamp is kept and stamped at its next
change. **Signing out forgets both** (owner 2026-10-15): the redirect home
after a sign-out carries `#signed-out` (`SignedOutMarkSubscriber`, on the
firewall's `LogoutEvent`), and `assets/js/signed-out.js`, on every page of
the base layout, removes the two keys and then the mark; the display and map
preferences stay. A fragment never reaches a server, so the mark is in no
log. This answers a shared computer: the next person finds an empty
planner. The privacy notice's local storage paragraph says so.

**A data system, not a users system** (owner 2026-10-15): nothing of a
ride is stored on our server. A ride to keep is a file the rider downloads
and opens again (6.1); no account is needed for it and no account holds
one. A share system, where the rider keeps a ride somewhere remote of their
own choosing, may come later.

### 6.1 The ride file

The GPX (6.2) is the ride file. **Download GPX** writes it from the ride on
screen. **Open a GPX** (`#planOpen`, a file picker for `.gpx`, under the
ride's name so an empty planner can open a file) reads one back in the
browser with `rideFromGpx()` (`plan-model.js`) and puts its name, bike and
stops on the map. **Over a ride being planned it asks first** (owner
2026-10-16), because the file replaces that ride: a modal dialog
(`#planReplace`) names the file and says so, with three answers, *Keep my
ride* (the focused one; Escape and a click beside the dialog answer the
same), *Download GPX* (the ride on screen, the dialog stays open) and *Open
the file*. A file that cannot open says why before anything is asked. Undo
and redo wait while the dialog is open. The legs are routed again under that bike,
because the line and the heights are not taken from the file: a road the
engine no longer knows shows as a "no road" leg, never as a silently
different ride. Opening is a change to the stops, so undo goes back to the
ride that was on the map before. The file never reaches our server; only
the leg requests every ride makes do.

A GPX this planner wrote (`creator="Cycling Commons"` or our namespace)
opens as it was written: its waypoints are the stops. It is refused with the
reason when it has no stops, more than 50, or a point off the globe.

**Another app's GPX is converted** (owner 2026-10-16, "You need to convert
it then"; `foreignRide()` in `plan-model.js`). Its track, else its route,
else its waypoints, becomes the stops, at most 50 (`MAX_STOPS`):

- A route or a list of waypoints that fits is taken as it is, names
  included: its points are the ones its author chose.
- A track, or a longer list, keeps the points that hold its shape
  (`simplifyTrack()`): first and last always; then, as Douglas-Peucker
  splits, the point farthest off the straight line between two kept points,
  until none lies more than `SHAPE_M` (25 m) off; the stops left go to the
  longest stretches, split at their middle along the line, so a long straight
  road is not left to the engine's own choice.
- A point repeated within 5 m (a device standing still) is one point; a point
  off the globe is left out; a file with no usable point is refused
  (`no_stops`).
- The name is the file's own (`<metadata>`), else the track's or the route's.
  The bike stays the rider's. Converted track points are map points.
- The legs are routed again for the rider's bike, as for every ride, and the
  status line says so: "Opened {name}, made in another app, as {n}
  checkpoints. The planner routes between them for your bike, so a road can
  differ from the file."

Measured on five Strava tracks (2026-10-16, road bike, dev engine): 3,315 to
13,024 points converted in 8 to 30 ms. Within 50 m, the routed ride covers
the track of a 51 km and a 78 km ride for 85 % and 79 % with 25 stops, 95 %
and 96 % with 50, 99.9 % and 99 % with 100. The owner chose 50 (2026-10-16):
a converted file spends at most 49 of a visitor's 100 legs a day. A file of
up to 25 MB opens.

**What is along a long ride.** The along request takes 5,000 line points at
most (`PlanController::MAX_LINE_POINTS`, `ALONG_MAX_POINTS` in
`plan-model.js`); a ride past about 110 km has more. `thinLine()` keeps every
k-th point, the first and the last, before the line is sent, so the corridor
of a ride up to the 400 km route cap is read.

Our extensions, which GPX 1.1 allows, in the namespace
`https://cyclingcommons.org/xmlns/ride/1` (prefix `cc`): the bike type in
`<metadata><extensions><cc:bike>`, and per stop the place in
`<wpt><extensions><cc:item>` (`B:123`, `osm:node/…`) with its letter in
`<cc:letter>`. A GPX the planner wrote before the extensions opens without
its bike, every stop a map point. Names are cut to 80 characters. The day
is not written (section 4.4).

### 6.2 The GPX

The GPX is written **in the browser** from the ride on screen, by one
writer (`gpxText()`): a GPX 1.1 file with one `<trk>` whose points carry the elevations, and one `<wpt>` per
stop, named by the stop's name, a place stop with a `<link>` to its page on
the Commons. The file is named after the ride ("La Redoute loop.gpx";
"Ride.gpx" when unnamed), and carries the extensions of 6.1. No server
endpoint writes a planned ride's GPX:
the public API's `/v1/routes/{id}.gpx` is for recommended routes and stays
what it is.

## 7. Personal data: privacy notice version 3

A planned ride is personal data: a line the rider drew, with the places
they chose, and where they mean to ride. What the notice says about it, in
the words the privacy spec requires (privacy-notice.md section 2: every
claim has a source of truth in code):

- **What we keep:** nothing. The ride lives in the rider's browser for
  seven days (6.0) and in the files they download (6.1). The leg, along
  and forecast requests reach our server to be answered and are not kept
  beyond the server's ordinary request logs (privacy-notice.md section 3).
- **Who sees it:** the rider, nobody else.
- **Export and deletion:** there is no copy, so there is nothing of a ride
  in the data export (account-and-auth.md section 11) and nothing to delete.

Keys, five locales: `privacy.collect_rides`, `privacy.change.v5_rides`, and
the sentence on the ride in `privacy.cookies_local_body`. **Not
significant**: no personal data is kept. It ships in privacy notice
version 3, the one version that holds everything since the published
version 2 (privacy-notice.md section 7a).

**The forecast** (section 4.4), the change key `v6_forecast` in the same
version 3: for the ride's date our server
asks Open-Meteo for the day's forecast at one point near the middle of the
ride, never near its start or finish and only for a ride of 20 km or more
(section 4.4), rounded to 0.1° (about 10 km) in the browser, with the date. Open-Meteo sees our server's
address, that rounded point and the date; nothing about the rider, no
account, no stops, no line. The answer is cached for three hours under the
rounded point and the date, and nothing else is kept. Keys, five locales:
`privacy.server_services_forecast` (under "Services our server asks") and
`privacy.change.v6_forecast`. **Not significant**: no personal data is
processed; it is a new service our server asks, and the notice names every
one of those.

## 8. Endpoints and limiters

| Endpoint | Who | CSRF id | Limiter |
|---|---|---|---|
| `POST /map/plan/leg` `{a: [lng, lat], b: [lng, lat], bike, shortest?}` (`shortest: true` asks the shortest way of that bike, section 4.2a) (GeoJSON order, as `/contribute/route`) → `{routed, coordinates, distanceM, heights, surfaces, streets}`; `routed: false` carries no line, so nothing that is not a road ever comes from the server | everyone (owner decision, section 11) | `plan-leg` | `plan_leg` 600 per 10 minutes keyed `user-<id>`; `plan_leg_anon` 100 per day keyed on the salted address hash, the ride check's pattern |
| `POST /map/plan/along` `{line: GeoJSON LineString}` → `{radiusM, climbs, groups, coverage, regions}`, the ride check's lists and the regions the line crosses (section 5); 2 to 5000 points, 500 m to 400 km, else 400 (`bad_request`, `length_range`) | everyone | `plan-along` | `plan_along` 60 per 10 minutes per user; `plan_along_anon` 20 per day |
| `POST /map/plan/weather` `{lat, lng, date}` → `{forecast: {date, windKmh, windFromDeg, tempMaxC, rainMm} \| null}`; `null` when the service is off (`FORECAST_URL` empty), does not answer, or the date is past its 16 days; a malformed point or date is 400 | everyone | `plan-weather` | `plan_weather` 60 per 10 minutes per user; `plan_weather_anon` 30 per day |

- The two open endpoints follow the stateless-JSON pattern (security-architecture.md
  section 5.1): a bad or cross-site token is 403, a malformed body is 400
  before any budget is spent, and the four limiters are in the inventory of
  security-architecture.md section 7 (built 2026-10-14, `PlanController`).
- `/map/plan/leg` is `RouteSnapper::snap()` with a bike: the one class that
  speaks to `/route` gains the costing block from `BikeCosting` and keeps its
  honest `null` when the engine is unreachable. The heights come from
  `ElevationClient` with `resample_distance: 30` and `range: true`.
- `/map/plan/along` is `RideCheckService` on a line instead of a file: same
  corridor, same letters, same caps, same climbs rule. No GPX is parsed and
  nothing is stored.
- `/map/plan/weather` is `App\Weather\Forecast`: the point rounded to 0.1°
  before anything else, one GET of Open-Meteo's daily forecast (wind speed
  and dominant direction at 10 m, highest temperature, rain sum; the day in
  the place's own time zone), a 5 second timeout, the answer cached for
  three hours in the `cache.forecast` pool under the rounded point and the
  date, so a ride dragged around a town costs one request an afternoon.
  Nothing of the rider reaches the service (section 7).

## 9. Testing

No rider's rows: nothing of a ride is stored, so there is no scope to leak.

- **PHP.** `BikeCosting`: every `BikeType` has a block, an unknown value
  throws. The leg endpoint: the costing block sent per bike, `routed: false`
  with no heights when the engine answers nothing, heights dropped only when
  every sample is 0 or none is known, a sea-level leg kept, a missing sample
  filled from its neighbours, the limiters asserted against the config. `/map/plan/along`
  answers the same groups as the ride check for the same line.
- **Node** (`web/tests/js`): inserting a place at its km along; the climbed
  and descended rule on a known sample; the profile's gap for a leg without
  heights; the GPX writer's output on a two-leg ride; the ride file written
  and read back, a GPX from before the extensions, and every refusal; the
  ride bar's text.
  The day (section 4.4): the flat speed of each reference rider at its
  power, a 6 % climb at about 9 km/h for the road rider, a descent at its
  cap, gravel slower than asphalt, a leg without heights as flat, the flat
  equivalent above the distance when there is climbing, the E-bike's motor
  stopping at 25 km/h; the steepest kilometre and the longest climb on a
  known profile; the water gap with and without places, and none while
  capped; sunrise and sunset in Brussels on both solstices to the minute;
  the wind shares of a line ridden east under a west wind.
- **PHP, the day.** `LegSurfaces` counts the busy metres from `road_class`
  and `cycle_lane`. `Forecast`: the point rounded to 0.1° before the request,
  one request for two asks of the same rounded point and date, `null` for a
  date past 16 days, for no service and for no answer. `POST /map/plan/weather`:
  the token, 400 for a bad point or date, the two limiters, `forecast: null`
  passed through. The test environment has no forecast service
  (`FORECAST_URL` empty in `.env.test`), as it has no engine.
- **Copy.** The five locales carry every new key (translations.md).

## 10. Build order

Each step is its own small commit or two; the specs change in the same
commit as the code they describe.

1. **This spec and the roadmap item** (2026-10-14): `route-planner.md`,
   `Version20261014010000`, the pointers in map-and-search.md, account-and-auth.md,
   privacy-notice.md and roadmap-and-changelog.md.
2. **Prototype review.** The owner locks section 1 and the decisions in
   section 11 on the clickable prototype; the spec is corrected first.
3. **Server.** `BikeCosting`, `RouteSnapper::snap()` with a bike and heights,
   `POST /map/plan/leg`, `POST /map/plan/along`, the limiters. Tests first.
   Built 2026-10-14.
4. **Map.** The rail section and panel, the ride layers, stops and legs,
   figures, profile, the along list, Add to ride on the drawer, the ride
   bar, the GPX writer, five locales. Node tests first. Built 2026-10-14.
5. **Keeping a ride.** First built as a server table with an account tab
   (2026-10-14); replaced the next day by the browser's draft and the ride
   file, nothing kept on our server (owner 2026-10-15).
6. **Ship.** The three wiki passages (section 2), the changelog note, the
   roadmap item marked shipped on the desk.

## 11. Owner decisions open

Each is proposed here and settled on the prototype; the chosen value
replaces the proposal in the section it belongs to.

| # | Decision | Proposal | Why |
|---|---|---|---|
| 1 | Who may draw a ride | Everyone, and anyone keeps a ride as a file | The ride check's reasoning: it answers a question before a rider has a reason to trust us. The engine's cost is bounded by the anonymous limiter |
| 2 | The bike costing table (section 4.2) | As tabled | The road, gravel and mountain profiles are the engine's own; E-bike and the specialty bikes are our reading |
| 3 | Caps | 50 stops (25 until 2026-10-16) | A one-day ride needs few stops; a long ride converted from another app's GPX keeps its roads with 50 (section 6.1) |
| 4 | A leg the engine cannot route | Refuse the GPX until it routes | A straight line in a GPX is a lie a device follows |
| 5 | The corridor along the ride | Fixed 250 m | "What can I stop at", not "how wide shall I look" |
