# Overnight-stay data sources for the Atlas

> **Wiki-prepare draft** — research notes staged for review. Once approved, lift into the public wiki (e.g. _Data sources → Where to sleep_).
>
> - **Date:** 2026-06-25
> - **Scope:** Where can the **O · Where to sleep** layer get accommodation data beyond OpenStreetMap, first for the Wallonia pilot, then worldwide (updated 2026-10-21), and which of it we may legally and ethically reuse.
> - **Lens:** Our data is published under **ODbL**. A source is only usable if it is (a) under an open licence compatible with ODbL **and** (b) not built from personal data we'd be republishing.

---

## TL;DR

- **One genuinely usable source beyond OSM:** the official Walloon tourism database — **PIVOT "Les offres touristiques en Wallonie"**, published via **Géoportail de la Wallonie under CC-BY 4.0**. Region-wide campsites, gîtes, B&Bs, hotels with coordinates. **This is the one to ingest.**
- **The cyclist-hospitality networks people ask about — Warmshowers and Vrienden op de Fiets — are closed.** Members-only, paid, and the data is people's home addresses. Not reusable, and not just for licensing reasons.
- **Cyclist labels already sit in OSM.** `bettundbike` (Bett+Bike, Germany and Austria), `award:accueilvelo` (Accueil Vélo, France) and `bike_friend` (mostly Denmark) mark about 1,100 stays worldwide. The harvest stores them and the map shows them as a Cyclist label row.
- **Outside Europe, Taiwan is the best find:** its open national register flags 4,901 stays as bicycle-friendly (CC BY 4.0-compatible). US federal hiker-biker camps are public domain.
- **Flanders has an open register with a cyclist label**, but no open licence yet: ask Toerisme Vlaanderen.
- **Welcome To My Garden** is the open-*source*, commons-minded project — but its garden data isn't openly *licensed*. We would **ask for a partnership, not scrape**.

---

## Why the licence/privacy lens is strict

Two filters, both must pass:

1. **Licence compatibility.** ODbL can absorb public-domain (CC0) and attribution-only (CC-BY) data. It **cannot** absorb share-alike-of-another-flavour (CC-BY-**SA**), non-commercial (NC), no-derivatives (ND), or "all rights reserved" data. "Visible on a website" is not a licence. "No licence stated" is **not** permission — it means no.
2. **Personal data.** Hospitality networks are directories of **named hosts at their home address**. Even where a licence were permissive, folding home addresses into a public dataset is a privacy problem. The Commons dataset stays non-personal; these stay out.

---

## Inventory

| Source | Has coords | Licence | ODbL-reusable? | Verdict |
|---|---|---|---|---|
| **PIVOT "Offres touristiques en Wallonie"** (Géoportail Wallonie) | Yes | **CC-BY 4.0** | **Yes** — attribute *"Source : Tourisme Wallonie"* | **★ Use — the real win** |
| OpenStreetMap `tourism=*` | Yes | ODbL | Yes | Already our base |
| OSM cyclist labels (`bettundbike`, `bett_and_bike:ref`, `award:accueilvelo`, `bike_friend`) | Yes | ODbL | Yes | **In use** - the Cyclist label row |
| Toerisme Vlaanderen linked open data | Yes | Copyright, "Bron: Toerisme Vlaanderen" | Not yet | **Ask** - 420 stays on iconic cycling routes |
| Accueil Vélo on data.gouv.fr | Yes | ODbL / Licence Ouverte 2.0 | Yes, but useless | Skip - one set from 2013, one of tourist offices only |
| Welcome To My Garden | Yes (exact) | code AGPL-3.0; **data: none** | No (unlicensed) | **Ask — partnership, not scrape** |
| Warmshowers | Members-only | Proprietary, paid | No | Closed + personal data |
| Vrienden op de Fiets | Members-only | Proprietary, paid | No | Closed + personal data |
| iOverlander | Sparse | Proprietary, non-commercial | No | Exclude |
| Park4Night / Campercontact | Yes | Proprietary | No | Exclude |
| Refuges.info | Yes | CC-BY-**SA** 2.0 | No (SA conflicts with ODbL) | Skip (tiny, hiker-only, mostly in OSM) |
| "Inventaire des campings" (Géoportail) | Yes | SPW — *no redistribution* | No | Avoid — PIVOT covers campsites anyway |
| Same PIVOT data via **ODWB** (odwb.be) | Yes | **none** (`license: null`) | No | Wrong channel — use the Géoportail copy |
| OpenCampingMap / Overpass extracts | Yes | ODbL (from OSM) | Yes, but **redundant** | Already covered by OSM |

---

## The one to use — Géoportail Wallonie PIVOT (CC-BY 4.0)

