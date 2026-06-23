# The Commons — Data Catalog

Everything a rider can contribute to the open cycling Commons — climbs, water, stays, hazards,
viewpoints, and more. All of it is **community-contributed**, useful to **anyone**, and
**non-personal** (about the *world*, never a person's identity). Never scraped; contributed by riders — reports, edits, and aggregate, anonymous signals.

## Principles
- **Build on OSM, don't reinvent it.** Items tagged **[OSM]** already have a home in OpenStreetMap (ODbL). Sync with / contribute back to OSM for those; layer cycling-specific curation on top. the Commons's added value is *curation, normalization, freshness, and the cycling lens* — not re-collecting the base map.
- **Non-personal only.** No identity, no tracking, nothing personal. The Commons is the map, not the rider.
- **Media is a first-class, consent-gated contribution.** Photos and video join the Commons under **CC BY-SA 4.0** with provenance — the contributor explicitly confirms they own it and *donate* it (first-time consent), or links a photo from a recognised source whose licence is validated. Never scraped. Marked **[media]**. (Multiple photos per item, plus short video; galleries show as a slideshow.)
- **Closed providers are signposted, not absorbed.** Bike-friendly-stay *schemes* (national cyclist-accommodation networks) are a referral layer that links out — not part of the open Commons. Only data riders contribute (or that's already open) goes in the Commons.

## Contribution methods (legend)
- **[auto]** — derived automatically from aggregate, anonymous signals (a road exists, is rideable, is popular)
- **[tap]** — one-tap rider report in the moment ("water here", "road closed")
- **[edit]** — structured attribute edit (climb metrics, POI details)
- **[safety]** — dynamic/perishable; needs freshness, expiry, and confirm/decay
- **[OSM]** — overlaps OpenStreetMap; sync rather than duplicate
- **[media]** — photo/video, CC BY-SA 4.0, consent-gated (own-it-and-donate, or licence-validated link)

---

## A. Road surface & base map  *(the base)* — mostly [OSM]
- Area / region / municipality boundaries [OSM]
- Road & path geometry and names [auto][OSM]
- Cycleways, bike lanes, segregated paths, gravel/MTB trails [OSM]
- Surface type — asphalt / concrete / paving / cobbles / gravel / dirt / sand [edit][OSM]
- Surface smoothness / quality [tap][OSM]
- Path width, barriers, gates, bollards [OSM]
- Lit / unlit at night [OSM]
- One-way / cycling-permitted-against-traffic [OSM]

## B. Climbs, descents & terrain  *(the layer closed databases lock down)*
- Climbs / cols / bergs: start & top points, length, elevation gain [auto][edit]
- Average gradient, max gradient, full gradient profile [auto]
- Climb surface & whether it's paved/gravel [edit]
- Difficulty category (HC/1–4) [auto]
- Named climbs & famous segments [edit]
- Descents: technicality, hairpin count, surface, danger notes [edit][safety]
- Per-area relief / total climbing [auto]

## C. Water & food  *(ride-critical)*
- Drinking water / refill points — fountains, taps, cemeteries, churches [tap][OSM]
  - **Potability is verified, not assumed.** OSM `amenity=drinking_water` is frequently *not* confirmed potable, so a point is only shown as drinking water once cross-checked against the regional utility: **SWDE** ([swde.be](https://www.swde.be), distribution-zone open data on the [Géoportail de la Wallonie](https://geoportail.wallonie.be)) in Wallonia, **De Watergroep** ([drinkwatertappunten](https://www.dewatergroep.be/nl-be/drinkwater/extra-services/drinkwatertappunten)) in Flanders. Natural mineral springs (e.g. the Spa *pouhons*) are labelled as such — potable, but not utility tap water. eaupotable.info ([be-belgie](https://eaupotable.info/nl/be-belgie)) is an OSM-based public-fountain directory usable as a cross-reference.
  - **Verification state drives the map symbol** (planned implementation; the demo only previews it with sample data):

    | State | Map symbol | Source of truth |
    |---|---|---|
    | **Verified potable** | curated **icon** (💧 marker) | confirmed against the utility (SWDE / De Watergroep) or by a steward; mineral springs flagged as such |
    | **Unverified** | small **dot** | raw OSM `drinking_water=*`, shown but labelled *"verify locally"* |

    A point starts as a dot (imported from OSM) and is **promoted to an icon** once a verification step (utility cross-check or steward confirmation) passes. Demotion/expiry follows the freshness rules. The demo fakes the verification, but the dot-vs-icon distinction is the real intended UX.
- Public toilets [tap][OSM]
- Cyclist-friendly cafés / coffee stops [tap][edit]
- Resupply — shops, supermarkets, bakeries (+ opening hours) [tap][OSM]
- Notable on-route food stops [edit]

## D. Bike services
- Bike shops (+ hours, brands serviced) [edit][OSM]
- Public repair stations / pumps / tool stands [tap][OSM]
- E-bike charging points [tap][OSM]
- Emergency / mobile mechanics [edit]

## E. Where to sleep
- Bike-friendly accommodation riders have actually used — B&B, hotel, hostel, campsite [tap][edit][OSM]
- Secure bike storage at a stay [edit]
- Bivouac / shelter / wild-camp spots (where legal) [tap]
- *Signpost layer (links out, not Commons): cyclist-accommodation & hospitality schemes*

## F. Hazards & conditions  *(dynamic — needs freshness)*
- Road-surface problems — potholes, broken surface, loose gravel [tap][safety]
- Dangerous junctions, blind corners, bad sightlines [tap][safety]
- Tram tracks, level crossings, cattle grids, slippery surfaces [tap][safety][OSM]
- High-traffic / unsafe roads to avoid for cyclists [tap][safety]
- Private / restricted / no-entry roads — *don't trespass* (Manifesto §IX) [tap][OSM]
- Seasonal closures — cols under snow, flood-prone, seasonal gates [tap][safety][OSM]
- Construction / temporary closures / diversions [tap][safety]
- Aggressive-dog warnings [tap][safety]
- Notorious crosswind / exposed sections [tap]

## G. Getting there & multimodal
- Train stations with bike access; bikes-on-train rules [edit][OSM]
- Ferries cyclists can use (+ bikes-allowed, rough schedule) [edit][OSM]
- Tunnels & bridges a cyclist may use — or must avoid [edit][OSM]
- Bike-share / rental stations [OSM]
- Park-and-ride / trailheads / good ride-start parking [tap][OSM]

## H. Shelter & emergency
- Rain shelters / covered spots / bus stops to wait out weather [tap][OSM]
- Hospitals, pharmacies, first aid [OSM]
- Mountain refuges / huts / emergency phones [OSM]

## I. Scenic views  *(the photo-stop layer)*
- Viewpoints / panoramas [tap][OSM][media]
- Photo spots — where the shot is [tap][media]
- Best light / time of day [edit]

## J. History & culture  *(the story layer — stories about the region or its cities)*
- Landmarks & points of interest to ride past [edit][OSM]
- Local stories & history (community text) [edit]
- Municipality facts / public-domain coats of arms [edit]
- Cycling-heritage sites — famous finish lines, velodromes, monuments [edit]

## K. Quality rides — cyclist-experience attributes  *(ratings / suitability)*
- Quietness / traffic level of a road [auto][tap]
- Scenic rating [tap]
- Overall cycling-friendliness [tap]
- Suitability by bike type — road / gravel / MTB / e-bike [edit]
- Accessibility — adapted-bike / handbike friendly, gradient-limited (Manifesto §X) [edit]
- Best direction to ride a loop or climb [edit]

## L. Ride heatmap — derived & aggregate  *(auto, anonymized — never per-rider)*
- Road popularity / "is this actually used" heatmap (aggregate) [auto]
- Rideability inference — e.g. is this gravel OK on a road bike (from aggregate use) [auto]
- Under-explored areas (shows where the map is thin) [auto]
- Coverage & freshness per area [auto]
- **Seasonal route shift** — how the popular lines move spring → summer → autumn → winter [auto]

### Optional engine: a seasonal ride-heatmap *(design — not yet built)*
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
(Manifesto §IV). This is the *measured* side of routes; the rider-rated/voted side is **K**.

---

## The boundary — what is NOT in the Commons
- Personal data: a person's identity, history, fitness metrics, and movements.
- Anything that re-identifies an individual.
- Closed partners' proprietary data (their exact host addresses) — signposted, never copied.
These are never collected (Manifesto §IV) or are external. The Commons holds the *world*, not the people in it.

## Notes for product / schema
- Most of A–H and parts of I have **OSM tags already** — design the Commons to *interoperate with* OSM (import + contribute back under ODbL), so you inherit a huge head start and avoid duplicating the base map.
- **[safety]** items need a freshness model: timestamp, confirmations, and decay/expiry, or the map rots.
- The Commons's defensible curation: **climbs (B), bike-friendly stays riders vouch for (E), quality rides / cyclist-experience attributes (K), and live conditions reported by riders (F)** — the layers OSM is thin on and that closed, single-app data leaves out.

## Freshness model (for the [safety] / dynamic layers)
A "road closed" or "pothole" report that never expires becomes a lie. Perishable items need a lifecycle, or the map rots:
- **Timestamp + reporter count** on every report — when, and how many independent riders.
- **Confidence from confirmations.** 1 report = *unconfirmed*; several independent ones = *confirmed*. Show the state, don't hide it.
- **Decay / expiry by type.** Each hazard has a half-life: a pothole persists for months, "closed for an event" expires in days, "loose gravel" fades over weeks. After expiry it's hidden (not deleted) pending re-confirmation.
- **Auto-clear from aggregate use.** If riders keep passing through a spot flagged "closed," that's evidence it reopened — [auto] data downgrades a stale [tap] report.
- **One-tap confirm / dispute.** A rider passing a flagged spot gets a light "still there? yes / gone" prompt that feeds confidence.
- **Provenance kept, identity not.** Store *that* N riders confirmed and *when* — never *who* — in the public Commons.

This turns perishable reports into a self-healing layer instead of an ever-growing pile of stale warnings.

## Access: the map is one view; the data is queryable
The Commons is **open data, not a walled map.** Every layer is reachable three ways:
- **Map** — render with toggleable layers (the /commons demo).
- **Query API** — filter by **type + area** (country / region / bounding box), like OSM Overpass: "all drinking-water points in France," "climbs in Wallonia," "hazards in this bbox."
- **Bulk export** — per-country / per-region open dumps for anyone to download and build on (ODbL).

So "per country" is a first-class query, not a map-only view — that's what makes it a commons rather than a feature.

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

## B. Climbs — the worked example
Name (+ local & alternate names) · start point and top (coords, town) · which side / approach · length ·
elevation gain · **average gradient** · **max gradient (and where)** · gradient profile / the steep ramps ·
number of hairpins · surface (paved · gravel · cobbles) · difficulty category (HC/1–4) ·
exposed vs sheltered (wind/sun) · shade / tree cover · traffic on the climb · **water/fountain on the climb** ·
where it tops out (viewpoint, café, pass sign) · famous-for / history (Tour, Classics) · segment/KOM reference ·
best season & when it's *open* (snow gates) · descent notes (technicality, surface, danger) [safety] · photo spot.

## C. Water & food — per point
**Water:** source type (fountain · public tap · cemetery tap · church · spring) · potable? · seasonal (frost
shut-off) · reliability. **Café:** cyclist-friendly? · open days & hours [OSM] · weekly closing day · indoor bike
parking · outdoor seating · card/cash · coffee-stop reputation. **Shop/bakery:** type · hours · open Sunday? · what
they stock. **Toilet:** public · free/paid · accessible [OSM].

## D. Bike services — per point
**Shop:** brands serviced · repairs? · rental? · e-bike service? · hours [OSM]. **Repair station:** pump + valve
type (presta/schrader) · tools available · chain tool · work stand · 24/7? [OSM]. **Charging:** connector ·
free/paid · location. **Vending:** tubes / CO2 / spares.

## E. Stays — per place
Type (B&B · hotel · hostel · campsite · refuge) [OSM] · **secure bike storage** · bike wash · tools/workshop ·
drying room · early/packed breakfast · price band · scheme (cyclist-accommodation network · independent) ·
booking link / contact · open season · minimum nights · cyclist-rated.

## F. Hazards & conditions — per report  *(all [safety])*
Type (pothole · loose gravel · dangerous junction · blind corner · tram tracks · level/cattle crossing · road
closure · construction · flooding · ice · aggressive dog · notorious crosswind) · severity · **date observed /
last confirmed** · temporary vs permanent · seasonal window · direction affected · suggested detour · confirm/decay state.

## G. Getting there — per node
**Station:** bikes allowed (always / off-peak / reservation) · bike spaces · lifts/ramps · bike ticket needed? [OSM].
**Ferry:** bikes allowed · schedule · seasonal · cost · crossing time [OSM]. **Tunnel/bridge:** cyclists allowed? ·
shuttle? · alternative. **Parking:** free/paid · size · surface · security [OSM]. **Bike-share:** docks · type [OSM].

## H. Shelter & emergency — per point
**Shelter:** covered? · type (bus stop · hut · church porch · barn) · seating. **Refuge/hut:** staffed? · water ·
food · sleeping · open season [OSM]. **Medical:** hospital · pharmacy · first aid · hours [OSM]. **Emergency:** SOS
phone / rescue point · what3words-style locator.

## I. Scenic views — per POI
**Viewpoint:** what you see · best light / time of day · access [OSM][media]. **Photo spot:** best angle, time.

## J. History & culture — per POI
**Landmark:** type · era · one-line story. **Local story:** short text + source. **Municipality:** public-domain
coat of arms · a fact or two. **Cycling heritage:** Tour/Classics history · velodrome · memorial · famous finish line.

## K. Quality rides — cyclist-experience attributes — per road/route  *(ratings, 1–5 unless noted)*
Quietness · scenery · surface quality · **bike-type suitability** (road · gravel · MTB · e-bike) · **accessibility**
(adapted-bike / handbike friendly, gradient cap) · best direction to ride · effort/difficulty · family/kid-safe ·
seasonal best.

## L. Derived & aggregate — computed, not entered  *(all [auto])*
Road popularity score · rideability inference (is this gravel OK on a road bike?) · under-explored areas · per-area
coverage % and data freshness · confidence/age of each [safety] item. These are *outputs* of the Commons, not things
a person fills in — but they're published openly too.

---

*Design note:* keep every field **optional and additive** — a rider adds one fact (a water tap, a gradient, a
"closed" flag) without filling a form. Richness accretes from many small contributions, OSM-style. Required fields
kill contribution; optional fields let the Commons grow one tap at a time.

---

# Giving back to OpenStreetMap  *(Phase 2 feature)*

Today the website *uses* OSM tiles (consumes OSM). "Gives back to" is a **committed intention, not yet
built.** Because the Commons is **ODbL — the same licence as OSM** — data can flow back legally. The flow,
lightest to heaviest:

- **Rider nudge (day-one-able).** When a rider adds an OSM-appropriate fact that OSM is missing (water tap,
  repair station, wrong surface), offer a one-tap *"add to OpenStreetMap too?"* — posted as their own
  OSM edit. Clean, community-driven, no bulk-edit politics. This is how you become a good OSM citizen.
- **Curated submission.** Curators review verified facts and submit them properly, with sources, human-in-the-loop.
- **Formal import (later, heavy).** Bulk contribution needs an OSM *import plan*, community discussion, an
  ODbL-compatible source, and a dedicated account — strict rules; bad imports get reverted. Not a day-one move.

**Flows back → OSM:** durable infrastructure facts (the **[OSM]**-tagged items — water, repair stations, bike
shops, surface, cycleways, barriers).
**Stays in the Commons (not pushed to OSM):** the experience layers apps build on top, subjective ratings (J), ephemeral **[safety]** hazards
(OSM doesn't want "pothole reported yesterday"), and anything personal. OSM wants lasting facts, not the game or the weather.

**Etiquette:** follow OSM's Import & Automated-Edit guidelines — human-reviewed, attributed, never a firehose —
or you get reverted and resented. Slow and clean beats fast and bulk.

**Metric:** track *facts contributed back to OpenStreetMap by Commons contributors* — it proves the
give-back is real and makes a genuinely good community story.
