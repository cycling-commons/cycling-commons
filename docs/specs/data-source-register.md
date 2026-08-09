<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Data source register

**Status:** canonical reference · **Audience:** contributors and curators
adding data, or evaluating a new upstream source

Compiled 2026-08-09. This is the answer to "where could layer *X* get more
data, and are we allowed to take it?" — one row per candidate upstream source,
each with its licence, its verdict, and the evidence behind the verdict.

**This is not [`wiki/landscape.md`](../../wiki/landscape.md), and the two must
not be merged.** That page surveys the *products* riders use today — who else
does this, and how fragmented it is — and it is public-facing prose. This
register is the *supply* side: what may be ingested, under what terms, by us.
A name can appear in both with different verdicts, and usually does: Komoot is a
significant product and an unusable source.

**One rule before anything else.** A source's presence here is not permission to
harvest it. `Ingest` means the licence permits it; it does not mean the
mechanism is decided. [`osm-data-architecture.md`](osm-data-architecture.md) §1
still governs *how* — store only our additions, reference OpenStreetMap (OSM)
rather than copying it — and a bulk mirror of a permissive source can still be
the wrong architecture.

---

## 1. The licence test

Cycling Commons publishes its data under the **O**pen **D**ata**b**ase
**L**icense 1.0 (ODbL) plus the **D**atabase **C**ontents **L**icense 1.0
(DbCL); photos under **C**reative **C**ommons **A**ttribution–**S**hare**A**like
4.0 (CC BY-SA 4.0); wiki prose under CC BY-SA 4.0; code under PolyForm Shield
1.0.0 ([`osm-data-architecture.md`](osm-data-architecture.md) §3). Everything
below follows from that.

| Upstream licence | Can it enter our ODbL dataset? |
|---|---|
| ODbL 1.0 | **Yes** — same licence on both sides, share-alike costs us nothing |
| CC0 / **P**ublic **D**omain **D**edication and **L**icense (PDDL) | **Yes** — no obligations at all, still credit as a courtesy |
| CC BY 4.0, and attribution-only government licences (see §2) | **Yes, with attribution carried per row** — the precedent is Tourisme Wallonie PIVOT, already in `item.source` |
| CC BY-SA 4.0 | **Media only.** Fine for the photo pool (same licence); keep out of the *database*, where share-alike collides with ODbL |
| CC BY-NC / -ND, "personal use only" | **No.** Our data is redistributed commercially by anyone under ODbL, which these forbid |
| No licence stated | **No, until asked.** Silence is not permission — the Esri episode ([`Dated/2026-08-09-esri-imagery-terms.md`](Dated/2026-08-09-esri-imagery-terms.md)) is what the absence of a 401 is worth |
| Terms of service forbidding bulk reuse | **No**, regardless of how the data itself is licensed (Google) |

**Verdict vocabulary used in every table below:**

- **Ingest** — licence permits taking the data into our dataset, with whatever
  attribution it requires.
- **Reference** — we may link to it, deep-link into it, or embed it with
  attribution, but not copy it into the dataset. Mapillary imagery is the
  worked example.
- **Ask** — plausibly willing, no published licence that covers us. Requires a
  human conversation before a single request is made. **Never scrape an `Ask`
  source while waiting for an answer.**
- **No** — terms forbid it. Recorded so it is not researched a third time.

**Confidence** is marked on every row: `verified` = the licence text or the
dataset's own licence field was read on 2026-08-09; `stated` = the source
publicly describes its terms and we have not read the instrument itself;
`unverified` = believed from general knowledge and **must be checked before
any use**.

---

## 2. National open-data licences — the unlock table

Most of the per-letter candidates below are government datasets, and the same
handful of licences decides all of them. Reading this table once is worth more
than reading fifty dataset pages.

