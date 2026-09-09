<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# How data earns its place

Everything on the map has a source, a level of trust, and — for some things — a
ranking. Those are **three separate questions**, and the single most common
misunderstanding is treating them as one ladder.

They are not. A rider's vote does not "beat" OpenStreetMap. They answer
different questions entirely:

<!-- CODE-ILLUSTRATIVE mermaid diagram source, rendered by javascripts/diagrams.js -->
```mermaid
flowchart LR
    A["1 · WHERE IS IT FROM?<br/>provenance<br/>which record wins<br/>for one real object"]
    B["2 · DO WE BELIEVE IT?<br/>trust<br/>how far up the funnel<br/>this record has come"]
    C["3 · IS IT ANY GOOD?<br/>ranking<br/>only for things that are<br/>a matter of taste"]
    A --- B --- C
```

If you remember one thing: **provenance decides which row you see, trust decides
whether you see it at all, ranking decides what order the good ones come in —
and ranking only exists for subjective things.**

---

## 1 · Provenance — which record wins

We do **not** copy OpenStreetMap and edit our copy. We *reference* it, and our
own additions sit alongside. When the same real-world object exists in both, the
rider sees it **once**, as the curated record.

<!-- CODE-ILLUSTRATIVE mermaid diagram source, rendered by javascripts/diagrams.js -->
```mermaid
flowchart TD
    OSM["OpenStreetMap<br/>the base layer · ODbL<br/>the fountain exists, here"]
    PROV["Open data providers<br/>e.g. Géoportail Wallonie PIVOT, RIVM<br/>licensed, attributed"]
    RIDER["Riders<br/>new places, edits, photos"]

    OSM --> POOL
    PROV --> POOL
    RIDER --> POOL

    POOL{{"The Commons<br/>deduplicated on the OSM reference"}}
    POOL --> DEC

    DEC{"Do we hold our own<br/>curated record for<br/>this same object?"}
    DEC -->|yes| CUR["Ours is shown<br/>full pin, our fields,<br/>OSM still credited"]
    DEC -->|no| COM["The source record is shown<br/>lighter marker, tagged<br/>community"]

    style CUR fill:#1C3A2A,color:#EFE6D4
    style POOL fill:#FF5A1F,color:#101E16
```

!!! note "What this means for a curator"
    Approving a rider's edit to an OSM-sourced place does not change OSM and does
    not delete anything. It creates **our** record for that object, which from
    then on is what the map shows. The OSM attribution stays.

**Upstream fixes belong upstream.** If the underlying geometry or the basic fact
is wrong in OSM, the honest fix is an OSM edit — not a Commons override that
quietly diverges forever.

### The seven sources, by name

Three boxes is the shape. In the database each row carries one of seven
values, and they are what a drawer's citation line is built from:

| Value | What it is | Cited as |
|---|---|---|
| `manual` | Hand-authored by us. A seeded hero pin, a demo route. The harvest never touches it | rider |
| `user` | A rider added or edited it through the app | rider |
| `scout` | A rider's ride trace suggested it. How it arrived, not proof: the server never saw the ride file | rider |
| `authority` | A publisher of record for the thing mapped, named in the provider registry. Two at the time of writing: Géoportail Wallonie PIVOT (Tourisme Wallonie, official accommodation in Belgium, letter O, CC BY 4.0) and the RIVM drinking-water register (the Netherlands, letter B, public domain) | the publisher: Tourisme Wallonie, RIVM |
| `wikidata` | Anchored to a Wikidata entry | Wikidata |
| `osm` | Straight from the harvest. Most of the map | OpenStreetMap |
| `auto` | Our own pipeline computed it, for example a surface stretch. Deleted and rebuilt wholesale, never edited in place | derived |

The first three are the ones the map calls **rider** sources. The rest keep
their upstream citation, which is why approving a rider's edit to an OSM place
does not remove the OpenStreetMap credit: it adds ours beside it.

!!! note "Which row wins is a separate question again"
    When two rows turn out to describe one real place, the duplicate guard keeps
    one, in the order above, top to bottom. That is a *bookkeeping* rule, not a
    quality judgement: a brand-new `manual` pin outranks a long-verified `osm`
    row, because the question being asked is "which of these two records is ours
    to keep", never "which is better". Specified in
    `docs/specs/catalog-data-model.md` §5 and §5a.

