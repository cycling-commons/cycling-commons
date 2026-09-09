<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# The Commons — Data Catalog

Everything a rider can contribute to the open cycling Commons — climbs, water, stays, hazards,
viewpoints, and more. All of it is **community-contributed**, useful to **anyone**, and
**non-personal** (about the *world*, never a person's identity). Never scraped; contributed by riders — reports, edits, and aggregate, anonymous signals.

## Principles
- **Build on OSM, don't reinvent it.** Items tagged **[OSM]** already have a home in OpenStreetMap (ODbL). Sync with / contribute back to OSM for those; layer cycling-specific curation on top. the Commons's added value is *curation, normalization, freshness, and the cycling lens* — not re-collecting the base map.
- **Non-personal only.** No identity, no tracking, nothing personal. The Commons is the map, not the rider.
- **Every body rides (Manifesto §II).** The facts an adapted bike, a handbike, a cargo bike or a rider who needs a calm, legible road depends on are data, not an afterthought: the clearance width of a barrier, the traffic a road carries, how many decisions a route asks per kilometre, whether it follows signed routes. They are collected under **A** and derived under **R**.
- **Media is a first-class, consent-gated contribution.** Photos and video join the Commons under **CC BY-SA 4.0** with provenance — the contributor explicitly confirms they own it and *donate* it (first-time consent), or links a photo from a recognised source whose licence is validated. Never scraped. Always a real capture: nothing generated or composed by AI or any other artificial means, and nothing added to or removed from the scene, with one exclusion: faces and number plates are blurred before upload. Light editing is fine, with AI tools or without: colour toning, cropping, sharpening, noise removal.
- **Providers are partners, on their licence.** A scheme or register that publishes under an open licence enters as its own rows, cited as theirs, the way the RIVM drinking-water register does (**[provider]**). One that is closed is signposted, a link out and no copy, until it agrees. What riders add on top of a provider's row is offered back to the provider where they can take it.

## Contribution methods (legend)
- **[auto]** — derived automatically from aggregate, anonymous signals (a road exists, is rideable, is popular)
- **[tap]** — a one-tap answer on a pin that is already on the map, from its drawer: *still here?*, *drinkable?* on water, *I rode this* on a route. A tap never adds a point
- **[edit]** — entered or changed through the one improve form per category: a pin placed on the map, then that category's fields. Every new point arrives this way, and a curator approves it before it shows
- **[safety]** — dynamic/perishable; needs freshness, expiry, and confirm/decay
- **[OSM]** — overlaps OpenStreetMap; sync rather than duplicate
- **[provider]** — imported from an open data provider of record, named in the provider registry and attributed on every row it gave (source `authority`). For example: the RIVM drinking-water register in the Netherlands, Tourisme Wallonie PIVOT for stays in Belgium
- **[media]** — photo/video, CC BY-SA 4.0, consent-gated (own-it-and-donate, or licence-validated link). For example: three photos of a col and a short clip of the descent, shown on the item as a slideshow

---

Letters are stable identifiers, not an order: practical categories take A-M,
experiential (votable) categories take N-Z, and the ride heatmap is a derived
layer with no letter. A rider never sees a letter: wherever the site names a
category it shows the category's icon and name, on the map's layer list, the
drawer, search results and the contribution forms alike. The letters live in
`?type=`, the coverage tiles, `coverage_poi.letter` and the public API, and
on this page, where they name a section.

## A. Road surface & base map  *(the base)* — mostly [OSM]
- Area / region / municipality boundaries [OSM]
- Road & path geometry and names [auto][OSM]
- Cycleways, bike lanes, segregated paths, gravel/MTB trails [OSM]
- Surface type — asphalt / concrete / paving / cobbles / gravel / dirt / sand [tap][edit][OSM]
- Surface smoothness / quality [tap][edit][OSM]
- Path width [OSM] *(not harvested; OSM has it on few paths. Useful if it comes, not for now)*
- Barriers on the way, chicanes (single, double, triple, squeeze), A-frames, kissing gates, bollards and blocks, with the **type and the clearance width in centimetres**. The width is the fact, the type is the hint: a double chicane at 150 cm passes a trailer, one at 90 cm stops a handbike. OpenStreetMap has the model (`barrier=cycle_barrier` + `cycle_barrier=*`, `barrier=bollard|kissing_gate|gate|swing_gate|block`, `maxwidth:physical`, `opening`, `spacing`, `bicycle=*`, `wheelchair=*`) and, for most bollards, no width; a rider measures where OSM is silent, in centimetres against a known reference (road bars about 42 cm, a cargo bike about 65 cm, a trailer about 80 cm). Harvested only from cycleways, paths and tracks, never from car roads, or every bollard in Europe lands on the map [tap][edit][OSM] *(harvest and form: design, not built)*
- Traffic exposure: derived from road class, speed limit, lanes and cycleway, and quietness as Scout detects it from rides; the input for a route's sensory load [auto][OSM] *(being built)*. Riders can be asked too, but only as a tally, ten say quiet and two say moderate, never one voice, and always with the time of day attached: a road is quiet at seven and not at five *(not built)* [tap]
- Lit / unlit at night [OSM]
- One-way / cycling-permitted-against-traffic [OSM] *(on the roadmap, not built)*
- Signed cycle routes & node networks: the signed routes and numbered junction
  networks riders navigate by, drawn from OpenStreetMap's route relations. The
  corridors and numbers are OSM's. Ours is the surface along them: OSM's tag
  where it exists, a rider's answer where someone has said, nothing where
  nobody has, and a one-tap way to fill the gaps [tap][edit][OSM]

## B. Water & food  *(ride-critical)*
- Drinking water / refill points: fountains, taps, cemeteries, churches [tap][edit][OSM][provider]
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
- Resupply — shops, supermarkets, bakeries (+ opening hours) [tap][edit][OSM]
- Notable on-route food stops [tap][edit]

## C. Public toilets  *(mapped, but rarely with hours, fee or access)*
- Public toilets — municipal blocks, station and park facilities, serviced stops [tap][edit][OSM]
- Free or paid · accessible · opening hours, where they are known [edit][OSM]

Its own letter rather than a line inside **B** because it answers a different
question at a different moment, and folding it into "Water & food" made it
unfindable - a rider looking for a toilet does not think of it as food. It sits
at C, straight after Water & food, which is also where the map displays it.

Sourced from OSM today. Municipal toilet datasets that publish openly, and
there are dozens, can enter as **[provider]** rows the way the RIVM taps do,
one adapter per city rather than a decision per city *(not built)*. HogeNood,
the Dutch specialist directory, is closed and partner-only, so it is a link out
and no copy until it agrees; the British toilet map has no clear licence, so it
is asked first.

## D. Bike services
- Bike shops (+ hours, brands serviced) [tap][edit][OSM]
- Public repair stations / pumps / tool stands [tap][edit][OSM]
- Emergency / mobile mechanics — a service that comes to you, not a place on a map; whether it belongs here at all is undecided *(not built)*
- E-bike charging points: design. The harvest takes three kinds from OpenStreetMap, bike shops, repair stations and pumps (`shop=bicycle`, `amenity=bicycle_repair_station`, `amenity=compressed_air`); no charging kind is harvested.

## E. Hazards & conditions  *(dynamic — needs freshness)*
A hazard is what a rider at speed cannot see in time: a pothole in the shade
of a descent, a road that is closed round the corner, ice on a north-facing
bend. It is a point a rider can stand at, that will not be there for ever,
that another rider can check, and that names no person. What a rider can
plainly see, tram tracks, a cattle grid, cobbles, is not a hazard and is not
recorded as one. The Commons serves long rides, and a route that warns of
fifty places warns of none; the list below is deliberately short and stays
short (owner 2026-09-09).

The point of a hazard is to be known before the ride. The **ride check** does
that today: upload a GPX and every hazard on file along the line comes back in
riding order, with the rest of what the corridor holds; the file is read and
discarded, nothing is stored. The same for a route drawn in the Commons, rather
than uploaded, is not built yet.

- Road-surface problems: potholes, broken surface, ice, and gravel or sand washed onto a paved bend, the common trigger on a descent [tap][edit][safety]
- Seasonal closures: cols under snow, flood-prone dips, snow gates; the closure lifetime and its expiry are built for these [tap][edit][safety][OSM]
- Construction / temporary closures / diversions, with the detour [tap][edit][safety]
- One spot a rider can pin: a bend where you cannot see what is coming, or a busy road you must cross in one go with no island in the middle. The form calls these *bad corner* and *junction / crossing*, never *dangerous*: one rider's fright is not a fact [tap][edit][safety]
- Crosswind / fog on an exposed stretch: real on a dam or a col; a stretch, not a pin, so it reads as a quality of the road [tap][edit]

## F. Getting there & multimodal
- Train stations with bike access; bikes-on-train rules [tap][edit][OSM]
- Ferries cyclists can use (+ bikes-allowed, rough schedule) [tap][edit][OSM]
- Tunnels & bridges a cyclist may use — or must avoid [tap][edit][OSM]
- Bike-share / rental stations [OSM]
- Park-and-ride / trailheads / good ride-start parking [tap][edit][OSM]

## G. Shelter & emergency
- Rain shelters / covered spots / bus stops to wait out weather [tap][edit][OSM]
- Hospitals, pharmacies, first aid [OSM]
- Mountain refuges / huts / emergency phones [OSM]

## N. Climbs, descents & terrain  *(the layer closed databases lock down)*
- Climbs / cols / bergs: start & top points, length, elevation gain [auto][tap][edit]
- Average gradient, max gradient, full gradient profile [auto]
- Climb surface & whether it's paved/gravel [edit]
- Difficulty category (HC/1–4) [auto]
- Named climbs & famous segments [edit]
- Descents: technicality, hairpin count, surface [edit]. Gravel or sand on a paved bend is not a note on the climb: it is a hazard pinned on that bend under **E**, so the ride check can warn about it [safety]
- Per-area relief: one number per region for how hilly it is, metres climbed per kilometre ridden, from the same elevation source the climbs use. It belongs at the top of the region page, which today counts area, items and routes and says nothing about the riding; the region portrait that would carry it is on the roadmap *(not built)* [auto]

## O. Where to sleep
- Bike-friendly accommodation riders have actually used — B&B, hotel, hostel, campsite [tap][edit][OSM][provider]
- Secure bike storage at a stay [edit]
- Bivouac / shelter / wild-camp spots (where legal) [tap][edit]
- Cyclist-accommodation schemes, the same way as the RIVM taps (owner 2026-09-09): a scheme that publishes openly, Accueil Vélo in France under Licence Ouverte, enters as **[provider]** rows, each cited as the scheme's and updated by the harvest; a closed scheme, Bett+Bike in Germany or Vrienden op de Fiets in the Netherlands, is a signpost, a link out and no copy, until it agrees. On top of a provider's row riders add what the scheme does not hold: *still here?* and *I stayed here* confirmations, photos, a written note, and a rating once the vote ships. Where the scheme can take it, that is offered back to them under its licence, the same shape as the OSM give-back below *(open schemes: design, not built; closed schemes: ask first)* [provider][tap][edit][media]

## P. Scenic views  *(the photo-stop layer)*
- Viewpoints / panoramas [tap][edit][OSM][media]
- Photo spots — where the shot is [tap][edit][media]
- Best light / time of day [edit]

## Q. History & culture  *(the story layer — stories about the region or its cities)*
- Landmarks & points of interest to ride past [tap][edit][OSM]
- Local stories & history (community text) [edit]
- Municipality facts / public-domain coats of arms [edit]
- Cycling-heritage sites — famous finish lines, velodromes, monuments [tap][edit]

## R. Recommended routes  *(rides riders vouch for)*
- A route a rider proposes: a drawn or GPX-uploaded line, with its distance and climbing measured [edit]
- Start point, loop or point-to-point, difficulty, the season it is best ridden in [edit]
- *I rode this* confirmations from other riders, which are what verify a route [tap]
- A photo of the ride [media]
- What lies along the route, shown on the route itself without asking: the ride check run over the stored line, so a recommended route opens with its water, coffee stops, toilets, shelters, hazards and surface changes in riding order. The corridor query exists for uploaded files; pointing it at a stored route is the missing step *(not built)* [auto]
- What the route asks of a body, derived along the line from **A** and the geometry we already measure *(design, not built)*: the **narrowest passage** in centimetres and where it is; **turn density**, decisions per kilometre; the share of the line on **signed routes**, from OSM route relations; **traffic exposure** per kilometre, worst stretch named. Predictability, legibility and sensory load are stable qualities of a route, not hazards that perish, which is why they are here and not under **E** [auto]
- Whether a road is quiet is detected first, not asked: Scout, the ride-file tool, derives it from the ride itself, and the answer lands on the road under **A** as an aggregate fact, no trace kept *(being built)* [auto]
- Categories a route can carry, chosen by the proposer and confirmed by the vote: cultural, architecture, scenic, quiet, and the like; a rider filters the region's best by the kind of ride they want *(not built)* [edit][tap]

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
[OSM] · width [OSM] · segregated vs shared · lit / unlit [OSM] · traffic exposure (derived) · barriers
(chicane single/double/triple/squeeze · A-frame · kissing gate · bollard · block · steps · ford · stile) with
**clearance width in cm** and its source (OSM `maxwidth:physical` / `opening` / `spacing`, or measured by a
rider) [OSM] · one-way / contraflow allowed [OSM] · seasonal access.

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
Type, as the form offers it (crosswind / fog · ice / frost · loose surface / gravel · potholes · junction /
crossing · bad corner · flooding · roadworks · road closed · other) · severity · when it is worst · **date
observed / last confirmed** · if closed, for how long · direction affected · suggested detour · confirm/decay state.

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
difficulty · best season · categories (cultural · architecture · scenic · quiet · …, *design*) · a photo · who proposed it · *I rode this* count · state (unverified → verified) ·
derived along the line *(design)*: what lies along it in riding order (water · coffee · toilets · shelter · hazards · surface changes) · narrowest passage (cm, and where) · turns per km · share on signed routes ·
traffic exposure per km and its worst stretch · quiet stretches, as Scout detects them.
Road qualities (quietness, smoothness, surface, barriers) are per road under **A**, never per route; a route
only sums them up.

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
