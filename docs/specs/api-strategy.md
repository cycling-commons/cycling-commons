<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# API Strategy — commercial access and the commons

**Status:** canonical reference · **Audience:** contributors to Cycling Commons
· some sections marked *proposed* pend an owner decision (§10).

This document owns the **business and access posture** of the public API: how
Commons data reaches third parties (including commercial products such as
RideWithGPS), what we charge for, and why letting commercial consumers use the
data does not undermine the project.

It does **not** restate the legal posture or the API's technical shape — those
are owned elsewhere and cross-linked:

- **Licensing** (data ODbL/DbCL, code PolyForm Shield):
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
- **Code is non-compete (PolyForm Shield).** Nobody may stand up a competing
  Cycling Commons using our code.

Therefore the API is **not** a way to sell proprietary data. It is **the hosted,
always-fresh, low-friction face of a share-alike commons**. What a commercial
consumer pays for is *operational convenience and freshness*, never exclusive
access to bytes. The defensible moat is **freshness + community + brand**, not
the dataset, which is open by design.

A commercial product consuming the API is a **feature, not a threat**: our code
is non-compete, and under ODbL a consumer's derived *database* must itself stay
open (§6). Consumption feeds the commons rather than draining it.

## 2. "Open license" ≠ "open access to our servers"

These are independent and must never be conflated:

| | Governs | Set by |
|---|---|---|
| **License** (ODbL/DbCL) | what someone may *do* with data they hold | osm-data-architecture.md §3 |
| **Access** (ToS, quotas, no-scraping) | *how they may obtain it from us* | osm-data-architecture.md §7 + this doc |

Open-licensed data can sit behind a no-scraping rule — this is exactly how OSM
itself operates (ODbL data, but you use planet/Geofabrik dumps, not live
scraping), and how our own ingestion already works
([coverage-provider.md](coverage-provider.md)). Because scraping the live
service is prohibited, we **must** provide sanctioned non-scraping channels to
obtain the open data. Those channels are §3.

## 3. Distribution channels

There are exactly two sanctioned ways to obtain Commons data. Both are
non-scraping; scraping the site/tiles/endpoints outside these remains prohibited
(osm-data-architecture.md §7).

### 3.1 Periodic bulk export — free, open, ODbL

A published, downloadable snapshot of the non-personal Commons dataset (our
enrichment + our own data; `osm_ref`s, not republished OSM tags/geometry, per
osm-data-architecture.md §7) as GeoJSON / PMTiles / PostGIS dump on the CC
bucket. One deliberate download, self-hosted by the consumer — **this is how a
third party self-hosts without touching our live service.** It is the ODbL-honest
baseline and satisfies the "the data is genuinely open" promise.

Key lever: **ODbL obliges us to license openly *if* we distribute — it does not
force us to distribute *live* or *fresh*.** The free export is therefore
deliberately **periodic (stale)**; live freshness is the paid product (§3.2,
§4). Cadence is an open decision (§10).

### 3.2 Metered self-serve API — free tier + paid quotas

The live, always-fresh service. Response shape and access terms are owned by
osm-data-architecture.md §7; this doc owns the **quota tiers and pricing**
(§4). Freshness is backed by materialize-on-edit
([osm-data-architecture.md §6](osm-data-architecture.md)). Coverage tiles ride
existing PMTiles/`upstream-maps` infra.

## 4. Pricing model — metered quota tiers *(proposed)*

Adopt a self-serve, metered, **requests-per-month** model (the proven
Thunderforest shape), not a bespoke/enterprise model. Illustrative tiers —
numbers pend §10:

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
tooling — operational convenience. **What we never charge for:** the data itself
(the periodic dump is always free and open, §3.1).

## 5. Business-development posture — passive, self-serve

We do **not** court big platforms. No outbound BD, no design-partner program, no
sales team. Publish the free dump, ship a self-serve keyed API with quota tiers,
and let consumers find it. This matches a small team and is the owner's stated
intention. Inbound commercial use is welcome on the standard tiers; it is not
solicited.

## 6. Contribution-back — realistic expectations

The write/contribution API (routing user submissions back through the existing
contribution → moderation loop,
[moderation-and-contribution.md](moderation-and-contribution.md)) should be
built and made **as frictionless as possible**. But the business case must stand
up **without assuming competitors use it**:

- **Direct competitors will not** wire "contribute to Cycling Commons" into
  their UI — they have no incentive to grow a rival's dataset. Do not model
  revenue or growth on their goodwill.
- **Realistic contributors:** our own app's users (we own that UX); aligned,
  non-competing consumers (bike-shop finders, tourism boards, clubs); and, for
  *data* derivatives, **ODbL share-alike**, which forces a consumer's derived
  database open even if they never add a single button.

So share-alike — not a contribution button — is the mechanism that makes
commercial consumption feed the commons. The write API is a convenience for
friendly consumers, not a lever against competitors.

## 7. Attribution enforcement — posture

ODbL attribution is easy to violate quietly and hard to police at scale. Posture:

- **Default reward is visibility/goodwill**, not enforcement. Do not build
  process around policing attribution now.
- **Enforcement is reserved**, case-by-case, and scales with the violator's size
  and our legal appetite: small violators are not worth chasing; a large,
  blatant, worth-it case is a legal question weighed when it arises.

## 8. Account/Commons boundary — hard rule

The public/commercial API exposes **only the Commons layer** (non-personal:
routes, utilities, hazards, coverage, our enrichment). It **never** exposes the
account layer — email, IP logs, password hashes, moderation internals. The
Commons dataset is non-personal, but the platform holds personal data at the
account level; the API boundary is where that separation is enforced. See
[security-architecture.md](security-architecture.md) and
[account-and-auth.md](account-and-auth.md).

## 9. Why a commercial consumer (e.g. RideWithGPS) is safe

1. They obtain data via sanctioned channels (§3), never by scraping.
2. They cannot reuse our **code** (PolyForm Shield).
3. Their derived **database** must stay open under ODbL share-alike (§6).
4. They pay us only if live freshness/throughput beats self-hosting the free
   periodic dump — a convenience sale, not a data sale.
5. They never reach the account layer (§8).

The competitive set that matters is route/data platforms (Komoot, Bikemap,
RideWithGPS, cycle.travel) and the OSM ecosystem — not tile vendors. Even there,
we compete on holding a **fresh Commons delta nobody else has**, not on selling
the same OSM everyone can already download.

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
  not live programmatic access) — a one-line clarification there once §3.1 is
  ratified.

## Relationship to other specs

This is the business/posture layer over the technical API contract. It consumes
osm-data-architecture.md §3 (licensing) and §7 (API shape + access terms)
unchanged, and delegates the account boundary to the security/account specs. The
*endpoint* contract (routes, quotas, auth keys, tile schema, versioning) is owned
by [public-api.md](public-api.md), not here.