!!! warning "A photograph is not a source"
    These seven say where a *place record* came from. A photo of that place is
    not a place. A Wikimedia Commons image reached through OpenStreetMap or
    Wikidata is shown beside the record it illustrates, carrying its own credit
    and its own licence per file: the drawer links it straight from Commons,
    and the media pipeline keeps a licence-checked, scanned copy of it in our
    own store. It is never a rider contribution and never enters a moderation
    queue, because there is no contributor and nothing was submitted.

---

## 2 · Trust — the funnel every record climbs

Nothing arrives trusted. Everything climbs the same funnel, and **two kinds of
thing get off at different stops**.

<!-- CODE-ILLUSTRATIVE mermaid diagram source, rendered by javascripts/diagrams.js -->
```mermaid
flowchart TD
    S(["Submitted"]) --> MOD{"Moderation<br/>spam · abuse · duplicate"}
    MOD -->|rejected| OUT(["off the public map"])
    MOD -->|accepted| U["Unverified<br/>public but unconfirmed<br/>small “help confirm” dot<br/>shows in Everything only"]

    U --> GATE{"Verification gate<br/>X independent riders tap<br/>still here / still true"}
    GATE --> V["Verified<br/>a full pin"]

    V --> SPLIT{"What kind of thing<br/>is this?"}
    SPLIT -->|"Utility<br/>water · services · shelter<br/>transit · hazards · surface"| STOP(["Stops here.<br/>Never voted on.<br/>The value is coverage."])
    SPLIT -->|"Experiential<br/>climbs · stays · views<br/>history · routes"| VOTABLE["Votable<br/>now collects rider votes"]

    VOTABLE --> BEST(["Best-of<br/>top-voted<br/>this is what Best of shows"])

    style OUT fill:#8b1a1a,color:#EFE6D4
    style STOP fill:#3E7D8C,color:#EFE6D4
    style BEST fill:#FF5A1F,color:#101E16
    style V fill:#1C3A2A,color:#EFE6D4
```

!!! warning "A water tap is never 'best-of'"
    Utility types **stop at Verified, permanently**. You do not rank a drinking
    fountain — you want to know it is there. Ranking is the whole point for a
    climb: you want the best ten, not all two hundred.

!!! note "What of this funnel is running today"
    The funnel above is the design. Two parts of it are live and one is not,
    and the difference matters if you are reading this to predict what the map
    will do:

    - **Routes gate exactly as described.** Three independent riders tapping
      *I rode this* promotes a route from Unverified to Verified, the count
      excludes the proposer, and the threshold is an admin setting rather than
      a constant.
    - **Everything else does not gate yet.** For a place — water, services,
      views, climbs, stays — a rider confirmation lifts how the map *presents*
      it (a full pin rather than a help-confirm dot) from the **first**
      independent confirmation, and the record's state is changed only by a
      curator. The tiered X below, and the modifiers under it, are the design
      for that gate, not a description of it.
    - **A curator's own confirmation settles it.** When the person confirming
      holds curator rights, the place becomes Verified on that one answer. Three
      riders should not be needed to agree that a castle is a castle, and a
      curator standing at a place is the strongest signal the system has.
    - **Anything you can stand in front of can be confirmed.** The old rule
      asked whether a place could *vanish*, which excluded castles and
      mountains. The right question is whether a rider was there and this is
      right — its existence, its position, its name — and a climb can be wrong
      about all three. An invented viewpoint with a generated photo is exactly
      what the next rider at that spot disproves.
    - **Decay is built for closures, and only for closures.** A hazard reported
      as *Road closed* carries the reporter's own answer to "closed for how
      long?" — today, days, weeks, months — and retires itself once that window
      passes, unless somebody confirms it is still shut, which restarts the
      clock. Retired means it stops being shown, not deleted: the closure was
      true when it was reported, and keeping it is what makes a repeat closure
      legible next year. Nothing else auto-stales yet; a hazard with no stated
      end date still needs a human to clear it.

    The potable/labelling rule in §3 and the view-mode gate in §4 *are* shipped
    as written.

