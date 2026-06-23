# The fragmented landscape

*Why the Commons exists: today a cyclist's knowledge is scattered across dozens of separate, mostly
closed apps — "a different app for everything." This page lists the real providers in each area, as a
reference behind that claim. It is a snapshot (June 2026); names and figures shift.*

Legend: **open** = open data / open source · **closed** = proprietary silo · **mixed** = open base, closed layer.

---

## Climbs & cols
Finding, rating and racing climbs, cols, gradients, segment times and KOMs.

- **Strava** (Segments / Local Legends) — closed, freemium. The de-facto global KOM standard everyone benchmarks against; leaderboards increasingly paywalled.
- **VeloViewer** — closed, paid. Deep segment/climb analytics on the Strava API (VAM, category, 3D profiles).
- **climbfinder** — closed, freemium. The broadest, best-UX European climb-profile database.
- **CyclingCols** — closed (community), free. A broad community catalogue of cols across many countries — for riders ticking off climbs one by one.
- **myCols** — closed, free. Worldwide climbs with history/storytelling per ascent.
- **PJAMM Cycling** — closed, freemium. Global steepest/hardest climbs + a proprietary difficulty index (PDI) and Street View.
- **salite.ch** — closed (volunteer), free. Veteran European archive, deep on Italy/Switzerland.
- **cols-cyclisme.com** — closed, free. The French-speaking community's col reference.
- **quaeldich.de** — closed (community), free. Rich German-language climb detail (surface, traffic, photos).
- **climbbybike.com** — closed, free. One of the earliest large pan-European climb indexes.
- **100 Greatest Cycling Climbs** (Simon Warren) — closed, paid. The canonical UK climb bucket-list (book + app).
- **CyclingUp / Climb Index Europe / cycloclimbing** — closed, free. Smaller volunteer European catalogues.

**Fragmentation:** heavily closed; there is **no shared open climb dataset**. Strava's leaderboards are the only near-universal layer (proprietary, paywalled), while a dozen national databases re-catalogue the same cols behind walled gardens.

## Quality rides & ratings
Route planning, navigation, ride sharing, and cyclist-experience ratings (quietness, scenery, surface).

