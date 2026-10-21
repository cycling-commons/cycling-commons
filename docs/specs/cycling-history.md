<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Cycling history: places, stories and the timeline

Canonical. Owns the cycling history places (History & culture places that tell
a cycling story), how the hand-written list reaches the map, the `year` field
and the `/cycling-history` timeline page. What a Q row may store is owned by
[catalog-data-model.md](catalog-data-model.md); its fields by
[edit-items/Q-history-culture.md](edit-items/Q-history-culture.md).

## 1. What a cycling history place is

A Q · History & culture place with a cycling story: a place on a ride where
something happened in cycling, world wide. The first ride on two wheels
(Mannheim, 1817), the first Tour de France start (Montgeron, 1903), a velodrome
where an hour record fell, a rider's memorial on a climb, a street named after
a champion. Women's cycling is part of the list on purpose: the first women's
road world champion, the only woman who rode the Giro with the men, the
cyclists who used the bicycle to win their freedom.

A row is one of these when it carries a non-empty `cyclingStory`. Three
attributes make the story:

| Attribute | Field | What it holds |
|---|---|---|
| `cyclingStory` | "Cycling story / link", textarea, full width in the drawer | what happened here, plain and checkable |
| `year` | "Year of the story", three or four digits (`CatalogField::year()`) | when it happened; puts the place on the timeline |
| `links` | "Other pages about this place" | the Wikipedia article of the place (label "Wikipedia") and of the people or event the story is about (labelled with their name), each with its language variants, at most two (one host, two entries, catalog-data-model.md §7) |

The official website of the place, where it has one, is `web` ("Official
site"), never a `links` entry. A place may also have a `photo`: a Commons file
of the place itself (its Wikidata P18), chosen by a person who looked at it
(§2). Every one of these is a field a rider can edit.
The year field refuses anything but digits, in the form
(`CatalogFieldConstraints`) and in the API (`ContributionRequest`) alike.

## 2. The hand-written list

`tools/wikimedia/cycling_history.json`, one line per place: a name, a Q kind
(`PlaceKind`), the story, its year, `about` (the English Wikipedia titles of
the people or event), and the exact OSM object (`osm`) or Wikidata item
(`qid`) that locates it. Every id is one a person looked up and checked; the
script never searches by name, because the first hit for a memorial is as
often the village around it.

`tools/wikimedia/cycling_history.py` turns the list into the reviewable
`tools/wikimedia/out/cycling-history.json`: the point (the OSM object's when
there is one, else Wikidata P625), the country, the OSM object as `osmRef`,
and the links and official site from Wikidata. `--only <name>` redoes one row,
`--refresh` rewrites story, kind, year and links without locating again. The
list's own checks are `tools/wikimedia/tests/test_cycling_history.py`.

A `photo` in the list is a Commons file name a person looked at before writing
it down: it shows the place, and nobody is in it close up (photo-uploads.md
§6). The 2026-10-21 pass looked at 39 freely licensed P18 photos and turned
down four: three with riders or visitors close up, one that did not show the
place. The script asks Commons for the licence and the author on every run and
leaves the photo off, with the reason, when it no longer passes
`usable_photo()`.

A story states a fact a reader can check, in plain English, without dashes as
punctuation, and leaves out what is not sure. A place whose story has no single
year (a museum, a track raced on every winter) gets no year and is listed under
the timeline instead (§4).

## 3. Seeding

`app:catalog:seed-cycling-history <file>` (`SeedCyclingHistoryCommand`, with
`--dry-run`) writes each place as a `source='manual'` Q row,
`source_ref = manual:history-<slug>`, unverified and unassigned, by the
`manual` seeding rule (catalog-data-model.md §5). The row takes `osmRef` as its
`osm_ref`, so it stands in for that object's community pin (§5b). It skips and
names:

- a place in no onboarded region; it seeds once its country is onboarded, by
  running the command again;
- a place another served row already is: the same name nearby, the same
  Wikidata item as a `wikidata:` row, or the same OSM object. Its story goes
  onto that row by hand.

A photo is written only when PhotoValidator shows it on that place, as a
Commons link with its credit and licence; a refused one is left off and named.
`app:media:localise-commons --letter Q` then puts each one through the same
path a town card's photo takes (scan, re-encode, our own storage) and rewrites
the link to our copy. It must run where the virus scanner is reachable (the
worker, not the web container). A later re-seed keeps that copy when the file
is the same, so a seed never puts the Wikimedia link back. Until it has, the page shows no photo for the
place, because a browser never loads a picture from Wikimedia.

A bad entry (unknown kind, a year that is no year, a link the map would refuse)
fails the whole run: the artifact is a reviewed file, not a feed. Pinned by
`SeedCyclingHistoryCommandTest`.

## 4. The timeline page

`/cycling-history` (`PageController::cyclingHistory()`, localised paths in
`LocalizedPath::CYCLING_HISTORY`), read by `App\Catalog\CyclingHistory` from
the served rows: letter Q, a served state, not gone (`GoneRows`), in a region,
with a cycling story. So the page shows exactly the places the map shows, and a
rider's edit or a curator's approval reaches both at once. Riders' own places
count the same as the seeded ones.

- **The line.** The roadmap's trail (roadmap-and-changelog.md §8.2): a dashed
  path that sways a little, each year a round chip on it. On a wide screen the
  cards take turns left and right of the trail, each tilted a hair; on a narrow
  one they follow it on the right. One card per place, under its year, years
  in order; places of the same year by name. A card with a photo shows it on
  top, with its credit, licence and Commons page over its corner. A card shows the name, its kind and where it is
  (region, country), the story, "Show on the map" (`/map?item=<id>`) and its
  links, resolved to the reader's language by the drawer's rule
  (`OutboundLinks::forLocale()`, the twin of `links.js` `itemLinks()`), with
  the drawer's `rel="noopener noreferrer nofollow"`, because riders can edit
  them.
- **More places to visit.** The places without a year, by name.
- **Add a place.** Opens the Q add form (`/improve?type=history-culture&mode=add`);
  a new place reaches the page after a curator approves it.

The page is in the page cache (page-caching.md §6), in the sitemap, in the
footer's "The atlas" column and on `/pages` under The atlas (site-directory.md
§5).


## 5. The switch

The system setting `cycling_history.live` (`SettingsRegistry::CYCLING_HISTORY_LIVE`,
default 0, config `config/packages/cycling_history.yaml`, read by
`CyclingHistorySwitch`) opens the page. At 0 `/cycling-history` answers not
found, and nothing links it: not the footer, not `/pages` (its dot, card and
road are left out), not the sitemap, and What's new and its Atom feed leave out
`ReleaseNotes::CYCLING_HISTORY_NOTES`. An environment seeds its places first
(§3) and sets the switch to 1 under /admin, system settings, after. Pinned by
`CyclingHistoryPageTest::testWhileSwitchedOffThePageIsNotFoundAndNothingLinksIt`.