| Country / body | Licence | Terms in one line | Verdict | Confidence |
|---|---|---|---|---|
| Flanders (Geopunt, Vlaamse overheid) | Modellicentie Gratis Hergebruik v1.0 | Free reuse, commercial and non-commercial, indefinite, **attribution the only obligation** | **Ingest** | verified |
| Wallonia (Géoportail, PIVOT) | CC BY | Attribution | **Ingest** — already in use for E · stays | verified (in use) |
| France (data.gouv.fr, DATAtourisme) | Licence Ouverte / Open Licence 2.0 | Attribution; explicitly compatible with CC BY | **Ingest** | verified |
| United Kingdom (data.gov.uk, Ordnance Survey OpenData) | **O**pen **G**overnment **L**icence v3.0 | Attribution, including the Ordnance Survey Crown-copyright line where OS data is underneath | **Ingest** | verified |
| Netherlands (PDOK, Nationaal Georegister) | Mostly CC0, some CC BY | Per dataset — the licence field is populated and must be read | **Ingest**, per dataset | stated |
| Germany (GovData) | Datenlizenz Deutschland – Namensnennung 2.0 | Attribution | **Ingest** | stated |
| Switzerland (opendata.swiss) | Mixed; commonly "open use, source must be named" | Per dataset | **Ingest**, per dataset | stated |
| Spain (datos.gob.es) | Mixed; commonly attribution-only reuse under Ley 37/2007 | Per dataset | **Ingest**, per dataset | unverified |
| European Union (data.europa.eu) | Mostly CC BY 4.0 | Attribution | **Ingest** | stated |

**The trap in this table** is that a national portal's *default* licence is not
the licence of every dataset on it. Dutch cycling-network datasets are a live
example: the geometry is described as open data, and at least one of the
regional network sets carries "Onbekende licentie" (unknown licence) in its own
metadata. Read the dataset's licence field, not the portal's front page.

---

## 3. The register, by catalog letter

Letters follow [`wiki/data-catalog.md`](../../wiki/data-catalog.md). Every
letter already has OSM behind it — that is the baseline and is not repeated per
row. What follows is what could be added *beyond* OSM.

### A · Road surface

| Source | What it gives | Licence | Verdict | Confidence |
|---|---|---|---|---|
| OSM `surface` / `smoothness` / `tracktype` | The only cross-border surface base there is | ODbL | **Ingest** (baseline) | verified |
| National road registries (NL Nationaal Wegenbestand, FR IGN, DE state registries) | Authoritative centrelines, sometimes a surface attribute | National licences, §2 | **Ingest** where a surface attribute genuinely exists | unverified |
| Gravelmap | Dedicated unpaved-surface database | No licence published | **Ask** | unverified |
| Trailforks | Trail condition reporting | Proprietary (Outside Inc.) | **No** | stated |
| Strava Metro / Heatmap | Popularity, **not surface** | Proprietary, agreement-gated | **No** | stated |

**Assessment.** There is no second open surface source of consequence. This is
the finding behind the surface-line-tiles work: A's coverage problem is not
solved by finding a provider, it is solved by rendering OSM itself as a
reference plane. Do not spend more research here.

### B · Climbs

| Source | What it gives | Licence | Verdict | Confidence |
|---|---|---|---|---|
| Wikidata | Named cols with `P625` summit coordinates, per country | CC0 | **Ingest** — in use (`tools/wikimedia/climb_candidates.py`) | verified (in use) |
| OSM `mountain_pass=yes`, `natural=saddle` | Passes and saddles, worldwide | ODbL | **Ingest** | verified |
| Copernicus GLO-30 **D**igital **E**levation **M**odel (DEM) | The elevation behind every measured gradient | Free, attribution required | **Ingest** — in use, notice on `/credits` | verified (in use) |
| climbfinder, quaeldich, salite.ch, cols-cyclisme, PJAMM | The richest European climb catalogues | All proprietary, no reuse licence | **No** | stated |
| Strava segments | The de-facto leaderboard | Proprietary, **API terms forbid** storing/redisplaying | **No** | stated |

**Assessment.** Climbs are an editorial layer and will stay one. The seeding
tooling exists and the backlog item is to run it per country, not to find a new
source.

