# Roadmap, changelog, and the release list

Canonical. Covers `/roadmap` with its votes, follows and rider ideas
(`/roadmap/propose`, the ideas desk at `/moderate/ideas`), `/whats-new` (and
the `/changelog` redirect), `/changelog.atom`, the updates opt-in on
`/account/settings`, and `/unsubscribe`.

Related: [privacy-notice.md](privacy-notice.md) for the consent line,
[contact-and-support.md](contact-and-support.md) for the other ways in.

## 1. Why this exists

Riders had no way to learn that anything had changed, and no way to see what was
coming. Owner, 2026-08-27: *"no way to inform them about updates"*. The answer
is three surfaces that share one list, and a fourth that pushes it.

Owner's constraint on the roadmap, 2026-08-28: **"for now it must be a very
simple list."** Voting, following and rider proposals were the destination,
not the first version; they are built now (§8, owner 2026-10-13).

## 2. Two sources, three readers

The roadmap lives in the database (`roadmap_item`, `roadmap_item_text`) and is
written on the roadmap desk (§8). The changelog lives in
`App\Content\ReleaseNotes`, a constant per release. `/roadmap` reads the
first, `/whats-new` and the Atom feed read the second. The English page is
`/whats-new`, the name the page and the footer use (each locale has its own
path, `LocalizedPath::CHANGELOG`). `/changelog` answers with a permanent
redirect to it (`PageController::changelogMoved()`), because bug reports,
search results and bookmarks carry that address. The feed is
`/changelog.atom`, unprefixed and English: feed readers are subscribed to that
exact address, and nobody reads a feed address.

**Why the roadmap is a table and the changelog a file.** Owner 2026-10-13: the
roadmap changes while riders vote on it, so moving, adding or rewording an
item is a desk action, not a deploy. Until then it was a PHP constant edited by
pull request, which was right for a short list nobody could touch; the 14
items it held were copied into the table by migration `Version20261013010000`,
in the same groups and order and in all five languages. Release notes stay in
the file: they are written with the release they describe, and they get the
same review as the code.

**Why the changelog uses keys and not prose.** Every note is a catalogue key,
so the page reads in five languages the day it ships instead of being an
English island. `RoadmapChangelogTest` fails on a key that is missing in any of
the five, which matters because an untranslated key renders as the key and
the page still answers 200. A roadmap item has one text per language in
`roadmap_item_text`; a language without its own shows the English text, else
the first there is, marked with its own `lang`.

**The GitHub link is a number, not a sync.** An item may carry `issue`, and the
page links it. Nothing polls GitHub, nothing breaks when GitHub is down, and an
item without an issue is still a valid item.

**Shipped is not repeated.** Finished work leaves the roadmap for the changelog,
and the roadmap links there. One fact, one place.

## 3. Versions, and the thing that has to be done by hand

`ReleaseNotes::RELEASES[].version` is the git tag **without** its leading `v`.
The footer stamp comes from somewhere else entirely: in a working copy
`BuildVersion` runs `git describe --tags --match 'v*' --always`.

They agree only if the release is tagged. An untagged commit shows a bare
commit hash or a `describe` suffix in the footer, whatever the page says.

    git tag -a v0.9.5-beta -m "0.9.5-beta"

`RoadmapChangelogTest::testReleaseVersionsLookLikeGitTags` asserts the shape, not
that the tag exists: the tag lives in git, and a test cannot make somebody create
it. The push can: the `release-tag` pre-push hook (`tools/release-tag-prepush.sh`)
stops a push to `production` whose commit carries no `v*` tag, and prints the
commands (owner 2026-09-29, after an untagged release). Pushes to `main` and
`staging` pass untouched; `SKIP=release-tag` pushes once without a tag. A tag
added after a deploy is still right, and the footer shows it from the next
deploy on.

A deployed release has no `.git`, only a `REVISION` file with the commit. Its
footer number is then the `VERSION` file the deploy wrote beside it (the
`git describe` output taken before `.git` is stripped), else
`APP_BUILD_VERSION`, else the first twelve characters of the commit. The
footer's source link always uses `REVISION`'s commit, so the label can never
move the link off the running build.

**`APP_BUILD_VERSION` lives in `web/.env` alone**, with its leading `v`
(`APP_BUILD_VERSION=v0.9.5-beta`). Staging runs each release before
production, on the same tagged commit, so the two environments never rightly
name different versions, and one value means one edit per release.
`ReleaseVersionDriftTest` asserts that `.env` names `v` plus the version
`ReleaseNotes::RELEASES[0]` announces, that `.env.staging`, `.env.prod` and
`.env.test` carry no copy of their own, and that no version is announced
twice. A host's `.env.local` could still override it; nothing in the deploy
needs to.