The Commissariat Général au Tourisme (Tourisme Wallonie) maintains the **PIVOT** tourism database and publishes it openly through the **Géoportail de la Wallonie**.

- **Contents:** hôtels, gîtes, chambres d'hôtes, campings, meublés, villages de vacances — region-wide, each as a point with coordinates. Updated daily.
- **Licence:** **CC-BY 4.0** (attribution-only → ODbL-compatible). Required attribution string: **`Source : Tourisme Wallonie (TW)`**.
- **Catalogue record:** `https://geoportail.wallonie.be/catalogue/91721175-5f01-410c-8c78-37c1d1893ba2.html`
- **Service (ArcGIS REST, JSON/GeoJSON):** `https://geoservices.wallonie.be/arcgis/rest/services/TOURISME/OFFRES_TOURISTIQUES/MapServer`
- **Two important caveats:**
  - **Channel matters.** The *identical* dataset also sits on the ODWB portal (`odwb.be`), but there it carries **no licence** (`license: null`). Only the **Géoportail** copy is CC-BY. Always pull from Géoportail.
  - **Cyclist-friendly flag.** Wallonia runs a **"Bienvenue Vélo"** cyclist-welcome scheme. The live layer has no attribute for it, so PIVOT cannot mark bike-friendly stays.

This is the only non-OSM source that is both openly licensed **and** genuinely additive. It upgrades E·Where-to-sleep from OSM-only to authoritative, region-wide coverage.

---

## Cyclist labels in OSM

Three cyclist-welcome schemes already live in OSM as tags on the stay itself, so they need no new source, only a harvest that keeps them:

| Tag | Scheme | Objects (taginfo, 2026-10-21) | Rule |
|---|---|---|---|
| `bettundbike=yes` | Bett+Bike (ADFC), Germany and Austria | 497 | yes |
| `bett_and_bike:ref` | Bett+Bike reference, such as `NRW-1540` | 191 | any value, unless `bettundbike=no` |
| `award:accueilvelo` | Accueil Vélo (France Vélo Tourisme), France | 312 | `yes`, an award year (`2024`), or a range that has not ended (`2026-2029`); `2014-2024` has ended |
| `bike_friend=yes` | Generic bike-friendly, mostly Danish places | 302 | yes, only when no named scheme is there |

The coverage harvest stores the four keys, and the map drawer shows a **Cyclist label** row on the stay. The stay form has the same field, so a rider can correct it.

The limit is coverage, not licence: Bett+Bike lists about 5,900 members, and OSM tags about 500 of them. Mappers who add these tags improve the map directly.

## Ask first - Toerisme Vlaanderen

Flanders publishes its full register of stays as linked data: a SPARQL endpoint (`https://linked.toerismevlaanderen.be/sparql`), a JSON:API and RDF dumps. Each stay has a name, address, location, beds, a photo and quality labels. One label is a cyclist label: **"Opgenomen in icoonfietsroutes"** (part of an iconic cycling route), on 420 stays.

The catalogue says only that the data is protected by copyright, with the attribution "Bron: Toerisme Vlaanderen". That is not an open licence, so we cannot fold it into ODbL data. We ask Toerisme Vlaanderen for a written grant first.

## Outside Europe

No cyclist label outside Europe is tagged in OSM. About 200 US campsites carry "Hike/Bike" or "Hiker Biker" in their name, but no structured tag. Official sources:

| Source | Country | What it holds | Licence | Verdict |
|---|---|---|---|---|
| Tourism Administration lodging database (`data.gov.tw` 7780) | Taiwan | 4,901 of 15,656 stays flagged "自行車友善旅宿" (bicycle-friendly stay), with coordinates, updated daily | Open Government Data License v1.0, CC BY 4.0-compatible | **Ingest** flag and coordinates; drop operator names and phones |
| Recreation.gov RIDB | USA | 116 "Hiker/Bicyclist" campsites in 12 campgrounds, plus the C&O Canal Hiker-Biker Overnight sites | Public domain | **Ingest**, matched by name |
| NPS API `/campgrounds` | USA | 49 of 665 campgrounds mention hiker-biker sites | Public domain | **Ingest** |
| SIT Québec campings | Canada | 116 of 1,227 campgrounds list bike racks or sheds | CC BY 4.0 | **Ingest** the flag only |
| DOC campsites and huts | New Zealand | 1,291 generic stays along the cycle trails | CC BY 4.0 | **Ingest** |
| California State Parks campgrounds | USA | 43 Hike/Bike Camp Areas | Conflicting terms | **Ask** |
| Vélo Québec Bienvenue cyclistes!, Ontario By Bike | Canada | 400+ and about 1,656 certified businesses | None stated | **Ask** |
| League of American Bicyclists, Adventure Cycling, ATDW, Tourism NZ, Japanese prefecture lists | USA, Australia, NZ, Japan | Business lists, paid maps, distributor feeds | Closed | **No** |