### How many confirmations is X?

X is **not one number**, and it is not eleven knobs either. It is a small set of
tiers, plus modifiers.

| Tier | Types | Base X | Why |
|---|---|---|---|
| **Objective utility** | water *exists*, bike services, getting there | ~2 | existence is binary — cheap to confirm |
| **Experiential** | climbs, stays, views, history | ~2–3 | verification only confirms it *exists*; **voting** does the quality filtering, so no punishing bar |
| **Routes** | quality rides | ~3 × *"I rode this"* | the confirmation asserts *I rode it*, not *it exists* |
| **Safety / time-sensitive** | hazards, shelter, the water *potable* flag | ~1 to publish | publish fast, then **decay** — auto-stale after N days unless re-confirmed |

**Modifiers** adjust the base (never below 1):

<!-- CODE-ILLUSTRATIVE mermaid diagram source, rendered by javascripts/diagrams.js -->
```mermaid
flowchart LR
    BASE["base X<br/>from the tier"] --> M1["−1<br/>OSM provenance<br/>arrives source-vetted"]
    M1 --> M2["−1<br/>trusted contributor<br/>or curator"]
    M2 --> M3["−1<br/>bootstrap phase or<br/>low-density region"]
    M3 --> FLOOR(["effective X<br/>floor: 1"])
    style FLOOR fill:#1C3A2A,color:#EFE6D4
```

It is a trust ↔ throughput dial. Too low and we verify false positives; too high
and coverage starves in quiet regions.

---

## 3 · Risk lives at the field, not the item

This is the part that surprises people, and it is the answer to *"but what about
potable?"*

**"This fountain exists"** and **"this water is safe to drink"** are two
different claims with two different risk profiles. They do not share a gate.

<!-- CODE-ILLUSTRATIVE mermaid diagram source, rendered by javascripts/diagrams.js -->
```mermaid
flowchart TD
    ITEM["A drinking-water point"]
    ITEM --> F1["It exists<br/>objective · checkable<br/>→ gated at X ≈ 2"]
    ITEM --> F2["It is potable<br/>never fully verifiable by us<br/>→ labelled, not gated"]
    F2 --> LAB(["shown as<br/>“Unsigned — use judgement”"])
    style F1 fill:#1C3A2A,color:#EFE6D4
    style F2 fill:#C8923A,color:#101E16
```

We will never certify drinking water. Pretending a rider tap-count makes it safe
would be dishonest, so the map **says what it knows and marks what it doesn't**.
The same principle covers anything we cannot actually check.

!!! tip "The rule"
    If a claim can be confirmed by looking, **gate** it. If it cannot, **label**
    it. Never gate something into looking more certain than it is.

---

## 4 · What the map shows, and when

The view modes are a **density** control, not a trust control. There are three,
and they read the lifecycle above from the top down.

<!-- CODE-ILLUSTRATIVE mermaid diagram source, rendered by javascripts/diagrams.js -->
```mermaid
flowchart LR
    subgraph EV["Everything — the on-the-road map"]
        E1["verified pins"]
        E2["unverified “help confirm” dots"]
        E3["full utility coverage"]
        E4["all experiential places"]
    end
    subgraph CO["Confirmed — what somebody has vouched for"]
        O1["every place a rider or curator confirmed"]
        O2["plus the best-of picks"]
        O3["no unchecked reference data"]
    end
    subgraph CU["Best of — the trip-planning map"]
        C1["best-of experiential only"]
        C2["full utility coverage<br/>(unchanged)"]
    end
    style CO fill:#1C3A2A,color:#EFE6D4
    style CU fill:#1C3A2A,color:#EFE6D4
```

**Confirmed is the middle rung**, and it contains Best of rather than sitting
beside it: a curator verifying a place *is* somebody vouching for it. What it
leaves out is the reference layer — the OpenStreetMap data we mirror but nobody
here has checked — because a mode meaning *someone looked at this* cannot carry
the one layer where nobody has.

That also means Confirmed starts nearly empty in a new region, and stays that
way until riders fill it. We would rather show you an honest empty screen than
imply a check that never happened; it is also the one screen that makes pressing
*Confirm* worth something.

