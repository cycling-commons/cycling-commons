<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# API Strategy: commercial access and the commons

**Status:** canonical reference · **Audience:** contributors to Cycling Commons
· some sections marked *proposed* pend an owner decision (§10).

This document owns the **business and access posture** of the public API: how
Commons data reaches third parties (including commercial products such as
RideWithGPS), what we charge for, and why letting commercial consumers use the
data does not undermine the project.

It does **not** restate the legal posture or the API's technical shape. Those
are owned elsewhere and cross-linked:

- **Licensing** (data ODbL/DbCL, code AGPL-3.0-only):
  [osm-data-architecture.md §3](osm-data-architecture.md).
- **API response shape** (reference-only default, optional hydrated endpoint)
  and **access terms** (API-only, scraping prohibited):
  [osm-data-architecture.md §7](osm-data-architecture.md).
- **Account/Commons data boundary** (what the API may never expose):
  [security-architecture.md](security-architecture.md),
  [account-and-auth.md](account-and-auth.md).

---

## 1. The thesis: the bytes are not the moat

Two license choices already made (osm-data-architecture.md §3) fix the shape of
everything here:

- **Data is open (ODbL/DbCL).** Anyone may use, redistribute, and build
  derivatives of the Commons data, with attribution and share-alike. We neither
  can nor want to make the data proprietary.
- **Code is free software (AGPL-3.0-only).** Anyone may read it, fork it, modify
  it and run it, commercially and in direct competition with us. The only thing
  the licence asks in return is reciprocity: a fork that is conveyed, or that is
  run as a network service, must offer its users the complete corresponding
  source under the same terms (AGPL section 13).

**Neither licence holds anything back, and the strategy has to be honest about
that.** There is no clause anywhere in this project that stops a rival standing
up its own Cycling Commons from our code and our data. The licences do not
protect the business, and were never chosen to. What they do is guarantee that
anything built on this work stays in the open, so a rival's improvements come
back to the commons instead of disappearing into a closed product.

Therefore the API is **not** a way to sell proprietary data, and it is not a way
to sell a licence. It is **the hosted, always-fresh, low-friction face of a
share-alike commons**. What a commercial consumer pays for is *operational
convenience and freshness*, never exclusive access to bytes.

### 1.1 Where the durable advantage actually sits

A fork gets the code on day one and a snapshot of the data on day one. What it
does not get, and cannot copy, is the thing that keeps producing:

- **The live data and its freshness.** A dump ages from the moment it is taken.
  What has value is the delta arriving every day, and that arrives here because
  the riders and curators are here.
- **The curation.** Coverage is only useful once a human has decided a tap is
  real, a hazard has cleared, a climb is measured correctly. That work is a
  standing cost a fork has to fund from scratch, and it is the difference
  between our rows and raw OSM.
- **The community.** The contributors, the moderators, the regional curators and
  their local knowledge. People, not files. They follow the project they trust,
  and they can leave, which is a real discipline on how we behave.
- **The name.** The marks are reserved
  ([TRADEMARK.md](../../TRADEMARK.md)); a fork must ship under its own name, so
  a rider always knows whether they are looking at *this* Commons with this
  data, this curation and this privacy promise.

None of that is a legal moat. It is an operational one, and it has to be earned
again every month. That is the correct incentive.

A commercial product consuming the API is a **feature, not a threat**: under
ODbL a consumer's derived *database* must itself stay open (§6), and under AGPL
a consumer who runs our code must publish their source. Consumption feeds the
commons rather than draining it.

## 2. "Open license" ≠ "open access to our servers"

These are independent and must never be conflated:

| | Governs | Set by |
|---|---|---|
| **Data licence** (ODbL/DbCL) | what someone may *do* with data they hold | osm-data-architecture.md §3 |
| **Code licence** (AGPL-3.0-only) | what someone may *do* with the software they hold, including running it as a rival service | osm-data-architecture.md §3 |
| **Access** (ToS, quotas, no-scraping) | *how they may obtain it from us* | osm-data-architecture.md §7 + this doc |

Open-licensed data can sit behind a no-scraping rule. This is exactly how OSM
itself operates (ODbL data, but you use planet/Geofabrik dumps, not live
scraping), and how our own ingestion already works
([coverage-provider.md](coverage-provider.md)). Because scraping the live
service is prohibited, we **must** provide sanctioned non-scraping channels to
obtain the open data. Those channels are §3.

## 3. Distribution channels

There are exactly two sanctioned ways to obtain Commons data. Both are
non-scraping; scraping the site/tiles/endpoints outside these remains prohibited
(osm-data-architecture.md §7).

### 3.1 Periodic bulk export: free, open, ODbL

A published, downloadable snapshot of the non-personal Commons dataset (our
enrichment + our own data; `osm_ref`s, not republished OSM tags/geometry, per
osm-data-architecture.md §7) as GeoJSON / PMTiles / PostGIS dump on the CC
bucket. One deliberate download, self-hosted by the consumer. **This is how a
third party self-hosts without touching our live service.** It is the ODbL-honest
baseline and satisfies the "the data is genuinely open" promise.

Key lever: **ODbL obliges us to license openly *if* we distribute. It does not
force us to distribute *live* or *fresh*.** The free export is therefore
deliberately **periodic (stale)**; live freshness is the paid product (§3.2,
§4). Cadence is an open decision (§10).

### 3.2 Metered self-serve API: free tier plus paid quotas

The live, always-fresh service. Response shape and access terms are owned by
osm-data-architecture.md §7; this doc owns the **quota tiers and pricing**
(§4). Freshness is backed by materialize-on-edit
([osm-data-architecture.md §6](osm-data-architecture.md)). Coverage tiles ride
existing PMTiles/CDN infra.