- **Komoot** — closed, freemium. Surface/bike-type routing + a vast community Highlights layer & Trail View.
- **RideWithGPS** — closed, freemium. Precise planning, cue sheets, club/event tooling.
- **Strava Routes / Heatmap** — closed, freemium. Routes from aggregated activity; "where cyclists actually ride."
- **cycle.travel** — mixed (OSM base), free. Genuinely quietness-optimised routing from real traffic data.
- **Bikemap** — closed, freemium. Millions of user routes with surface filters.
- **CycleStreets** — open (non-profit, OSM), free. Explicit fastest/balanced/**quietest** modes (UK).
- **Garmin Connect / Wahoo / Hammerhead** — closed (with hardware). Popularity routing + Climb previews on devices.
- **Outdooractive** — mixed, freemium. Large multi-sport route portal, strong in DACH.
- **BRouter** — **open-source**, free. The configurable routing engine behind many other tools.
- **CyclOSM / OpenCycleMap** — **open** (OSM), free. The open cycling map styles others embed.
- **kurviger / Relive / Cyql** — closed, freemium. Scenic-road planning / ride videos / club ride org.

**Fragmentation:** a thin open spine exists (OSM, BRouter, CyclOSM, cycle.travel), but the mass-market layer is proprietary and **cyclist-experience ratings are siloed per-app** (private Highlights, heatmaps, surface tags) — no portable quality standard.

## Scenic, views & heritage
Viewpoints, panoramas, photo spots, landmarks, cultural/heritage sites worth riding past.

- **Komoot Highlights** — closed, freemium. The de-facto discovery layer for European cycle touring.
- **Strava** — closed, freemium. POIs + popularity heat (not curated heritage).
- **Outdooractive** — mixed, freemium. Tourism-board POIs and editorial tours (DACH/Alps).
- **AllTrails** — closed, freemium. Popular viewpoints/photo spots, hiking-leaning.
- **Wikiloc** — closed, freemium. Tens of millions of trails with geotagged waypoints (viewpoints, fountains, ruins).
- **Atlas Obscura** — closed, free to browse. Offbeat cultural/heritage sites.
- **izi.TRAVEL** — mixed, free. Location-triggered audio guides at heritage sites.
- **Wikipedia / Wikimedia Commons** — **open** (CC), free. The canonical open heritage knowledge base + millions of geotagged photos — but not packaged for cyclists.
- **OpenStreetMap** (`tourism=viewpoint`, `historic=*`) — **open** (ODbL), free. The upstream POI source most apps render.
- **Google Maps / Places** — closed. Unmatched coverage + Street View, but ToS forbids bulk reuse.
- **Mapillary** — mixed (Meta). Crowd street-level imagery; photos are **CC BY-SA** (open, and usable to trace into OSM), but the platform, derived data, and API are Meta-owned and under Meta's terms.
- **Polarsteps / Spotted by Locals** — closed. Personal trip diaries / locals' city guides.
- **FATMAP** — **defunct** — a 3D scenic-discovery map **shut down by its owner Strava**: a concrete example of a knowledge silo simply vanishing and taking its curated data with it.

**Fragmentation:** the open facts live in OSM and Wikipedia/Commons, but the layers cyclists actually use wall their curation into non-interoperable silos — and FATMAP's shutdown shows how fragile any single silo is.

## Bike-friendly stays
Cyclist-friendly accommodation and hospitality networks.

- **Warmshowers** — non-profit, web free / app paid. The original large-scale bike-touring hospitality network.
- **Booking.com / Airbnb / HostelWorld** — closed. Scale, but cyclist-friendliness is an unverified amenity flag.
- **Bett+Bike** (ADFC, Germany) — closed scheme, free to search. Thousands of certified properties; the model others copied.
- **Accueil Vélo** (France) — closed scheme, free. Tightly integrated with France's cycle-route network.
- **Albergabici** (Italy) / **Fietsers Welkom!** (Netherlands) / **Cyclists Welcome** (Cycling UK) — closed national schemes.
- **EuroVelo national labels** — numerous closed national silos (thousands of certified businesses across **incompatible** systems).
- **iOverlander / Park4Night** — mixed, freemium. Crowd-sourced wild-camp / overnight spots for self-supported tourers.
- **Couchsurfing** — closed, paid. Broad reach, declining trust after monetisation.
- **Trustroots & Couchers.org** — **open-source**, free. Community-run alternatives with cyclist circles.
- **biroto / cyclefriendlyhotels / beds4cyclists** — closed aggregators competing with the national labels.

**Fragmentation:** extreme. Cyclist-welcome quality is splintered across many incompatible national labels plus private aggregators, mainstream OTAs reduce it to a checkbox, and the community-owned options are small and don't interoperate.

## Road surface & conditions
Surface type & quality, gravel/pavé, hazards, closures, traffic/safety, live conditions.

- **OpenStreetMap** (`surface=` / `smoothness=` / `tracktype=`) — **open** (ODbL), free. The base layer nearly every tool reads — but coverage is patchy.
- **CyclOSM** — **open-source**, free. The most complete free cycling map style (surfaces, cobbles, infra).
- **cycle.travel** — mixed, free. Surface scoring + a "Cycling Quietness" traffic model.
- **BRouter** — **open-source**, free. Gravel-favouring profiles weighting OSM surface tags.
- **Gravelmap** — crowdsourced (non-profit), free. The largest *dedicated* unpaved-surface database — but its own silo.
- **Trailforks** — closed (Outside/Pinkbike), freemium. The strongest real-time MTB trail-condition reporting.
- **Strava Heatmap / Metro** — closed, freemium. Popularity signal — but **no surface-quality info**.
- **Bikemap / RideWithGPS / Komoot** — closed, freemium. Surface display + comfort/popularity routing.
- **Waze / Google Maps** — closed, free. Real-time closures/hazards — but car-centric, thin on bike detail.
- **BikeMaps.org** — open/non-profit, free. Crowd cyclist collisions/near-misses/hazards (academic, sparse).
- **OsmAnd** — open-source core, freemium. Offline surface inspection in the field.
- **One.Network / roadworks.org** — closed over public data, free. The official UK closure feed — but motor-framed.

**Fragmentation:** one shared open base (OSM tags) plus a constellation of incompatible proprietary silos — Strava's heat, Gravelmap's gravel DB, Trailforks' reports, Waze's closures — none interchanging data, the richest live signals closed and car-centric.

## Water · food · services
Drinking water/refill, resupply (shops/bakeries), cyclist cafés, bike shops & repair stations, e-bike charging.

- **OpenStreetMap** (`amenity=drinking_water` / `bicycle_repair_station` / `shop=bicycle`) — **open** (ODbL), free. The only cross-app shared source — but thinly mapped.
- **Refill** — closed (UK charity), free. A large network of free tap-water refill points.
- **TrailTap / WeTap** — crowdsourced, free. Cyclist/walker fountain & refill maps (UK / global, patchy coverage).
- **Komoot** — closed, freemium. In-ride resupply/POI alerts along a route.
- **Google Maps** — closed, free. The default resupply tool — but nothing cyclist-specific or refill-aware.
- **Bike Citizens** — mixed, freemium. Urban cyclist navigation + bike-shop POIs.
- **OsmAnd / Organic Maps** — open-source, free/freemium. Offline water/café/shop search where Google fails.
- **Bosch eBike Flow / BikeEnergy** — closed (hardware), free at point of use. The leading e-bike charging maps — locked to brand/region.
- **Warmshowers** — non-profit. Touring hosts (lodging, meals, water).
- **Café Network / CoffeeStop / CakeRider** — closed, free. Small regional cyclist-café lists (mostly UK).
- **City open-data fountain datasets** (NYC, Vienna, Vancouver…) — **open**, free. Authoritative *within* a city, each its own schema/portal.

**Fragmentation:** even more siloed than surface — refill points split across Refill/TrailTap/WeTap and dozens of incompatible city portals; e-bike charging fractured by hardware brand; cafés/repair/hosts each in their own list. No single source unifies water, food, repair and charging.

---

## What the Commons does differently
One open dataset (ODbL) for the non-personal facts above, given back to OpenStreetMap, with a free query
API and bulk exports — so the layers **join up** instead of fragmenting further, and where good open data
already exists (OSM, Wikimedia, open hospitality networks) we **partner and link** rather than wall off a copy.
