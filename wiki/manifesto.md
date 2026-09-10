<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# The Cycling Commons Manifesto

A commons is not a free-for-all and it is not a company's database with an open licence bolted on. It
is a shared resource that a community governs, sustains, and protects from enclosure. Cyclists already
produce an enormous amount of knowledge about the world they ride through: where the water is, which
climb is worth the detour, where to sleep after a long day. Today that knowledge is scattered, and what
a rider adds to an app stays locked inside that app. The Cycling Commons exists to set it free and
keep it free.

## The theory underneath: Ostrom, not Hardin

The lazy assumption, Garrett Hardin's "Tragedy of the Commons" (1968), says a shared resource is
doomed to be overused and ruined. **Elinor Ostrom showed that's not inevitable.** Her work *Governing
the Commons* (1990) won the 2009 Nobel Prize in Economics by showing that communities the world over
have sustainably governed shared resources for centuries, *without* privatising them and *without* a
central owner, when a handful of design principles are in place: clear boundaries, rules made by the
people affected, monitoring, graduated sanctions, and cheap conflict resolution.

Hardin's tragedy is about *rivalrous* resources, a pasture, a fishery, where one person's taking
depletes another's share. Open data is the opposite: non-rivalrous, and better the more it is used.
You cannot overgraze a dataset. So the risk to a cycling commons is not overuse. It is **enclosure**
(someone privatising what the community built) and **neglect** (too few contributors, or spam drowning
the signal). Ostrom's principles are what guard against *those* failures, the threat model an open
dataset actually faces.

The Cycling Commons is a deliberate, modern application of Ostrom's principles to cycling data. The
[Governance](governance.md) page maps each principle to a concrete part of the system. This is why the
Commons can be open *and* survive: it is governed, not merely published.

## The principles

**I. The Commons is the map, not the rider.** Everything in the Commons is about *the world*: roads,
climbs, water, hazards. Never about a person's fitness, identity, or movements. Nothing of that kind
enters the dataset or the API: the map is public; the person is not. Accounts exist so one rider
counts once. What a rider contributes stays in the Commons under its licence; what is about the rider,
the account, can be exported or erased at any time. A rider who wants credit gets it by display name,
never by anything more. The design for letting aggregate activity *inform* the map without any trace
entering it is
[the sensing boundary](governance.md#the-sensing-boundary-how-activity-becomes-a-place-fact); nothing
of that kind is collected today.

**II. Cycling is for every body.** Accessibility is first-class: adapted-bike and handbike
friendliness, gradient limits, and surface suitability are data the Commons actively collects, not an
afterthought.

**III. Open for everyone.** The data is published under the Open Database Licence (ODbL). Photos,
video and the written word, this wiki included, are Creative Commons Attribution-ShareAlike 4.0
(CC BY-SA 4.0), so a picture a rider gives travels as freely as the row it illustrates. The software
that serves all of it is free software as well, under the GNU Affero General Public Licence v3
(AGPL-3.0-only): anyone may run it, study it, change it and fork it, and anyone who offers a changed
version to others over a network owes them that source in turn. The name, the logo and the wordmark
are the one thing held back, so a fork rides under its own name. A free
read API is live in an early, two-endpoint form
([cyclingcommons.org/developers/api](https://cyclingcommons.org/developers/api)); bulk exports are
designed, not yet live. Anyone may build on it, commercial or not.

**IV. Build on OpenStreetMap; give back to it.** OSM is the open base map. We do not re-collect it;
we curate the cycling layers it is thin on, and we intend to contribute durable facts back upstream.
The ODbL is chosen precisely so data can flow back to OSM. (The give-back itself is design, not yet
built; see [Contributing](contributing.md).)

**V. Curation where taste rules, completeness where need does.** For the things that are a matter
of taste (best climbs, bike-friendly stays, finest views, history & culture, top quality rides) the
Commons is not a contest to hold the most rows: it surfaces the *best* of a region, as judged by the
riders who know it, not an undifferentiated firehose. For the things a rider simply needs (water,
toilets, shelter, a shop, a supermarket) it wants every one there is, checked in proportion to what a
wrong answer costs. A supermarket needs no review. A tap is shown as drinking water only once a rider
has confirmed it is drinkable and still running, and it stays so only while riders keep confirming it
(principle VII).

**VI. The community governs the Commons.** The people who contribute decide what rises to the top, by
voting, in open rounds. Curators serve the community; they do not rule it. (Routes work this way
today; the seasonal rounds for climbs, stays, views and heritage are design, planned to open after
launch. See [Curation & Voting](curation-and-voting.md).)

**VII. Freshness is a duty.** A "road closed" that never expires becomes a lie, and so does a tap that
ran in May and is dry in August. Perishable data carries a lifecycle (timestamps, confirmations, and
decay) so the map heals itself instead of rotting: a fact nobody has confirmed for long enough is
asked about again, and shown with less certainty until somebody answers.

**VIII. Provenance kept.** Every fact carries where it came from: the source it was imported from
and the licence it arrived under, or the trail of rider confirmations, *that* and *when*. A reader can
see why the map believes something, and a partner's data is cited as theirs, never absorbed as ours.
Trust without surveillance.

**IX. Respect the ground. Never trespass.** Nothing in the Commons encourages entering a private,
restricted, or no-entry road. If access information ever enters the catalog, it is there so those
roads can be *avoided*. The Commons makes riders better guests of the places they ride.

**X. Never scraped, never sold, never enclosed.** Data is contributed or already open, never
scraped. The Commons is not a foundation's asset to one day paywall. Its licence and its governance are
built so it *cannot* be enclosed later, even by the people who started it.