**Search always reaches everything, in both modes.** "Too much" is solved by
ranking and collapsing — curated first, community tagged — never by hiding.

### Which mode a region opens in

A region opens in **Everything** by default. Best of is something a region
*earns*, because a best-of map of an uncurated region is an empty map — and an
empty map reads as "there's nothing here" even when the rail says 1,488 places.

A moderator can flip a region to open in Best of once it has enough curated
content, **spread across enough kinds of content**:

<!-- CODE-ILLUSTRATIVE mermaid diagram source, rendered by javascripts/diagrams.js -->
```mermaid
flowchart TD
    R["A region"] --> T{"Enough curated<br/>picks in total?"}
    T -->|no| LOCK(["Locked<br/>opens in Everything"])
    T -->|yes| B{"Spread over enough<br/>different blocks,<br/>each carrying its share?"}
    B -->|no| LOCK2(["Still locked<br/>25 scenic views and nothing else<br/>is still an empty map"])
    B -->|yes| OK(["A moderator may flip it<br/>opens in Best of"])
    style LOCK2 fill:#C8923A,color:#101E16
    style OK fill:#FF5A1F,color:#101E16
```

The **blocks** are the data layers themselves — road surface, climbs, where to
sleep, scenic views, history & culture, best-of routes. Utility layers do not
count towards readiness, because they render in *both* modes: a full map of
water taps is no evidence that Best of has anything to show.

Breadth is *"N blocks of M"*, not *"every block"*, on purpose. A Dutch province
has no climbs and never will, and must still be able to qualify on stays, views,
history and routes.

The exact numbers are **set in the admin**, not in code. An administrator opens
*System configuration* and changes the total, how many blocks must contribute,
and how much each must carry; the new numbers apply from the next page load,
with no deploy and no restart, and every change is recorded with its old and
new value. The Regions desk shows every region's count per block and states
exactly what is still missing.

This is the general rule, not a special case for this one gate: the numbers the
site runs on — this readiness gate, how many routes a region may have live at
once, how many riders must confirm a route before it verifies itself, how long
decided moderation items are kept — are all editorial decisions, and editorial
decisions belong to the people making them rather than to a release cycle.

---

## The short version, for curators

<!-- CODE-ILLUSTRATIVE mermaid diagram source, rendered by javascripts/diagrams.js -->
```mermaid
flowchart TD
    Q1{"Is this the same real object<br/>as an OSM/provider record?"}
    Q1 -->|yes| A1["Our record supersedes it<br/>for display. OSM keeps the credit.<br/>Fix upstream facts upstream."]
    Q1 -->|no| A2["It stands on its own."]
    A1 --> Q2
    A2 --> Q2
    Q2{"Is it a utility<br/>or experiential thing?"}
    Q2 -->|utility| A3["Goal: completeness.<br/>Verify it, then leave it.<br/>Never ranked."]
    Q2 -->|experiential| A4["Goal: the ranking.<br/>Verify it, then riders vote.<br/>Best-of is derived, never hand-picked."]
    A3 --> Q3
    A4 --> Q3
    Q3{"Is there a claim on it<br/>we cannot actually check?"}
    Q3 -->|yes| A5["Label it, don't gate it.<br/>e.g. “Unsigned — use judgement”"]
    Q3 -->|no| A6["Gate it at the tier's X."]
    style A4 fill:#FF5A1F,color:#101E16
    style A3 fill:#3E7D8C,color:#EFE6D4
    style A5 fill:#C8923A,color:#101E16
```

!!! danger "Best-of is derived, never hand-picked"
    Moderation is a **spam/abuse/duplicate gate**, not a quality ranking. What
    rises to best-of is decided by riders' votes. The one deliberate exception is
    **routes**, where supply *is* editorially reviewed in the Routes queue —
    riders then rank within that reviewed set.

---

**Where this is specified.** The lifecycle and the X tiers:
`docs/specs/edit-items/README.md`. Provenance and deduplication:
`docs/specs/osm-data-architecture.md`. What the map shows:
`docs/specs/map-and-search.md`, §4.2 for the view modes and the
Best-of-by-default gate.