### C · Water & food · M · Public toilets

| Source | What it gives | Licence | Verdict | Confidence |
|---|---|---|---|---|
| OSM `amenity=drinking_water`, `toilets`, `shop=bakery` | Baseline, worldwide | ODbL | **Ingest** (baseline) | verified |
| NL Nationaal Georegister tap-point dataset | Authoritative Dutch public tap points | PDOK, §2 | **Ingest** — already the intended NL pipeline source | stated |
| City fountain datasets (Vienna, Paris, Barcelona, Zürich, and dozens more) | Authoritative within one city, each its own schema | Municipal open data, generally attribution-only | **Ingest**, per city | stated |
| Great British Public Toilet Map | ~10,000 UK toilets, council-sourced | Built on public-sector open data and OSM; **its own aggregate licence is not clearly published** | **Ask** | unverified |
| drinkwaterkaart.nl | Dutch refill points | Not open; a partnership candidate | **Ask** | stated |
| Refill (UK charity) | Large refill-point network | Not open | **Ask** | verified (no open licence found) |
| HogeNood | Dutch toilet coverage | Closed, partner-only | **No** | stated (prior decision) |
| TrailTap, WeTap, Find Drink Water and similar apps | Crowd fountain maps | No published licence; several are themselves OSM-derived | **No**, or redundant with OSM | unverified |

**Assessment.** Municipal fountain datasets are the highest-yield unexplored
seam in the whole register: dozens of them, all permissive, all authoritative,
all currently invisible to us. They are also the most laborious — one schema per
city. Worth a single pipeline that eats a normalised **CSV**/GeoJSON and a
per-city adapter, rather than a decision per city.

### D · Bike services

| Source | What it gives | Licence | Verdict | Confidence |
|---|---|---|---|---|
| OSM `shop=bicycle`, `amenity=bicycle_repair_station` | Baseline, worldwide | ODbL | **Ingest** (baseline) | verified |
| FR data.gouv.fr cycling datasets (parking, repair, services) | Dense French coverage | Licence Ouverte 2.0, §2 | **Ingest** | verified |
| Flanders / Wallonia cycling-service datasets | Dense Belgian coverage | §2 | **Ingest** | verified |
| Manufacturer dealer locators (Bosch eBike, brand networks) | E-bike charging, brand service points | Proprietary, no reuse grant | **No** | stated |

### E · Bike-friendly stays

| Source | What it gives | Licence | Verdict | Confidence |
|---|---|---|---|---|
| Tourisme Wallonie PIVOT | Official Walloon accommodation | CC BY | **Ingest** — in use, its own provenance bucket | verified (in use) |
| **DATAtourisme** (ADN Tourisme, France) | **400,000+ French tourism points of interest**, daily-updated, national ontology, application programming interface plus bulk download | Licence Ouverte 2.0 | **Ingest** — the single largest actionable find in this register | verified |
| Accueil Vélo datasets on data.gouv.fr | France's cyclist-welcome label, as data — the exact semantic E wants | Licence Ouverte 2.0 | **Ingest** | verified |
| OSM `tourism=hotel/guest_house/camp_site` + `bicycle=*` amenities | Baseline, worldwide | ODbL | **Ingest** (baseline) | verified |
| Bett+Bike (ADFC, Germany) | Thousands of certified German properties | Closed scheme, no reuse licence found | **Ask** — a natural partner, same ethos | unverified |
| Vrienden op de Fiets (NL) | The Dutch equivalent | Closed | **No** (prior decision) | stated |
| Warmshowers | Hospitality network | Closed; member data is personal data regardless | **No** (prior decision) | stated |
| Welcome To My Garden | Belgian/European cyclist-camping network | Non-profit, ethos-aligned, **no public data licence** | **Ask, do not scrape** (standing decision) | stated |
| Trustroots, Couchers.org | Open-source hospitality platforms | Code is open; **member data is personal data** | **No** — a personal-data boundary question, not a licence one | stated |
| iOverlander | Overnight spots for self-supported travel | ToS: personal, non-commercial use only; redistribution expressly prohibited | **No** | verified |
| Booking.com, Airbnb, Park4Night | Scale | Proprietary, ToS forbids bulk reuse | **No** | stated |

