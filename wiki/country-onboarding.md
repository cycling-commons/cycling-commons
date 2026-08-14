<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# How a country joins the Commons

The Commons doesn't try to cover the world on day one. It grows one country
at a time, and the process is meant to be visible rather than mysterious —
this page explains what you're looking at on
[cyclingcommons.org/regions](https://cyclingcommons.org/regions), and how a
country you don't see there yet can get added.

## The stages a region moves through

Every region on the map is described by **two independent things**, and
`/regions` shows them as two columns rather than one ladder: how much the
region holds, and who looks after it. They really are independent — a busy
region can have nobody looking after it, and a freshly curated one can hold
almost nothing yet.

**How much it holds**

| Stage | What it means |
|---|---|
| **Not on the map yet** | The country hasn't been onboarded. It doesn't appear on `/regions`, but you can still ask for it — see below. |
| **Onboarded** | The region is live on the map from the day it's added — full read/write, full contribution flow — but riders haven't confirmed anything there yet. |
| **Growing** | Riders have started adding and confirming places. The commons for that region is no longer empty. |
| **Established** | Enough verified places and rider-backed routes that the map opens there in **Best of** view by default — best climbs, best stays, best views, ranked rather than just listed. It is earned rather than declared: a curator can only set it once the region passes a readiness count. |

**Who looks after it**

| Cover | What it means |
|---|---|
| **Curated** | The region has its own curator. |
| **Country-wide** | A curator covers the whole country, and this region has no local curator yet. Real cover, and deliberately said differently: somebody who rides there sees what a country-wide view never will. |
| **No curator yet** | Nobody has taken this region on. Riders can still add and confirm places, and the region page says so with a way in. |

A region page names its curators where they have made their profile public,
each with the reach they hold.

The important part: **the map serves every onboarded region from day one.**
Onboarding is not a waiting room before the map "turns on" — it's the
opposite. The moment a region is onboarded you can add a water point, flag a
closed road, or drop a photo of a view, exactly as you could anywhere else on
the map. Growing and Curated describe how much rider knowledge has
accumulated on top of that live map, not whether it's usable.

## What makes a country onboard

Onboarding a country is a deliberate, hands-on step: it means loading that
country's official geography — the country outline and its riding-scale
subdivisions (provinces, states, or the equivalent) — as real regions in the
Commons, so contributions, moderation, and curation all have somewhere to
anchor. It isn't automatic, and it isn't triggered by traffic alone.

What tells us where to look next is **you**. Every country in the world has
an ask-for-your-country page — follow the "Don't see your country?" search
on `/regions`, or go straight to `cyclingcommons.org/join/<your country
code>`. Tell us you're interested, and optionally that you'd be willing to
help. That interest is recorded and read by the people deciding what to
onboard next — we don't publish counts or league tables of which countries
are "winning," because the decision weighs more than raw volume: how ready a
local community is, how good the underlying map data is, and plain
practicality.

Asking doesn't obligate anything on either side. It's a signal, not a queue
ticket with a number.

## What a volunteer curator signs up for

If your country is already onboarded, that same `/join/<country code>` page
becomes something different: an application to **curate** it. See
[Curation & voting](curation-and-voting.md) for what curation actually does —
this is how you get the role.

The application is short: who you are (a few sentences is enough), and
optionally your OpenStreetMap username, since a lot of curation overlaps
with judgment calls about map data. There's no quiz and no minimum edit
count required to apply — though what you've already fixed or added on the
map is exactly what a reviewer looks at, so contributing (before or after
applying) is the strongest way to support your case.

Applying needs an account. If you hit the application page signed out, the
site walks you through creating one — including the email confirmation —
and then brings you **back to the application** to finish it. Once
submitted, you get an acknowledgement in your messages (and by email), and the
application and its status (pending, approved, declined) live on your profile,
alongside any note the reviewer leaves. While it is being read, the application
page shows you what you sent rather than an empty form.

**Curating requires two-factor authentication.** It is not optional and it is
not a setting: the moderation desks are unreachable until it is set up, because
a curator can approve, reject and permanently destroy other people's
contributions, and an account that can do that is worth stealing. You are asked
to set it up the first time you sign in after approval.

Approval scopes a curator to a place — a whole country, or one region within
it, whichever you asked for; the application form asks which. If your areas
change later, you are told which regions you now cover. A curator reviews the queue of flagged and
proposed changes for their scope; they don't get any special power over
regions outside it, and curating one country's queue never quietly becomes
authority over the whole Commons. See [Governance](governance.md) for how
regional and core-team responsibilities are meant to stay separate.

## What the data promises

Region boundaries — country outlines and their subdivisions alike — come
from open geographic data (conflating OpenStreetMap and other open sources),
the same [ODbL](https://opendatacommons.org/licenses/odbl/1-0/) licence that
covers the rest of the Commons dataset. Everything riders add on top —
places, edits, photos — carries the same open licensing described in full on
the [licensing page](https://cyclingcommons.org/licenses): ODbL for data,
CC BY-SA 4.0 for photos and video.

A region's identity — its slug, the thing in the URL — is meant to stay
fixed once assigned. Re-running an onboarding import to refresh boundaries
updates the region in place rather than replacing it, specifically so a link
to a region keeps working over time instead of quietly rotting.