CC BY data enters the Commons database with its attribution. It cannot go into OSM itself without a waiver from the publisher.

## Open-source, but ask first — Welcome To My Garden

[Welcome To My Garden](https://welcometomygarden.org) is a Belgian non-profit where people offer their garden as a free overnight spot for slow travellers and cyclists. Of everyone here, it is **the most aligned with our values**: a non-profit, anti-commercial, build-in-public project (its app is AGPL-3.0; a co-founder came out of Open Knowledge Belgium).

But **open-source code is not open data.** There is no data licence, no published export, and their terms prohibit collecting personal data. The gardens are pinned at **exact home coordinates**. So our position is simple:

- **We do not scrape it.** No licence basis, and it's personal data.
- **We'd partner.** Because of their mission, they're the network most likely to say yes to a proper data arrangement. Contact: `support@welcometomygarden.org`.

(A small open-data-friendly successor to Warmshowers, **`sleepy.bike`** — user-owns-their-data, Solid-pods — is worth watching for the same reason, but it's early and small.)

---

## Closed / incompatible — for the record

- **Warmshowers** — non-profit but **paid** and **members-only**; the API was locked down to third parties years ago; the Terms forbid copying/reuse of member data. Host data is personal data. **Not usable.**
- **Vrienden op de Fiets** — **paid membership** to see addresses, per-host opt-in consent model, explicit no-resale/GDPR stance. Mostly Netherlands + Flanders; Wallonia coverage is thin. **Not usable.**
- **iOverlander** — proprietary; export is paywalled and **personal, non-commercial use only**; no redistribution. Sparse, overland-oriented coverage in the Ardennes. **Exclude.**
- **Park4Night / Campercontact** — the only ones with genuinely *new* overnight-spot data, but **proprietary**, scraping prohibited. **Exclude.**
- **Refuges.info** — open, but **CC-BY-SA 2.0**, which conflicts with ODbL; and it's ~30 hiker-oriented shelter points over the Ardennes, most already cross-referenced into OSM. **Skip.**
- **EuroVelo services / "Bienvenue Vélo" / Bett+Bike / provincial tourism directories** — website listings only, no open accommodation download. (Note: EuroVelo's **route GPX tracks** went ODbL in Oct 2024, but that's routes, not stays.)
- **OpenCampingMap / Overpass / OSMnames** — ODbL, but it's just OSM rendered differently. Harvesting OSM directly already covers it. **Redundant.**

---

## Recommendation

1. **Ingest the Géoportail PIVOT layer (CC-BY 4.0)** into a Wallonia harvester, region-balanced like the other layers, feeding **E · Where to sleep**. Attribute *"Source : Tourisme Wallonie (TW)"* in the fixture header. Check the live REST fields for a "Bienvenue Vélo" attribute and surface it if present.
2. **Keep OSM as the base** for E; treat PIVOT as the authoritative overlay (dedupe against OSM where they overlap).
3. **Open a conversation with Welcome To My Garden** if we want garden-camping coverage — as a partner, never a scraper.
4. **Everything else: leave out.** Closed, incompatible, or already covered by OSM.

---

## Sources

- Taiwan lodging database: <https://data.gov.tw/dataset/7780> · licence <https://data.gov.tw/en/license>
- Recreation.gov data: <https://www.recreation.gov/use-our-data>
- SIT Québec campings: <https://www.donneesquebec.ca/recherche/dataset/sit-quebec-campings>
- DOC campsites: <https://services1.arcgis.com/3JjYDyG3oajxU6HO/arcgis/rest/services/DOC_Campsites_Open_Data/FeatureServer>
- Toerisme Vlaanderen linked data: <https://linked.toerismevlaanderen.be/>
- OSM Key:bettundbike: <https://wiki.openstreetmap.org/wiki/Key:bettundbike>
- Géoportail Wallonie — PIVOT offres touristiques (CC-BY 4.0): <https://geoportail.wallonie.be/catalogue/91721175-5f01-410c-8c78-37c1d1893ba2.html>
- Welcome To My Garden: <https://welcometomygarden.org> · code <https://github.com/WelcometoMyGarden>
- Warmshowers: <https://en.wikipedia.org/wiki/Warm_Showers> · API lockdown account <https://warmshowers.bike/>
- Vrienden op de Fiets: <https://www.vriendenopdefiets.nl/veelgestelde-vragen>
- iOverlander terms: <https://ioverlander.com/terms_2023>
- Refuges.info licence: <https://www.refuges.info/wiki/licence>
- EuroVelo GPX → open data: <https://pro.eurovelo.com/news/2024-10-09_eurovelo-gpx-tracks-go-open-data>
