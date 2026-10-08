# Roadmap, changelog, and the release list

Canonical. Covers `/roadmap`, `/whats-new` (and the `/changelog` redirect),
`/changelog.atom`, the updates opt-in on `/account/settings`, and
`/unsubscribe`.

Related: [privacy-notice.md](privacy-notice.md) for the consent line,
[contact-and-support.md](contact-and-support.md) for the other ways in.

## 1. Why this exists

Riders had no way to learn that anything had changed, and no way to see what was
coming. Owner, 2026-08-27: *"no way to inform them about updates"*. The answer
is three surfaces that share one list, and a fourth that pushes it.

Owner's constraint on the roadmap, 2026-08-28: **"for now it must be a very
simple list."** Voting, following and rider proposals are the destination, not
the first version (§7).

## 2. One source, three readers

`App\Content\ReleaseNotes` holds both lists as constants. `/roadmap` reads the
unfinished half, `/whats-new` and the Atom feed read the released half.
The English page is `/whats-new`, the name the page and the footer use (each
locale has its own path, `LocalizedPath::CHANGELOG`). `/changelog` answers with
a permanent redirect to it (`PageController::changelogMoved()`), because bug
reports, search results and bookmarks carry that address. The feed is
`/changelog.atom`, unprefixed and English: feed readers are subscribed to that
exact address, and nobody reads a feed address.

**Why a PHP file and not a table.** Editing the roadmap is a pull request, which
is the same review every other piece of copy gets and leaves a history for free.
A table needs a migration, an admin screen, a permission, and a way to preview a
change: all of it worth building when riders can propose items, none of it worth
building to publish a short list.

**Why keys and not prose.** Every entry is a catalogue key, so the page reads in
five languages the day it ships instead of being an English island.
`RoadmapChangelogTest` fails on a key that is missing in any of the five, which
matters because an untranslated key renders as the key and the page still
answers 200.

**The GitHub link is a number, not a sync.** An item may carry `issue`, and the
page links it. Nothing polls GitHub, nothing breaks when GitHub is down, and an
item without an issue is still a valid item. That is the whole of "combine
roadmap with github issues or something smart and simple".

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
2. Add its keys to `messages.en.yaml`, then the other four.
3. `app:translations:sync`.
4. Move anything it finished off `ReleaseNotes::ROADMAP`.
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
an older release is one group named `''`. The feed puts each heading in an
`<h3>` above its list. A note says what the reader gains ("See where a climb
hurts most"), not what is on the screen; the details go in a blog article.

## 6a. What goes on the roadmap

Owner, 2026-09-25: the roadmap carries **the big things only**, such as more
data providers, the seasonal vote, more countries, Scout on more devices and
giving data back. Bugs, deploy prerequisites, operations chores, content
review rows and smaller features stay off it; the smaller features are private
known issues on the curator desk (`is_public` false, status `planned`).
Items sit in three groups, `now`, `next` and `later`
(`ReleaseNotes::STATUSES`). The order inside a group is not a promise, and the
page shows no numbers. An item that shipped is removed the day it ships,
whether or not a release has been tagged yet; the changelog names it at the
next tag (§6 step 4). Fifteen items is the size it has now; it should stay
short, because a list nobody reads to the end is not a roadmap.

## 7. Deliberately not built

- **A standalone signup for people without an account.** It needs double
  opt-in and a subscriber table, and it is a different design from a toggle on
  an account that already exists.
- **Sending anything.** The list can be joined and left; no release mail is sent
  yet. That is the right order: a list nobody can leave is worse than no list,
  and a list with nothing to send is merely empty.
- **Voting, following and rider proposals on the roadmap.** The roadmap
  itself lists roadmap votes as a `next` item (`roadmap.item_roadmap_votes`).
