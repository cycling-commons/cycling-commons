<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# The Commons — Data Catalog

Everything a rider can contribute to the open cycling Commons — climbs, water, stays, hazards,
viewpoints, and more. All of it is **community-contributed**, useful to **anyone**, and
**non-personal** (about the *world*, never a person's identity). Never scraped; contributed by riders — reports, edits, and aggregate, anonymous signals.

## Principles
- **Build on OSM, don't reinvent it.** Items tagged **[OSM]** already have a home in OpenStreetMap (ODbL). Sync with / contribute back to OSM for those; layer cycling-specific curation on top. the Commons's added value is *curation, normalization, freshness, and the cycling lens* — not re-collecting the base map.
- **Non-personal only.** No identity, no tracking, nothing personal. The Commons is the map, not the rider.
- **Media is a first-class, consent-gated contribution.** Photos and video join the Commons under **CC BY-SA 4.0** with provenance — the contributor explicitly confirms they own it and *donate* it (first-time consent), or links a photo from a recognised source whose licence is validated. Never scraped. Always a real capture: nothing generated or composed by AI or any other artificial means, and nothing added or removed from the scene. Light editing is fine, with AI tools or without: colour toning, cropping, sharpening, noise removal. Marked **[media]**. (Multiple photos per item, plus short video; galleries show as a slideshow.)
- **Closed providers are signposted, not absorbed.** Bike-friendly-stay *schemes* (national cyclist-accommodation networks) are a referral layer that links out — not part of the open Commons. Only data riders contribute (or that's already open) goes in the Commons.

## Contribution methods (legend)
- **[auto]** — derived automatically from aggregate, anonymous signals (a road exists, is rideable, is popular)
- **[tap]** — one-tap rider report in the moment ("water here", "road closed")
- **[edit]** — structured attribute edit (climb metrics, POI details)
- **[safety]** — dynamic/perishable; needs freshness, expiry, and confirm/decay
- **[OSM]** — overlaps OpenStreetMap; sync rather than duplicate
- **[media]** — photo/video, CC BY-SA 4.0, consent-gated (own-it-and-donate, or licence-validated link)

---

Letters are stable identifiers, not an order: practical categories take A-M,
experiential (votable) categories take N-Z, and the ride heatmap is a derived
layer with no letter. The letters are what `?type=`, the coverage tiles and
the public API use.

## A. Road surface & base map  *(the base)* — mostly [OSM]
- Area / region / municipality boundaries [OSM]
- Road & path geometry and names [auto][OSM]
- Cycleways, bike lanes, segregated paths, gravel/MTB trails [OSM]
- Surface type — asphalt / concrete / paving / cobbles / gravel / dirt / sand [edit][OSM]
- Surface smoothness / quality [tap][OSM]
- Path width, barriers, gates, bollards [OSM]
- Lit / unlit at night [OSM]
- One-way / cycling-permitted-against-traffic [OSM]
- Signed cycle routes & node networks — the map can show the signed routes
  (national long-distance routes, regional routes, MTB loops) and the numbered
  junction networks riders navigate by, drawn from OpenStreetMap's route
  relations. The corridors and numbers are OSM's; what we add on top is ours —
  which stretches of a signed route have a recorded surface, its quality where
  someone has said, and a one-tap way to fill the gaps. Surfaces on this map
  are our own recorded answers, not a re-rendering of somebody else's style
  [tap][OSM]

## B. Water & food  *(ride-critical)*
- Drinking water / refill points: fountains, taps, cemeteries, churches [tap][OSM]
  - **Potability is stated, never assumed.** The map says what it knows about each water point and marks what it does not. The rule has two parts:
    - **What the OpenStreetMap tags say** sets the baseline, in three states: `drinking_water=yes`, or `amenity=drinking_water` with nothing said against it, is a drinking tap; `drinking_water=no` is not for drinking; a water point carrying neither is unknown. The coverage tiles carry this tri-state per point (`pipeline/coverage/tiles.py`).
    - **A public register of record** can vouch for a point where one exists. In the Netherlands the RIVM drinking-water register is imported as its own rows under letter B, named in the record panel and shown as *official register* until a rider confirms it on the spot. The registry of providers is described in [How data earns its place](data-priority.md).
    - A rider's one-tap *drinkable?* answer on the drawer proposes a correction; a curator decides, and the symbol changes once it is accepted.
  - **The symbol grammar is shipped.** The map's Key panel and [/map-key](https://cyclingcommons.org/map-key) are the reference:

    | State | Map symbol | Source of truth |
    |---|---|---|
    | **Drinking tap** | a blue drop | mapped as drinking water and nothing says otherwise; confirm on the spot |
    | **Not for drinking** | a drop with a bar across it | tagged not drinkable, or a rider said so |
    | **Tap, nothing said** | an unfilled drop | a water point nobody has said anything about; treat it as unknown |

    Every icon is drawn SVG (`ItemType::svgPath()` and the `KindIcons` registry), never an emoji, so it renders the same in every browser and can carry the badges. The shape says which store the record lives in: a small disc or drop is imported baseline data, a teardrop pin is a record this community keeps. A "?" badge at the top right means no rider has confirmed it yet. Natural mineral springs (the Spa *pouhons*, for one) are labelled as such: potable, but not utility tap water.
- Public toilets — their **own** category, **C** (next); listed here too because a rider looking for water and a rider looking for a toilet are usually the same rider, and the map shows C right after this group [tap][OSM]
- Cyclist-friendly cafés / coffee stops [tap][edit]
- Resupply — shops, supermarkets, bakeries (+ opening hours) [tap][OSM]
- Notable on-route food stops [edit]

## C. Public toilets  *(the stop nobody maps well)*
- Public toilets — municipal blocks, station and park facilities, serviced stops [tap][OSM]
- Free or paid · accessible · opening hours, where they are known [tap][OSM]

Its own letter rather than a line inside **B** because it answers a different
question at a different moment, and folding it into "Water & food" made it
unfindable - a rider looking for a toilet does not think of it as food. It sits
at C, straight after Water & food, which is also where the map displays it.

Sourced from OSM only. The obvious specialist directory for the Netherlands
(HogeNood) is closed and partner-only, so nothing of theirs is copied.

## D. Bike services
- Bike shops (+ hours, brands serviced) [edit][OSM]
- Public repair stations / pumps / tool stands [tap][OSM]
- Emergency / mobile mechanics [edit]
- E-bike charging points: design. The harvest takes three kinds from OpenStreetMap, bike shops, repair stations and pumps (`shop=bicycle`, `amenity=bicycle_repair_station`, `amenity=compressed_air`); no charging kind is harvested.

## E. Hazards & conditions  *(dynamic — needs freshness)*
- Road-surface problems — potholes, broken surface, loose gravel [tap][safety]
- Dangerous junctions, blind corners, bad sightlines [tap][safety]
- Tram tracks, level crossings, cattle grids, slippery surfaces [tap][safety][OSM]
- High-traffic / unsafe roads to avoid for cyclists [tap][safety]
- Private / restricted / no-entry roads — *don't trespass* (Manifesto §IX) [tap][OSM]
- Seasonal closures — cols under snow, flood-prone, seasonal gates [tap][safety][OSM]
- Construction / temporary closures / diversions [tap][safety]
- Aggressive-dog warnings [tap][safety]
- Notorious crosswind / exposed sections [tap]

## F. Getting there & multimodal
- Train stations with bike access; bikes-on-train rules [edit][OSM]
- Ferries cyclists can use (+ bikes-allowed, rough schedule) [edit][OSM]
- Tunnels & bridges a cyclist may use — or must avoid [edit][OSM]
- Bike-share / rental stations [OSM]
- Park-and-ride / trailheads / good ride-start parking [tap][OSM]

## G. Shelter & emergency
- Rain shelters / covered spots / bus stops to wait out weather [tap][OSM]
- Hospitals, pharmacies, first aid [OSM]
- Mountain refuges / huts / emergency phones [OSM]

## N. Climbs, descents & terrain  *(the layer closed databases lock down)*
- Climbs / cols / bergs: start & top points, length, elevation gain [auto][edit]
- Average gradient, max gradient, full gradient profile [auto]
- Climb surface & whether it's paved/gravel [edit]
- Difficulty category (HC/1–4) [auto]
- Named climbs & famous segments [edit]
- Descents: technicality, hairpin count, surface, danger notes [edit][safety]
- Per-area relief / total climbing [auto]

## O. Where to sleep
- Bike-friendly accommodation riders have actually used — B&B, hotel, hostel, campsite [tap][edit][OSM]
- Secure bike storage at a stay [edit]
- Bivouac / shelter / wild-camp spots (where legal) [tap]
- *Signpost layer (links out, not Commons): cyclist-accommodation & hospitality schemes*

## P. Scenic views  *(the photo-stop layer)*
- Viewpoints / panoramas [tap][OSM][media]
- Photo spots — where the shot is [tap][media]
- Best light / time of day [edit]

## Q. History & culture  *(the story layer — stories about the region or its cities)*
- Landmarks & points of interest to ride past [edit][OSM]
- Local stories & history (community text) [edit]
- Municipality facts / public-domain coats of arms [edit]
- Cycling-heritage sites — famous finish lines, velodromes, monuments [edit]

## R. Recommended routes  *(rides riders vouch for)*
- A route a rider proposes: a drawn or GPX-uploaded line, with its distance and climbing measured [edit]
- Start point, loop or point-to-point, difficulty, the season it is best ridden in [edit]
- *I rode this* confirmations from other riders, which are what verify a route [tap]
- A photo of the ride [media]

R is a route layer, not a per-road rating. What a road is like (quietness,
smoothness, surface, lit or not, seasonal closure) is recorded on the road
itself, under **A**. Best-of rankings of routes are derived from riders, never
hand-picked; the vote mechanics are design, see
[How data earns its place](data-priority.md).

## Ride heatmap — derived & aggregate  *(auto, anonymized — never per-rider)*
What renders today: the map's heat layer draws from `heat_point`, which the
catalog importer seeds from a committed demo file. Nothing is ingested from
rider uploads; the engine below is design, and the list here is what it is
meant to produce.

- Road popularity / "is this actually used" heatmap (aggregate) [auto]
- Rideability inference — e.g. is this gravel OK on a road bike (from aggregate use) [auto]
- Under-explored areas (shows where the map is thin) [auto]
- Coverage & freshness per area [auto]
- **Seasonal route shift** — how the popular lines move spring → summer → autumn → winter [auto]

### Optional engine: a seasonal ride-heatmap *(design, not built)*
Riders can upload their rides; the Commons keeps the **lines, never the riders**. The heatmap is the
real engine behind road-popularity (and could suggest popular loops), and stays manifesto-safe **only if
anonymization happens at ingest, not in storage**:

- **Map-match then discard.** On upload, snap the trace to OSM road segments, increment per-segment
  counters, and **throw the raw trace away.** Never store a per-rider polyline.
- **Privacy zones.** Drop the first/last ~200 m of every trace (kills home/work fingerprints).
- **k-anonymity.** Publish a segment's popularity only once **≥ N distinct riders** have used it.
- **Coarse seasonal buckets.** Per-segment counts per season — enough to see routes shift across the
  year, with no per-ride timeline that could re-identify anyone.
- **Seed from open data.** OSM's public GPS traces are already openly contributed — the heatmap can
  start from those before any first-party uploads exist.

Result: a purely aggregate layer, publishable openly (ODbL), holding **the map, not the rider**
(Manifesto §I). This is the *measured* side of routes; the rider-verified side is **R**: *I rode
this* confirmations on the map drawer (seasonal recommend-votes are design).

---

## The boundary — what is NOT in the Commons
- Personal data: a person's identity, history, fitness metrics, and movements.
- Anything that re-identifies an individual.
- Closed partners' proprietary data (their exact host addresses) — signposted, never copied.
These are never collected (Manifesto §I) or are external. The Commons holds the *world*, not the people in it.

## Notes for product / schema
- Most of A–G, N and O, and parts of P, have **OSM tags already** — design the Commons to *interoperate with* OSM (import + contribute back under ODbL), so you inherit a huge head start and avoid duplicating the base map.
- **[safety]** items need a freshness model: timestamp, confirmations, and decay/expiry, or the map rots.
- The Commons's defensible curation: **climbs (N), bike-friendly stays riders vouch for (O), quality rides / cyclist-experience attributes (R), and live conditions reported by riders (E)** — the layers OSM is thin on and that closed, single-app data leaves out.

## Freshness model (for the [safety] / dynamic layers)
A "road closed" or "pothole" report that never expires becomes a lie. Perishable items need a lifecycle, or the map rots.

What runs today is narrower than the model below, and the difference is
marked per item. A hazard reported as *Road closed* carries the reporter's own
answer to "closed for how long?" and stops being shown once that window
passes, unless somebody confirms it is still shut (`app:catalog:expire-closures`).
Nothing else auto-expires: a hazard with no stated end date needs a human to
clear it. The one-tap confirm is built for every place.

- **Timestamp + reporter count** on every report: when, and how many independent riders.
- **Confidence from confirmations.** 1 report = *unconfirmed*; several independent ones = *confirmed*. Show the state, don't hide it.
- **Decay / expiry by type** (design). Each hazard has a half-life: a pothole persists for months, "closed for an event" expires in days, "loose gravel" fades over weeks. After expiry it's hidden (not deleted) pending re-confirmation. Only the closure window exists today.
- **Auto-clear from aggregate use** (design). If riders keep passing through a spot flagged "closed," that's evidence it reopened: [auto] data downgrades a stale [tap] report.
- **One-tap confirm / dispute.** A rider passing a flagged spot gets a light "still there? yes / gone" prompt that feeds confidence. **Built for every place, not only hazards:** the drawer asks the letter's own question, *drinkable?* for water, *still here?* for the rest, and offers the three ways a place stops being true beside it: **out of order**, **closed**, **not there anymore**. Out of order is offered only where something can break; a viewpoint cannot. Each writes the same field the edit form offers, so a one-tap answer and a typed correction are one record rather than two, and each goes through the ordinary review queue: a tap proposes, a curator decides. A place confirmed gone leaves the map and does **not** reappear from the OpenStreetMap layer underneath it.
- **Provenance kept, identity not.** Store *that* N riders confirmed and *when* — never *who* — in the public Commons.

This turns perishable reports into a self-healing layer instead of an ever-growing pile of stale warnings.

## Access: the map is one view; the data is queryable
The Commons is **open data, not a walled map.** Every layer is reachable two ways today, with a third designed:
- **Map**: [/map](https://cyclingcommons.org/map), with toggleable layers.
- **Query API**: `/v1/search`, filtered by **type + bounding box**, like OSM Overpass: "all drinking-water points in this bbox," "climbs in this bbox." A bbox is required and capped in size. Country and region as API filters are design, not built; today "per country" is a map scope and a region page.
- **Bulk export** (design, not built): per-country / per-region open dumps for anyone to download and build on (ODbL).

The intent is that "per country" is a first-class query, not a map-only view: that is what makes it a commons rather than a feature.

---

# Sub-items & attributes — what a contributor actually fills in

The catalog above is the *categories*. This is the depth underneath each one — the fields people
think of when they tag something. Not every field is required (most are optional); the point is to
show how rich each item can get. **[OSM]** = an established OSM tag exists for it.

## A. Map & surface — per road/segment
Road type (road / cycleway / gravel path / singletrack) [OSM] · surface material (asphalt · concrete
· cobbles/setts · gravel · compacted · dirt · sand · grass) [OSM] · smoothness (excellent → impassable)
[OSM] · width [OSM] · segregated vs shared · lit / unlit [OSM] · typical traffic level · barriers
(gate · bollard · steps · ford · stile) [OSM] · one-way / contraflow allowed [OSM] · seasonal access.

## B. Water & food — per point
**Water:** source type (fountain · public tap · cemetery tap · church · spring) · potable? · seasonal (frost
shut-off) · reliability. **Café:** cyclist-friendly? · open days & hours [OSM] · weekly closing day · indoor bike
parking · outdoor seating · card/cash · coffee-stop reputation. **Shop/bakery:** type · hours · open Sunday? · what
they stock. (Toilets are their own category, **C**, next.)

## C. Public toilets — per point
Public · free or paid · accessible · opening hours [OSM]. Their own category, so the map can show them as
their own thing.

## D. Bike services — per point
**Shop:** brands serviced · repairs? · rental? · e-bike service? · hours [OSM]. **Repair station:** pump + valve
type (presta/schrader) · tools available · chain tool · work stand · 24/7? [OSM]. **Charging** (design): connector ·
free/paid · location. **Vending:** tubes / CO2 / spares.

## E. Hazards & conditions — per report  *(all [safety])*
Type (pothole · loose gravel · dangerous junction · blind corner · tram tracks · level/cattle crossing · road
closure · construction · flooding · ice · aggressive dog · notorious crosswind) · severity · **date observed /
last confirmed** · temporary vs permanent · seasonal window · direction affected · suggested detour · confirm/decay state.

## F. Getting there — per node
**Station:** bikes allowed (always / off-peak / reservation) · bike spaces · lifts/ramps · bike ticket needed? [OSM].
**Ferry:** bikes allowed · schedule · seasonal · cost · crossing time [OSM]. **Tunnel/bridge:** cyclists allowed? ·
shuttle? · alternative. **Parking:** free/paid · size · surface · security [OSM]. **Bike-share:** docks · type [OSM].

## G. Shelter & emergency — per point
**Shelter:** covered? · type (bus stop · hut · church porch · barn) · seating. **Refuge/hut:** staffed? · water ·
food · sleeping · open season [OSM]. **Medical:** hospital · pharmacy · first aid · hours [OSM]. **Emergency:** SOS
phone / rescue point · what3words-style locator.

## N. Climbs — the worked example
Name (+ local & alternate names) · start point and top (coords, town) · which side / approach · length ·
elevation gain · **average gradient** · **max gradient (and where)** · gradient profile / the steep ramps ·
number of hairpins · surface (paved · gravel · cobbles) · difficulty category (HC/1–4) ·
exposed vs sheltered (wind/sun) · shade / tree cover · traffic on the climb · **water/fountain on the climb** ·
where it tops out (viewpoint, café, pass sign) · famous-for / history (Tour, Classics) · segment/KOM reference ·
best season & when it's *open* (snow gates) · descent notes (technicality, surface, danger) [safety] · photo spot.

## O. Stays — per place
Type (B&B · hotel · hostel · campsite · refuge) [OSM] · **secure bike storage** · bike wash · tools/workshop ·
drying room · early/packed breakfast · price band · scheme (cyclist-accommodation network · independent) ·
booking link / contact · open season · minimum nights · cyclist-rated.

## P. Scenic views — per POI
**Viewpoint:** what you see · best light / time of day · access [OSM][media]. **Photo spot:** best angle, time.

## Q. History & culture — per POI
**Landmark:** type · era · one-line story. **Local story:** short text + source. **Municipality:** public-domain
coat of arms · a fact or two. **Cycling heritage:** Tour/Classics history · velodrome · memorial · famous finish line.

## R. Recommended routes: per route
Name · the line (drawn or GPX) · start · loop or point-to-point · distance · climbing · elevation profile ·
difficulty · best season · a photo · who proposed it · *I rode this* count · state (unverified → verified).
Road qualities (quietness, smoothness, surface) are per road under **A**, never per route.

## Derived & aggregate — computed, not entered  *(all [auto])*
Road popularity score · rideability inference (is this gravel OK on a road bike?) · under-explored areas · per-area
coverage % and data freshness · confidence/age of each [safety] item. These are *outputs* of the Commons, not things
a person fills in — but they're published openly too.

---

*Design note:* keep every field **optional and additive** — a rider adds one fact (a water tap, a gradient, a
"closed" flag) and everything else can stay blank. Adding takes a form; confirming an existing entry does not.
The map grows from many small contributions, OSM-style. Required fields kill contribution; optional fields let
the Commons grow one tap at a time.

---

# Giving back to OpenStreetMap  *(Phase 2 feature)*

Today the website *uses* OSM tiles (consumes OSM). "Gives back to" is a **committed intention, not yet
built.** The **data** is **ODbL — the same licence OSM uses** — so facts can flow back legally by
construction. Two things travel differently and are worth stating in the same breath: **media** is
CC BY-SA 4.0 (a creative-works licence, and photographs do not belong in OSM anyway), and the
**software** is source-available under PolyForm Shield, which governs the platform's code and has no
bearing on either direction the data moves. The flow,
lightest to heaviest:

- **Rider hand-off (being designed).** When a rider adds a fact OSM is missing (water tap, repair
  station, wrong surface), the intention is to offer to carry it over — **posted as their own OSM edit**,
  never as a bulk feed from a project account, which is the kind of contribution OSM reverts and
  remembers. What that offer says, and what it sends, is deliberately **not settled yet**: a give-back is
  only worth building once it is well-formed at the other end.

    Three things have to be answered first, and they are the whole difficulty:

    1. **Which fields even map.** `surface` and `smoothness` translate directly. Our `traffic` field
       does not exist in OSM at all, and a rider's note maps to nothing. Posting our vocabulary into
       OSM tags would be worse than posting nothing.
    2. **Whose edit it is.** OSM attributes an edit to an account, so it has to be the rider's, with
       their consent, in their words.
    3. **What comes back.** Nothing automatic. OSM changes reach us through the ordinary refresh of our
       reference data, which is the only path that keeps provenance honest.

    Prior art exists — RideWithGPS ships a contribute-to-OSM flow — and reading it carefully beats
    guessing at ours.
- **Curated submission.** Curators review verified facts and submit them properly, with sources, human-in-the-loop.
- **Formal import (later, heavy).** Bulk contribution needs an OSM *import plan*, community discussion, an
  ODbL-compatible source, and a dedicated account — strict rules; bad imports get reverted. Not a day-one move.

**Flows back → OSM:** durable infrastructure facts (the **[OSM]**-tagged items — water, repair stations, bike
shops, surface, cycleways, barriers).
**Stays in the Commons (not pushed to OSM):** the experience layers apps build on top, subjective ratings (R), ephemeral **[safety]** hazards
(OSM doesn't want "pothole reported yesterday"), and anything personal. OSM wants lasting facts, not the game or the weather.

**Etiquette:** follow OSM's Import & Automated-Edit guidelines — human-reviewed, attributed, never a firehose —
or you get reverted and resented. Slow and clean beats fast and bulk.

**Metric:** track *facts contributed back to OpenStreetMap by Commons contributors* — it proves the
give-back is real and makes a genuinely good community story.