**Assessment.** E was the letter with the least non-OSM supply, and it is now
the letter with the most: DATAtourisme plus the Accueil Vélo label sets make
France's stays layer a data-loading job rather than a research job. Bett+Bike is
the one worth a conversation — an ADFC certification scheme and an open cycling
atlas want the same thing, and a licence grant costs them nothing.

### F · Hazards & conditions

| Source | What it gives | Licence | Verdict | Confidence |
|---|---|---|---|---|
| Rider reports (ours, via Scout and the improve form) | The only source that will ever be current | Ours | **Ingest** — by design the primary source | n/a |
| National/municipal roadworks and closure feeds | Authoritative closures | National licences, §2, where published as open data | **Ingest** where open | unverified |
| BikeMaps.org | Crowd collision/near-miss/hazard reports, academic | **No licence published**; research project | **Ask** — likely receptive, it is an academic open-data project | verified (no licence found) |
| Waze **C**onnected **C**itizens **P**rogram | Live closures | Partner agreement, not open data | **No** | stated |
| One.Network / roadworks.org | Official UK closure feed | Closed layer over public data | **No** — but the underlying council data may be open, §2 | stated |

**Assessment.** F is structurally rider-sourced: hazards decay in days, and no
licensing arrangement fixes a feed that is car-framed. The correct investment is
Scout, not a provider.

### I · Scenic views · J · History & culture

| Source | What it gives | Licence | Verdict | Confidence |
|---|---|---|---|---|
| Wikidata | Named places with coordinates and typed classes, worldwide | CC0 | **Ingest** — in use, 194 I / 240 J rows seeded | verified (in use) |
| Wikimedia Commons | Photographs with machine-verifiable licences | Per file, mostly CC BY-SA / public domain | **Ingest as media** — in use, licence checked at harvest | verified (in use) |
| OSM `tourism=viewpoint`, `historic=*` | Baseline, worldwide, 850k+ on our coverage plane | ODbL | **Ingest** (baseline) | verified |
| Europeana | European cultural heritage aggregation | Metadata CC0; objects per item | **Ingest** metadata | stated |
| National heritage registers (Historic England, Rijksmonumenten, Onroerend Erfgoed Vlaanderen, Mérimée) | Authoritative, complete, per country | National licences, §2 | **Ingest** | unverified |
| DATAtourisme (again) | French heritage and viewpoint POIs | Licence Ouverte 2.0 | **Ingest** | verified |
| Atlas Obscura, Komoot Highlights | The curation riders actually use | Proprietary | **No** | stated |

**Assessment.** I and J are the best-supplied letters in the register and the
weakest in *editorial* terms — the open-review backlog is full of "would a rider
go there" questions about rows we already have. More supply is not the
constraint; a selection rule is.

### K · Quality rides / routes