## 4. Pricing model: metered quota tiers *(proposed)*

Adopt a self-serve, metered, **requests-per-month** model (the proven
Thunderforest shape), not a bespoke/enterprise model. Illustrative tiers, with
the numbers pending §10:

| Tier | Shape | Includes |
|------|-------|----------|
| **Free** | low monthly request quota | full Commons coverage, periodic-fresh, no SLA |
| **Solo** | higher quota | live freshness, email support |
| **Business** | large quota | fresh/incremental exports (vs. the free stale dump), higher rate limits |
| **Large** | very high quota | SLA, priority support |

The billable unit is **requests per month** (API calls and/or coverage-tile
requests). We are *borrowing Thunderforest's pricing shape, not competing on
their axis*: Thunderforest sells tile **rendering**; we sell access to a **fresh
Commons delta** (routes, utilities, hazards, coverage) joined against OSM, which
no tile vendor holds.

**What we charge for:** freshness, throughput/quota, SLA, compliance/attribution
tooling, all of it operational convenience. **What we never charge for:** the
data itself (the periodic dump is always free and open, §3.1).

## 5. Business-development posture: passive, self-serve

We do **not** court big platforms. No outbound BD, no design-partner program, no
sales team. Publish the free dump, ship a self-serve keyed API with quota tiers,
and let consumers find it. This matches a small team and is the owner's stated
intention. Inbound commercial use is welcome on the standard tiers; it is not
solicited.

## 6. Contribution-back: realistic expectations

The write/contribution API (routing user submissions back through the existing
contribution → moderation loop,
[moderation-and-contribution.md](moderation-and-contribution.md)) should be
built and made **as frictionless as possible**. But the business case must stand
up **without assuming competitors use it**:

- **Direct competitors will not** wire "contribute to Cycling Commons" into
  their UI, because they have no incentive to grow a rival's dataset. Do not
  model revenue or growth on their goodwill.
- **Realistic contributors:** our own app's users (we own that UX); aligned,
  non-competing consumers (bike-shop finders, tourism boards, clubs); and, for
  *data* derivatives, **ODbL share-alike**, which forces a consumer's derived
  database open even if they never add a single button.

So share-alike, not a contribution button, is the mechanism that makes
commercial consumption feed the commons. On the data side that is ODbL; on the
code side it is AGPL section 13, which turns running a modified Cycling Commons
as a website into an obligation to publish the modifications. The write API is a
convenience for friendly consumers, not a lever against competitors.

## 7. Attribution enforcement: posture

ODbL attribution is easy to violate quietly and hard to police at scale. Posture:

- **Default reward is visibility/goodwill**, not enforcement. Do not build
  process around policing attribution now.
- **Enforcement is reserved**, case-by-case, and scales with the violator's size
  and our legal appetite: small violators are not worth chasing; a large,
  blatant, worth-it case is a legal question weighed when it arises.

## 8. Account/Commons boundary: a hard rule

The public/commercial API exposes **only the Commons layer** (non-personal:
routes, utilities, hazards, coverage, our enrichment). It **never** exposes the
account layer: email, IP logs, password hashes, moderation internals. The
Commons dataset is non-personal, but the platform holds personal data at the
account level; the API boundary is where that separation is enforced. See
[security-architecture.md](security-architecture.md) and
[account-and-auth.md](account-and-auth.md).

## 9. Why a commercial consumer (e.g. RideWithGPS) is not a threat

Not because they are fenced out. They are not: the code is AGPL and the data is
ODbL, so a commercial consumer may take both. The reasons are these.

1. They obtain data via sanctioned channels (§3), never by scraping.
2. They **may** reuse our **code**, and the AGPL is what makes that acceptable.
   If they modify it and run it as a service, section 13 obliges them to offer
   their users the corresponding source, so their work returns to the commons.
   If they will not accept that, they write their own instead of taking ours.
3. Their derived **database** must stay open under ODbL share-alike (§6).
4. They must ship under their own name. The marks are reserved
   ([TRADEMARK.md](../../TRADEMARK.md)), so nobody can trade on our reputation
   while running their own version.
5. They pay us only if live freshness and throughput beat self-hosting the free
   periodic dump. That is a convenience sale, not a data sale, and it is the
   only sale on offer.
6. They never reach the account layer (§8).

The competitive set that matters is route/data platforms (Komoot, Bikemap,
RideWithGPS, cycle.travel) and the OSM ecosystem, not tile vendors. Against all
of them we compete on the same footing as anyone forking us: a **fresh Commons
delta**, curated by people who are here, that has to be re-earned rather than
defended by a clause.

## 10. Open decisions (pending owner)

- **Bulk-export cadence and format** (§3.1): monthly? quarterly? which
  artifacts (GeoJSON / PMTiles / PostGIS)?
- **Quota thresholds and prices** (§4): concrete request ceilings and €/month
  per tier.
- **Billable unit** (§4): API calls, tile requests, or a blended metric.
- **Fresh-export tier** (§4): is incremental/fresh bulk a paid tier feature, and
  at what freshness gap vs. the free dump?
- Reconcile osm-data-architecture.md §7's "programmatic access **only** via the
  public API" wording with the §3.1 bulk-export channel (a published artifact,
  not live programmatic access). That is a one-line clarification there once
  §3.1 is ratified.

## Relationship to other specs

This is the business/posture layer over the technical API contract. It consumes
osm-data-architecture.md §3 (licensing) and §7 (API shape + access terms)
unchanged, and delegates the account boundary to the security/account specs. The
*endpoint* contract (routes, quotas, auth keys, tile schema, versioning) is owned
by [public-api.md](public-api.md), not here.