## 4. The release list

An **updates** list: news about Cycling Commons itself, new versions first,
and never anything from or for somebody else. It is not limited to release
notes (owner 2026-10-01: "don't be so narrow, make it more like Keep me up to
date"); the cadence the rider picked is the ceiling.

| Piece | Where |
|---|---|
| The flag | `users.updates_opt_in`, default **false** |
| How often | `users.updates_cadence`: `big` (default) or `every` (`App\Account\UpdatesCadence`) |
| The consent | a `consent_record` row, kind `release-updates` (`App\Account\UpdatesConsent`) |
| Turning it on and off | `App\Account\UpdatesSubscription`, called from the settings save |
| The one-click link | `/unsubscribe?u=<uuid>&t=<hmac>` |

**One switch with a choice, not two lists.** The settings page (Profile tab,
beside Public profile) has one switch, "Keep me up to date. You can stop at
any time from here or from a link in the message.", and under it two radios: **Big news only: at most 4 times a year**
(pre-selected) and **Every update: at most 2 times a month**. One list with a
cadence means nobody gets the same news twice. Each cadence is a ceiling the
rider agreed to, not a schedule. The radios show while the switch is on
(`assets/settings/updates-cadence.js`); without script they stay shown, and a
choice saved while the switch is off is a stored preference that sends nothing.
Nothing sends these mails yet.

**What each action stores and records.**

| Action | `updates_opt_in` | `updates_cadence` | `consent_record` |
|---|---|---|---|
| Opt in | true | the chosen cadence | new row, version `v2-big` or `v2-every` |
| Change cadence while on | stays true | the new cadence | new row for the new cadence |
| Change cadence while off | stays false | the new cadence | none |
| Save without changing either | unchanged | unchanged | none |
| Opt out on the settings page | false | kept | none; earlier rows stay |
| The unsubscribe link | false | kept | none; earlier rows stay |

**Consent versions.** `v1` is the earlier single sentence "Email me
when a release ships. Release notes only, a few times a year, ...", still in the
catalogue as `settings.updates_consent` so a v1 record points at what was
agreed; its hash is sha256 of `release-updates|v1|settings.updates_consent`.
`v2` is the switch's new sentence (`settings.updates_consent_v2`) plus the
chosen radio (`settings.updates_cadence_big` or `_every`). The record's version
names both (`v2-big`, `v2-every`), and its hash is sha256 of
`release-updates|<version>|settings.updates_consent_v2|<radio key>`.
`UpdatesConsent::textKeys()` maps any recorded version back to its keys. Riders
who opted in under v1 keep their v1 record and are on `big`, which is what "a
few times a year" promised; a new record appears only when they change the
cadence or opt in again. The account export (`account.json`) carries both
`updates_opt_in` and `updates_cadence`.

**Nobody is opted in by anything except asking.** Not by the migration, not by
registering, not by having been here first.

**Why no double opt-in on the toggle.** The second confirmation exists to prove
an address belongs to whoever typed it. This toggle is only ever shown to a
signed-in rider whose address was verified at registration, so a confirmation
mail would prove nothing and would train people to click links in mail from us.
The standalone signup for people **without** an account is a different thing, it
does need double opt-in, and it is not built (§7).

**Why the unsubscribe link is signed and not stored.** A stored token is another
row to expire, revoke and leak. An HMAC over the account's uuid needs none of
that, cannot be guessed, and lasts exactly as long as the account. It carries the
**uuid**, never the id, so the link says nothing about how many riders there are.

**Why it answers the same way every time.** A bad signature, an unknown account
and an already-unsubscribed account all get "you will not get these".
Distinguishing them would make the URL an oracle for whether a uuid exists.

**Why GET and not POST.** A mail client that pre-fetches links would leave a
POST-only unsubscribe unclicked and the reader still subscribed; on GET it does
the thing the reader wanted anyway. Unsubscribing is not destructive.

**Withdrawing leaves the consent record standing.** The record is evidence that
consent was given, not a claim that it still holds. The flag is the current
answer. Same shape as the photo licence consent.

## 5. Caching, and the trap it avoids

`/changelog.atom` sets `public, max-age=3600`, because a feed reader polls it and
it holds nothing personal. It needs an explicit `PUBLIC_ACCESS` rule in
`security.yaml` to stay that way: without one, scheb's lazy-firewall listener
reads the token, the session usage index moves, and `AbstractSessionListener`
rewrites the header to `private, must-revalidate`. The same trap
`/map/catalog/stamps.json`, the region slices and `robots.txt` carry a rule
for. `RoadmapChangelogTest` asserts the header rather than the rule, so it
catches the symptom whatever causes it next time.

## 6. Adding a release

1. Add an entry at the **top** of `ReleaseNotes::RELEASES`, with its notes
   under `sections` (see below), and set `APP_BUILD_VERSION` in `web/.env`
   to the same version with its leading `v`.
2. Add its keys to `messages.en.yaml`, then the other four. Name each country
   in the "Next release" block (§6b): it leaves that block when this release
   is dated.
3. `app:translations:sync`.
4. Mark anything it finished as shipped on the roadmap desk (§8.4), if that
   was not done the day it shipped.
5. Tag it `v<version>` so the footer agrees with the page.
6. Run the tests: the key check fails on any locale you forgot.

**Sections (owner 2026-10-04).** A release's notes sit
under three headings, in `ReleaseNotes::SECTIONS` order: `public` ("Public
site": what everyone sees, the map, the pages, the ballot), `rider` ("Rider
account": what a signed-in rider's own account pages gain) and `curator`
("Curator account": the desks and what curators and admins handle). Each
heading is `changelog.section_<name>`; an empty section is left out. Releases
before 0.9.4-beta keep their flat `keys` and show no headings.
`ReleaseNotes::releases()` gives the page and the feed one shape for both:
an older release is one group named `''`. Every note also carries its kind in
`kinds`, one of `ReleaseNotes::KINDS` (`new`, `improved`, `fixed`; owner
2026-10-13, older releases labelled the same day): small coloured letters at
the end of the note's bold lead on the page (`changelog.kind_*`), and `[New]`
before it in the feed.
`RoadmapChangelogTest` fails on a note without a kind, a kind for a note the
release does not have, or an unknown kind. The feed puts each heading in an
`<h3>` above its list. A note says what the reader gains ("See where a climb
hurts most"), not what is on the screen; the details go in a blog article.

## 6a. What goes on the roadmap

Owner, 2026-09-25: the roadmap carries **the big things only**, such as more
data providers, the seasonal vote, more countries, Scout on more devices and
giving data back. Bugs, deploy prerequisites, operations chores, content
review rows and smaller features stay off it; the smaller features are private
known issues on the curator desk (`is_public` false, status `planned`).
Items sit in three groups, `now`, `next` and `later`
(`ReleaseNotes::STATUSES`), in the order the desk sets. The order inside a
group is not a promise. An item that shipped is marked shipped on the desk the
day it ships, whether or not a release has been tagged yet; the changelog
names it at the next tag (§6 step 4). Fifteen items is the size it has now; it should stay
short, because a list nobody reads to the end is not a roadmap.

## 6b. Countries live since the last release

A country that went live after the newest release's `date`
(`Countries::liveAfter()`, from `country.live_at`) is listed at the top of
"What's new" in a **Next release** block (`#next`, "Already live"), under the
public heading, as "Denmark is on the map." (`changelog.country_live`, the
name in the page's language). Nothing is written to `RELEASES` for it: the
block is read from the database, so it appears the moment `app:country:mark-live`
runs, on staging and production alike. Cutting the next release moves its date
past those countries and the block empties, so that release's notes must name
them (§6 step 2). The feed does not carry the block: it lists releases only.
Owner 2026-10-11.

## 7. Deliberately not built

- **A standalone signup for people without an account.** It needs double
  opt-in and a subscriber table, and it is a different design from a toggle on
  an account that already exists.
- **Sending anything.** The list can be joined and left; no release mail is sent
  yet. That is the right order: a list nobody can leave is worse than no list,
  and a list with nothing to send is merely empty.
- **Weighted or scored votes.** A vote is one mark, "this matters to me".
  No score from 0 to 10, no weight by role or account age: the counts inform
  the order, they do not decide it.
- **Dependencies between items, categories, releases on items.** The list is
  short on purpose (§6a); none of these earns its place on fifteen items.
- **A rider's name on an idea.** An idea on the roadmap never names who sent
  it (§8.3).

## 8. The roadmap in the database, and the rider's say in it

Owner, 2026-10-13: "Have a say in this list. Vote on what matters to you,
follow an item, and propose one of your own", with the items moved into the
database "so when we go live with this feature it is a toggle and not a
redeploy", new items added on the desk, and proposals "visible in a separate
tab" and votable before they are promoted to real items.

### 8.1 What is stored

| Table | Holds |
|---|---|
| `roadmap_item` | one item: group (`now`, `next`, `later`), place in the group, optional GitHub issue, state (`live`, `shipped`, `removed`), the proposal it came from |
| `roadmap_item_text` | one item's title and text in one language |
| `roadmap_proposal` | one rider's proposal, its status and the desk's decision |
| `roadmap_proposal_image` | the one picture a proposal may carry |
| `roadmap_vote`, `roadmap_follow` | one row per rider per ref |

An item can also change by migration, while the desk is not yet on
production and the item must land with its feature's code:
`Version20261014010000` reworded the Next item "Take a route with you" into
"Plan a ride on your bike" and moved it to Now
([route-planner.md](route-planner.md) section 2).

A **ref** names what a vote or a follow is on: `item:<id>` or
`proposal:<id>`. A shipped or removed item keeps its row with `closed_at`,
so a promoted proposal still points at something.

### 8.2 The page: two tabs

`/roadmap` (`RoadmapController`) lists the live items by group and place
(`RoadmapItems::rows()`). With the rider's say switched on, a second tab,
`/roadmap?tab=proposals`, lists the proposals open for votes, most votes first
(`RoadmapItems::proposalRows()`). The tab is in the URL, never in script state,
so both tabs stay shareable in the public cache (page-caching.md).

**Only a live item and an open proposal are ever listed or markable.** A
waiting or declined proposal is seen by its rider and the desk only; a
shipped or removed item is off the page. `RoadmapItems::anchorOf()` refuses a
vote or a follow on anything else. `RoadmapSayTest::testOnlyAnOpenProposalIsShownAndOnlyLiveThingsAreMarkable`
checks both, for a visitor and for another rider. An item that came from a
proposal, and every proposal, is marked "Idea from a rider", never with the
rider's name.

### 8.3 The switch

The system setting `roadmap.say_live` (`SettingsRegistry::ROADMAP_SAY_LIVE`,
default 0, `RoadmapSay`) turns on the rider's say: the vote and follow
buttons, the Proposals tab, the proposal form and `POST /roadmap/mark`. At 0
the roadmap is a list only, the form and the mark route answer 404, and the
foot of the page points to the contact page. The desk works either way, so
the items and the first proposals can be prepared before the switch.

### 8.4 Votes and follows

| | Vote | Follow |
|---|---|---|
| Shown | the count, to everyone | to the rider only |
| Effect | none beyond the count | an inbox message when it moves (§8.7) |

Both are toggles posted to `POST /roadmap/mark` (`ref`, `do` = `vote` or
`follow`, CSRF id `roadmap-mark`). `assets/roadmap/marks.js` posts in the
background with `Accept: application/json` and gets `{on, votes}` back;
without script the form posts and lands on the row's anchor. The buttons are
disabled while a post is out, so a double click cannot flip a mark back, and
only a refusal the server explains (403, 404, 429) falls back to the plain
post: after any other failure the first post may already have counted.
Written with DBAL (`RoadmapMarks`), so a double post meets the unique index
instead of closing the entity manager.

**Who:** a signed-in rider with a confirmed address, at most 120 marks an hour
(`roadmap_mark`). No account age or activity rule like the season ballot's
(route-domain.md §8c): a roadmap count decides nothing, so one extra account
buys nothing. **A visitor** sees the counts and a sign-in link in place of the
buttons, and no form: a CSRF token would open a session and take the page out
of the public cache. The counts a visitor sees can be up to 60 seconds old.

When an item or a proposal leaves the page its votes and follows are
deleted; when a proposal is promoted they move to its item
(`RoadmapMarks::moveRef()`).

### 8.5 Proposals

`/roadmap/propose` (`RoadmapProposalController`, signed in, closed while the
switch is off) holds the form and, under it, every proposal the rider sent
with its status and the desk's answer. Three questions: a title (4 to 120
characters), what is missing or hard today and what the rider would like
instead (10 to 4000 each), and who gains (optional, up to 2000). A confirmed
address is required, five proposals a day per account (`roadmap_propose`).

**What fits is said up front.** Above the questions the form says what fits
best (ideas that help riders find, check or add places and routes, or make
the open data easier to use) and, by example, what rarely does (leaderboards,
training plans, buying and selling), with the reason that other apps already
do those well, and that every idea is read all the same
(`roadmap.propose.fits`). Votes order what goes up; the fit decides whether
it can.

**Review first.** A proposal is `pending`, seen by its rider and the curators,
until an admin opens it for votes: a rider's text goes on a public page only after somebody read it,
the same as every other contribution.

**One optional picture**, stored and checked like a bug screenshot
(contact-and-support.md §6): `ScreenshotStore::hold()` on the web host,
`CheckPicture::ROADMAP` on the worker, bytes in `roadmap_proposal_image`. Its
rider and the desk see it once ready (`/roadmap/ideas/{id}/image`,
`/moderate/ideas/{id}/image`, `no-store`, `nosniff`).

**A picture goes public only when two said yes** (owner 2026-10-13: "if we
decide it is a good picture why not show it"). The rider, when sending: an
optional box, off by default, "You may show this picture with my proposal
when it is public" (`consented_at`, the moment they ticked it; it is their
picture, so nothing shows without it). And the desk, when opening or
promoting: "Show the rider's picture", offered only for a picture the rider
allowed (`shown`; a CHECK constraint refuses `shown` without
`consented_at`). Then the picture shows, small, with the proposal on the
Proposals tab and with its item once promoted, at
`/roadmap/pictures/{proposal id}` (`public, max-age=600`), as long as the
proposal is open or its item is live (`RoadmapItems::pictureIsPublic()`). A
decline hides it for good; anything else answers 404.

### 8.6 The roadmap desk

Admins run it: what the project builds next is the project's call. One tab
under More, "Roadmap", badged with the proposals waiting, and listed on the
moderation dashboard for admins. **Curators read the proposals** and their
pictures (owner 2026-10-13); they know what riders ask for in their region.
Their tab, "Proposals", has no badge and their pages no decision form; the
decide route is admin-only. Two halves, chips under the heading:

**Items** (`/moderate/roadmap`, `ModerateRoadmapController`, `ItemDesk`): the
live items by group with up and down inside a group; "Add an item"; and one
page per item with its group, its GitHub issue, a title and text per language
(a language left empty has no text), and the buttons Mark as shipped and
Remove from the roadmap. Saving with another group is a move. A new item, or
an item moved to another group, goes at the end of that group.

**Proposals** (`/moderate/ideas`, `ModerateIdeasController`, `IdeaDesk`), the
bug desk's shape: status chips, one page per proposal, one decision form.

| Decision | Allowed from | The proposer hears | Followers hear |
|---|---|---|---|
| Open for votes | pending, declined | `roadmap_idea_open` with the note | nothing |
| Keep open, reworded | open | nothing (a note is refused) | nothing |
| Promote, into a group | pending, open | `roadmap_idea_promoted_<group>` with the note | `roadmap_moved_<group>` |
| Decline, reason required | pending, open | `roadmap_idea_declined` with the reason | `roadmap_moved_removed`, if it was open |

Promoting makes a new item in the proposal's words (the desk's wording if
there is one) and language, links it both ways, moves the votes and follows,
and lands the desk on the new item to add the other languages. Promoted is
final for the proposal. Opening makes the proposer follow their own proposal,
so they hear what happens to its item later. Every desk change is one
transaction that locks the row it changes, so two admins or a double post
cannot both send the news.

### 8.7 Messages

| What happened | Who hears it | Body key |
|---|---|---|
| An item moved to another group | its followers | `roadmap_moved_<group>` |
| An item shipped / was removed | its followers | `roadmap_moved_shipped` / `_removed` |
| A proposal was decided | its rider | `roadmap_idea_open`, `_promoted_<group>`, `_declined` |
| An open proposal was promoted / declined | its followers, not the rider | `roadmap_moved_<group>` / `_removed` |

All are system messages (`UserMessageKind::RoadmapIdeaOutcome`,
`RoadmapItemMoved`, channel `roadmap`, category General), so each also reaches
the rider by email as every inbox message does.

**The follow is the opt-in, item by item.** These mails are not the updates
list (§4): that is news about the Commons, sent at most at the cadence the
rider picked, and only with its own consent. A roadmap mail answers something
the rider did on that one item (followed it, or sent the proposal), so it
needs no other preference, the way the answer to a contribution needs none.
The follow button says so ("Get a message, in your inbox and by email, ..."),
the privacy notice says so, and each mail ends with its own reason:
`messages.email_why_roadmap_follow` (and how to stop: click Follow again) or
`messages.email_why_roadmap_proposal` (`MessageMailer::whyKey()`). An item's name in the message
is its title in the follower's language. Wording edits, votes and a new place
inside a group are not news.

### 8.8 Personal data

Privacy notice version 4 (privacy-notice.md section 7a) adds the votes,
follows and proposals, marked not significant: the processing is new but
optional and happens only when a rider uses it, so nothing changes for data
riders already gave. On account deletion (`RoadmapDeletionHook`) votes and
follows go, waiting and declined proposals go with their picture, and a
proposal that is open or promoted stays with `user_id` cleared. The data
export carries `roadmap_votes`, `roadmap_follows` and `roadmap_ideas` in
`community.json`.