| Source | What it gives | Licence | Verdict | Confidence |
|---|---|---|---|---|
| **EuroVelo GPX tracks** (European Cyclists' Federation) | 17 pan-European routes, ~90,000 km, updated yearly | **ODbL since the 2024 General Meeting** — the same licence as ours | **Ingest** — no friction at all | verified |
| Sustrans / Walk Wheel Cycle Trust National Cycle Network | 12,000+ miles of UK signed routes | OGL v3, with an Ordnance Survey Crown-copyright acknowledgement required | **Ingest** | verified |
| NL Landelijke Fietsroutes (LF) and regional node networks | The Dutch national and knooppunt networks | Open data, **but at least one regional set publishes "unknown licence"** | **Ingest per dataset**, after reading each licence field | verified (the ambiguity is verified) |
| Flanders/Wallonia node networks and RAVeL | Belgian networks | §2 | **Ingest** | stated |
| German national cycling network GPX | Published via the EuroVelo channel | Assume ODbL with the EuroVelo set; confirm | **Ingest**, confirm first | stated |
| Komoot, RideWithGPS, Bikemap, Strava routes | Community route mass | Proprietary; user-generated content the platform does not own outright | **No** | stated |

**Assessment.** EuroVelo going ODbL in 2024 is the most consequential single
fact in this register. It is a licence-identical, curated, pan-European route
set — precisely K's shape — and it needs no negotiation, no attribution
gymnastics and no architectural exception. If one thing here becomes work, it
is this.

### Imagery and elevation (not a letter, but sourced the same way)

| Source | What it gives | Licence | Verdict | Confidence |
|---|---|---|---|---|
| OpenFreeMap (Protomaps basemap) | The basemap | Open | **Reference** — in use | verified (in use) |
| Copernicus GLO-30 DEM | Worldwide 30 m elevation | Free, attribution required (notice on `/credits`) | **Ingest** — in use | verified (in use) |
| Mapillary | Street-level imagery | Photos CC BY-SA; platform and **A**pplication **P**rogramming **I**nterface (API) under Meta's terms | **Reference only** — embed with attribution, never ingest | verified (in use) |
| Esri World Imagery | Satellite base | ArcGIS Location Platform key, referrer-restricted, expires 2027-08-10 | **Reference** — in use under a key, terms recorded | verified (in use) |
| National orthophotos (NL PDOK ~8 cm, BE, FR IGN, DE) | Far better imagery than Esri, per country | National licences, §2 | **Reference**, a quality upgrade per country | stated |
| Sentinel-2 cloudless | Worldwide imagery | Open | **Ruled out** at 10 m — too coarse to trace a road | verified (prior decision) |

---

## 4. Shortlist — what this research says to actually do

In order of value per unit of work:

1. **EuroVelo, ODbL.** Licence-identical, pan-European, curated, yearly-updated
   route geometry. Nothing to negotiate. Decide how it relates to rider-proposed
   K routes (it is reference-grade, not community-proposed — likely its own
   provenance source, the way PIVOT is for E).
2. **DATAtourisme plus the Accueil Vélo sets, Licence Ouverte 2.0.** Turns
   France's stays layer from empty into populated, with a cyclist-welcome
   semantic already attached — which is exactly what E means and what OSM cannot
   express.
3. **Municipal fountain and toilet datasets.** Dozens of permissive
   authoritative sources for C and M. Build one per-city adapter pipeline rather
   than deciding city by city.
4. **National heritage registers** for J, once the editorial selection rule
   exists. Supply is not currently J's problem.
5. **Two conversations worth having**, both with organisations whose mission
   matches ours and neither of which can be scraped in the meantime:
   **Bett+Bike** (ADFC) for German stays, and **BikeMaps.org** for hazards.
   Welcome To My Garden remains ask-don't-scrape.

**Explicitly not worth more research:** road surface (no second open source
exists — the answer is the coverage plane), climbs (editorial by nature), and
hazards (rider-sourced by nature; the answer is Scout).

---

## 5. Method, and how to keep this honest

Compiled by reading published licence instruments and dataset licence fields,
not by recalling reputations. Every row carries a confidence marker for exactly
that reason, and `unverified` rows are a to-do, not a conclusion.

When adding a row:

- Record the **licence instrument**, not the vibe. "Open data" is not a licence.
- Record the **date checked**. Terms change; EuroVelo's changed in 2024 and the
  whole verdict changed with it.
- If the answer is `Ask`, say who would be asked and what would be asked for,
  so the next person can act rather than re-derive.
- A `No` is as valuable as a `Yes` and must stay in the table. Deleting ruled-out
  sources is how the same source gets researched every year.

The cost of not doing this is documented: `docs/specs/Dated/2026-08-09-esri-imagery-terms.md`
records a layer that shipped against an endpoint that simply did not check for a
key, and the absence of an error was read as a licence.
