# Traffic measurements from bike radar

**Status:** built. Lines carry no rider at all, wait in an encrypted waiting
room per time block until the block has enough of them, and then go into plain
totals (§3.5, §4.3, §8). Owner-approved design; this document describes what
the code does.

A rider who rides with a rear radar records every overtaking car in the ride
file. Scout's ride review reads those passes in the browser and, with the
rider's consent per ride, sends a per-road summary: how far the rider rode on a
road piece, how many cars passed, how fast they drove when the device measured
it, and a coarse time key. Nothing in a line names or codes a rider. The server
holds each line in an encrypted waiting room until its time block has enough
lines, then adds them to plain totals. Curators see quiet, moderate or busy per
road once a road passes the disclosure rules. Nothing is public yet.

Related: [moderation-and-contribution.md](moderation-and-contribution.md)
(Scout intake), [privacy-notice.md](privacy-notice.md),
[coverage-provider.md](coverage-provider.md) (the tile pipeline),
[security-architecture.md](security-architecture.md).

## 1. Principles

1. **The ride never leaves the browser.** The server never receives a GPS
   track, a ride file, anything within 500 m of a ride's first or last fix, a
   time finer than a part of the day (§3.4), a date (only a day group of its
   quarter, each standing for at least four dates) or the order of a ride, not
   even in memory. The browser matches the ride to
   road pieces and sends per-piece summaries only. The Scout rule that a
   track-shaped payload is refused, not ignored, applies to the new endpoint
   too.
2. **A line names no rider.** No account, rider code or device id is stored
   with a line or a total. Sending needs an account only so floods can be
   limited; the account is never written next to the data.
3. **A ride reaches the totals in pieces.** While a send arrives the server
   sees its lines together, and says so (§5). From then on each line waits on
   its own, encrypted, in the waiting room of its time block, and moves to the
   totals only with at least four other lines of that block, at a moment
   chosen at random among the blocks that are ready (§4.3). A backup taken
   before and after shows blocks of many rides changing, never one ride.
4. **Curators see a band, never a number.** A road shows only past the rules
   of §4.4, and only as quiet, moderate or busy. Below the rules nothing is
   shown, not even that data exists.
5. **Measure first, judge later.** Data is stored per part of the day (five
   bands), day type and quarter; the time groups shown are chosen from those. The measured value does not write the road-surface `traffic` field
   until curators have compared the two.
6. **Every value except the pass count is optional.** Devices differ: some
   record car speed and distance, some only count passes.

## 2. Road pieces

A road piece is one OpenStreetMap way. The pipeline builds a `roadpieces` tile
family next to the surface family (`pipeline/coverage/roadpieces.py`, run with
`run.py --roadpieces`), one PMTiles file per onboarded country
(`roadpieces-<cc>.pmtiles`), published under `roadpieces/<cc>/<stamp>/` and
listed in `roadpieces/manifest.json` in the same shape as the surface manifest.

**Zoom and geometry.** z14 only (tippecanoe minimum and maximum zoom 14), no
feature dropping, feature id = way id. One source layer per country, `roadpieces_<cc>`.

**Properties.**

| Key | Values | Meaning |
|---|---|---|
| `l` | `p`, `l`, `r` | label: cycle **p**ath, cycle **l**ane, shared **r**oad |
| `h` | OSM `highway` value | road class, for styling |
| `s` | `1` or absent | the road says a separately mapped cycle path runs beside it |
| `g` | region id or absent | the operational region that owns the piece's middle vertex (`Owners.region()` in `pipeline/coverage/ownership.py`, the owner rule: smallest region, a small snap band at borders); absent when no region owns it |

**Selection and labels**, applied in order:

1. Excluded: `highway` in `motorway`, `motorway_link`, `construction`,
   `proposed`, `steps`, `platform`, `raceway`, `elevator`, `corridor`,
   `bus_stop`, `rest_area`, `services`; any way with `bicycle` in `no`,
   `use_sidepath`; `footway`, `pedestrian` and `bridleway` unless `bicycle` is
   `yes`, `designated` or `permissive`; `area=yes`.
2. `p` (cycle path): `highway=cycleway`; `highway=path`, `footway`,
   `pedestrian` or `bridleway` that survived step 1; any road with
   `motor_vehicle=no` or `motorcar=no`; any cycle street (`cyclestreet=yes`
   or `bicycle_road=yes`: a fietsstraat is laid out for bikes, cars are
   guests); any road whose `cycleway`,
   `cycleway:left`, `cycleway:right` or `cycleway:both` is `track` (the track is
   drawn on the road itself, so a rider placed on the road is on the track).
3. `l` (cycle lane): a road whose `cycleway[:left|:right|:both]` is `lane` or
   `opposite_lane`.
4. `r` (shared road): every other remaining `highway` a car may use
   (`trunk` … `residential`, `living_street`, `service`, `unclassified`,
   `track`, `road`, `busway`).
5. `s=1` on a road or lane road whose `cycleway[:left|:right|:both]` is
   `separate`.

`roadpieces.py` is an input of every extract's stamp (`run.py`
`ROADPIECES_RULES`), so a change to the rules rebuilds the extracts and a
relabel never serves yesterday's pieces.

**Serving.** Through the existing tiles proxy, like the surface family. The
review page, and the map for a curator with two-factor authentication, emit the
per-country URLs in `window.CC_TILES.roadpieces` (`RoadPiecesManifest`, from
`ROADPIECES_MANIFEST_URL` or the pin `ROADPIECES_TILES_URL`). The browser
fetches only the tiles a ride touches, through the page's `pmtiles` library,
and decodes them with `web/assets/lib/road-pieces.js`, a small reader for what
a road-piece tile holds: line features, their id and string properties. Decoded
tiles are kept per session, so bulk mode reads each tile once.

## 3. In the browser

### 3.1 The two-step review

`Review a ride` has a single-ride flow with a second step when the ride
has radar data (`countVehicles()` returns a result):

| The ride has | Step 1: tags | Step 2: traffic |
|---|---|---|
| tags and radar | the tag cards | after "Next: traffic" |
| tags, no radar | the tag cards | not shown |
| radar, no tags | greyed out, "No tags in this ride" on hover | opens at once |
| neither | "Nothing in this ride to send" | not shown |

The step bar shows only when the ride has radar data. Each step sends on its
own; going back keeps what was done. **Map:** step 1 shows only the tag pins and
stretch lines; step 2 shows only the car markers, the matched road pieces,
in the colour of their label, which is what is sent (blue bike only, amber
cycle lane, purple shared road), above the ride line, and the radar-on part
that matched no road in red dashes (not sent), above them. While step 2 is
on screen the map's legend box shows the whole key (`#scoutKey`: the grey
GPS track, the three kinds, not sent, the passing car and the nearby car with
"speed in km/h" or "mph" from the rider's units, since a car marker shows the
bare number and gives the unit on hover; a car only nearby, beside a cycle
path, has a blue marker, the cycle path's colour (`summary.passKinds`); each road is drawn in its
mapped shape but only from the first to the last fix matched to it, plus
10 m to close the gap at a junction (`riddenParts()` in
`web/assets/lib/traffic-match.js`), so the GPS track can run beside it and a
street turned off halfway does not run on past the turn), and the step's "Map key" link unfolds that
box when the rider folded it. The map's Key panel has the same group on the
review page (`#mk-scout`), and /map-key lists it. The car lines of the ride
facts (the radar total, and how many cars had no GPS fix) show in step 2 only,
like the cars on the map; step 1 is about the tags. In step 2 the radar total
line is hidden when the step has lines to send, because the step's own line
adds up to it.
Step 2 states, in one line, the matched kilometres and the cars that passed
the rider, then the cars only nearby beside a cycle path and the cars on parts
not sent, when there are any ("52 km matched to roads, 37 cars passed you,
2 nearby beside a cycle path, 2 on parts not sent"); the unmatched kilometres
("1.2 km could not be matched to roads (red on the map); that part and its
cars are not sent", with an eye button that zooms the map to one part at a
time, longest first, with a "2/7" counter when there are several); one button,
**Send traffic summary**; and below it one link, "Show what is sent", that
opens in place with the rule (per road, distance and cars; no ride line; times
only as a part of the day; the date only as a day group; the first and last
500 m never sent) and every line. With no road-piece tiles configured, or no
radar-on part on a known road, only that is said. The click is the consent for
that ride. The review page itself requires an account.

### 3.2 Bulk mode

The panel has one ride picker (`multiple`), and the same drop zone. One file
opens the single-ride review; more than one file, or a plain `.zip` with
several rides (the archive a bike computer platform exports), starts bulk mode.
There is no second picker for bulk and no folder picker: phones have none. `web/assets/lib/ride-archive.js`
finds the `.fit` and `.fit.gz` files, nested zips included, and leaves the rest;
the archive is unpacked in memory, so a very large export may be better
unpacked first and its `.fit` files picked. The browser
processes every `.fit` with radar data, one at a time with a progress line,
never uploading a file. `web/assets/lib/ride-batch.js` adds the rides up, and
the panel then shows:

- a summary table: rides with radar and the files they came from ("412 from
  37 files", since a `.zip` can hold many), the period from the lines' own
  dates in the rider's date format ("12 Mar 2019 – 28 Sep 2024"), the matched
  distance in the rider's units, cars that passed and cars nearby;
- a folded breakdown per year (rides, distance, passed, nearby), newest first,
  so years of data read at a glance without a list of every ride;
- the roads on the map, drawn like one ride's (kind colours, red dashes for
  what matched no road, the same legend), each stretch once however often it
  was ridden, with the map fitted to them;

and **Send traffic summaries**. That click is the consent for the batch; the
answer names the rides sent ("412 rides sent: 8,210 stretches added, 30 were
already known"). Bulk mode sends traffic only; tags stay in the
single-ride review. The batch is sent in chunks; every chunk is idempotent (§4.3),
so a retry after a broken connection adds nothing twice.

### 3.3 Matching (`assets/lib/traffic-match.js`, pure, node-tested)

Input: the decoded records (time, position, speed, radar fields) and the road
pieces of the tiles the ride touches. For each record with a position:

1. Candidates are pieces within 20 m.
2. Score = distance to the piece + 15 m when the ride's heading (above 5 km/h)
   differs from the piece's bearing by more than 45° (mod 180°) + 5 m for
   leaving the piece of the previous record (hysteresis).
3. A road or lane road with `s=1` loses to a cycle path candidate within
   20 m that runs within 30° of it.
4. The lowest score wins. Records with no candidate are unmatched.
5. A run on one piece shorter than 100 m with the same piece before and after
   it (a fix onto a parallel road, a crossing street passed over) is
   reassigned to that piece when it is within 20 m, else dropped as unmatched.
   A short run between two different pieces is kept: OSM cuts streets at
   every junction, and a 30 m crossing is road ridden.

Direction: a run on one piece is cut into legs where the rider turns back along
it by more than 30 m (an out-and-back); each leg is `f` when its projected
position along the piece's vertex order increases, else `b`.

### 3.4 The summary (`assets/lib/traffic-summary.js`, pure, node-tested)

First the ends are cut: every record within 500 m in a straight line of the
ride's first fix or of its last fix is dropped before matching counts anything,
wherever in the ride it lies (`trimEnds()`), because that is where a rider
lives or works. GPS drift at the door, a loop round the block and a pass by the
door halfway through the ride are all cut. A record without a fix takes the
verdict of the last fix before it. Cut records are not shown as unmatched
either; the review says the ends are never sent.

Records are grouped by **key** = (way, direction, label, band, day type,
quarter, day), counting only seconds where the radar field is present:

- **band**: the part of the local day, 0-4: night 00-06, morning rush 06-09,
  day 09-16, evening rush 16-19, evening 19-24. Local time = record time plus
  the offset `local_timestamp - timestamp` from the FIT activity message
  (message 34, field 5), rounded to whole quarter hours. Without it, the IANA
  zone of the country most of the ride matched in (the tile's `cc`); a country
  that spans zones (United States, Canada, Australia, Spain with the Canaries)
  picks the zone by the first fix's longitude and latitude
  (`zoneOffsetSeconds()`).
- **day type**: `weekend` on Saturday, Sunday and the public holidays of the
  ride's country (`web/public/data/holidays/<cc>.json`, official holidays only,
  built by `app:traffic:holidays` from the Yasumi library for 2010 to two years
  ahead; rerun yearly), else `workday`. Chile and Rwanda have no provider and
  use the calendar only.
- **quarter**: `YYYY-Qn` of the local date.
- **day**: the local date as days since 1970-01-01. It splits lines by day
  and stays in the browser, which shows it in "Show what is sent". What is sent
  is **dayGroup** (`dayGroupOf()`): how many dates of the same day type come
  before the day in its quarter, modulo 12 for a workday and modulo 4 for a
  weekend day (`DAY_GROUPS`). A quarter has at least 58 workdays and 26
  weekend dates in every country with a holiday list, so a workday group stands
  for at least 4 workdays and a weekend group for at least 6 weekend dates, and
  the server never receives a date. Consecutive dates of one day type fall in
  different groups. It feeds the several-days rule (§4.4).

Per key: `distanceM` (sum of distances between consecutive radar-on records on
the same piece and direction, counted on the later record's line so a band
boundary loses nothing; a gap over 5 s is not ridden distance), `timeS` (radar-on seconds), `region` (the piece's `g`, or null; it says
nothing the way id does not), `passes` (passes whose record falls in
the key, on a lane or road line), `nearby` (the same, on a cycle-path line: no
car drives on a cycle path, so a car the radar saw there drove on the road
beside it; that is noise, not safety, and never counts as passing the rider),
`avgSpeedKmh` (distance ÷ time), `carSpeedBins` (16 counts of the car
speed in 10 km/h bands, last band ≥ 150, from the pass's ground speed, else its
closing speed plus the rider's GPS speed; omitted when no pass has a speed).

**Dedupe codes.** For every clock-aligned 5-minute block a line covers:
`sha256("cc-traffic-block-v1|" + blockStartUnix + "|" + firstRecordUnix + "|" +
latSemicircles + "|" + lonSemicircles)` of the first kept record inside the
block (never one the end cut dropped), full precision. The same file, edited or cut differently, yields the same codes;
two riders riding together yield different ones. A line lists the codes of its
blocks (a band holds up to 84).

Lines that share a block code, which in practice is one ride, always travel in
the same request: the server counts a line as a duplicate once another request
claimed one of its codes, so a ride split over two requests would lose its
second half. Rides are shuffled and packed into requests of about 500 lines
(never above 2000) and about 4,000 distinct codes (`CHUNK_CODES`; the server
takes up to 10,000), and lines are shuffled inside each request
(`web/assets/lib/traffic-ride.js`: `makeChunks()`, `sendChunks()`). The review
keeps which requests were acknowledged, so sending again after a failure
resends only the rest.

### 3.5 No rider in a line

A line names no rider: no account, no rider code, no device id. A rider code
made in the browser cannot stop a modified client from posing as many riders,
and it brings a password or recovery step to every rider (§8). So what a code
would guard is kept by other means: §4.3 keeps a send's lines from reaching the totals together, and
§4.4 shows curators only a band. What it gives up: a road ridden by one person
only, on enough days, can show; and a frequent rider weighs more than an
occasional one (§8).

## 4. On the server

### 4.1 Endpoint

`POST /scout/traffic`, `ROLE_USER`, stateless CSRF token id `scout-traffic` in
`X-CC-Token` (`window.CC_TRAFFIC_TOKEN`, review page only), limiter
`traffic_submit` (per user, 120 per hour, sized for bulk chunks). The account is
used for that limit only and is never written next to the data. JSON:

```json
{"v": 2, "lines": [{
  "way": 4521877, "dir": "f", "label": "r",
  "band": 3, "dayType": "workday", "quarter": "2026-Q4", "dayGroup": 9,
  "distanceM": 3210, "timeS": 421, "passes": 7, "nearby": 0,
  "avgSpeedKmh": 27.4, "carSpeedBins": [0,0,0,0,0,1,3,2,1,0,0,0,0,0,0,0],
  "blocks": ["9f3a…", "c71e…", "02bd…"]
}]}
```

**Refused (422):** any top-level or line key in the Scout track list
(`track`, `points`, `trkpt`, `polyline`, `records`, `route`, `coordinates`,
`gpx`, `fit`, plus `lat`, `lng`, `lon`, `time`, `timestamp`); unknown keys,
`day`, `slot`, `season` and `rider` among them; more than 2000 lines; more
than `TrafficIntake::MAX_CODES = 10000` distinct block codes in the accepted
lines (error `too_many_codes`); `v` other than 2.

**Plausibility, per line (line dropped, counted in the response):**
`timeS` from 1 to the band's length plus 5 s (the one interval a band
boundary hands to the later line); `distanceM` ≤ 200000; average speed 3..80 km/h; `passes` and
`nearby` each ≤ 60 per km ridden and ≤ 300; `passes` 0 on a `p` line and
`nearby` 0 (or absent) on an `l` or `r` line; `carSpeedBins` 16 non-negative integers summing to
≤ `passes` + `nearby`; `band` 0..4; `dayGroup` 0..11 on a workday line and
0..3 on a weekend line (`TrafficLine::DAY_GROUPS`); `quarter` from 2010-Q1 to
the quarter of tomorrow in UTC (a zone east of Greenwich may already be in the
next one); 1 to 85 block codes of 64 hex characters; `label` in `p`, `l`, `r`;
`dir` in `f`, `b`; `region` absent, null or an integer ≥ 1.

Response: `{"ok": true, "added": n, "duplicate": n, "dropped": n}`; `added`
counts lines taken into the waiting room.

### 4.2 Keys

`TRAFFIC_SECRET` (32 random bytes, base64), its own variable, never
`APP_SECRET` or `ENCRYPTION_SECRET`. HKDF-SHA256 derives three subkeys: `enc`
(AES-256-GCM payloads of the waiting room), `block` and `seen` (HMAC-SHA256). The
committed base settings file leaves it empty; the dev stack passes a public
development key (`developers/docker/compose.yaml`), tests use the committed test
settings, and staging and production set their own. `TrafficKeys` refuses both
committed values in staging and production, and refuses an empty or short key
everywhere. Losing it makes the lines still waiting unreadable (the totals are
plain), so it is in the deploy secrets checklist (operations.md).

PHP runs with `zend.exception_ignore_args = On`, so a stack trace, and with it
an error report, never holds a call's arguments: no raw block code and no
plain line. The app image sets it (`web/Dockerfile`, `conf.d/cc-errors.ini`;
the image ships no `php.ini`, and PHP's own default is Off). Production hosts
must run with it On.

### 4.3 Tables

| Table | Columns | Holds |
|---|---|---|
| `traffic_pool` | `id bytea PK` (16 random bytes), `block_key bytea`, `payload bytea` | the waiting room: one row per waiting line, encrypted; no account, no time column |
| `traffic_total` | `way`, `dir`, `band`, `day_type`, `quarter` (the key), `label`, `region`, `distance_m`, `time_s`, `passes`, `nearby`, `speed_sum`, `speed_passes`, `bins` (16 integers), `lines`, `days` (a 16-bit set of day groups); the sums are BIGINT, so a busy road never overflows | plain totals per road, direction, part of the day, day type and quarter; no rider |
| `traffic_seen` | `code bytea PK` | HMAC of each block code received; kept for good |

A **block** is one road, direction, part of the day and day type, across
quarters: the unit a line waits in. `block_key` = HMAC(`block`, way + dir +
band + day type), so the waiting room says how many lines wait per block and
nothing about which road or time. A pool payload holds the whole line (without
its block codes), sealed with AES-GCM, bound to its row id and its block key as
associated data (a row moved to another block does not open) and
padded to a multiple of 256 bytes. `seen` code = HMAC(`seen`, the client's
block hash).

**Write path** (`TrafficIntake`), one transaction per request:

1. Refuse the request when its accepted lines carry more than
   `MAX_CODES = 10000` distinct codes. Claim the codes, in the order of their
   stored form, with multi-row `INSERT … ON CONFLICT DO NOTHING RETURNING` of
   up to 1000 rows each (`TrafficStore::claimCodes()`). A code another request
   holds, even one not yet committed, is not claimed (the insert waits for that
   request), so a retry of a request still in flight counts nothing twice.
2. A line with any code this request did not claim is a duplicate.
3. Each accepted line goes into the waiting room as its own row, in random
   order, under a random id.
4. Then at most **one** block leaves the waiting room. The call draws at most
   `TrafficPool::MAX_CANDIDATES = 32` blocks at random among those with at
   least `MIN_LINES = 5` rows, so the work of a send stays bounded however full
   the waiting room is; of those, one whose lines number at least 5 and whose
   (quarter, day group) pairs number at least `MIN_DAY_GROUPS = 3` leaves.
   A block of up to `MAX_RELEASE = 1000` lines leaves whole; a bigger one
   leaves `MAX_RELEASE` lines drawn at random that meet the same rule, and the
   rest waits. The lines are added to their totals rows
   (`TrafficStore::addToTotal()`) in quarter order, random within a quarter,
   and deleted from the waiting room (`TrafficPool::releaseOne()`). Any block
   can be the one, not only this request's, so which totals change says
   nothing about who sent; one block per request means a ride's roads reach
   the totals at different moments, usually with other riders' lines. A block
   that waits on is moved by a later send. A block whose chosen rows include
   one that does not open (another key, or sealed for another row or block) is
   passed over, neither released nor deleted, and a warning naming no row,
   block or content is logged.

### 4.4 Disclosure rules (`TrafficDisclosure`)

A *group* is a set of totals rows of one way and direction, chosen by the
active grouping scheme (§4.5) and the period (default: the last 12 quarters).
A group is shown only when all hold:

- at least `MIN_LINES = 5` lines;
- at least `MIN_DAY_GROUPS = 3` day groups: per quarter the groups of its rows
  together, summed over the quarters. Two dates can share a group, so this
  undercounts days, never overcounts them;
- its total distance is at least `MIN_DISTANCE_M = 2000`.

Constants live in `TrafficDisclosure`; public copy never states them.

Shown values are **bands, never numbers**: `traffic` is `quiet` under 1 car per
km, `moderate` from 1 to under 3, `busy` from 3 (all passing cars ÷ all km);
`nearby` is the same banding of the cars beside a cycle path (a cycle path's
only car figure: the path colours by its passing cars, which is none); the car
speed band (10 km/h, the band of the median measured speed) when at least 5
cars in the group had a measured speed; and days as `3-9` or `10+`.

### 4.5 Grouping scheme

One scheme is active platform-wide (system setting `traffic.grouping`),
never chosen per request, so groups never overlap and cannot be subtracted.
Schemes: `all` (one group per way and direction), `daytype` (workday,
weekend), `daytype_band` (day type × the five bands of §3.4). The
default is `daytype`. Changing it is an admin action, logged.

### 4.6 Curator layer

**Switched off until it is in use** (owner 2026-10-08). The system setting
`traffic.map_layer_live` (default 0, system-configuration.md) decides whether
a curator's map carries the layer at all: at 0 the template leaves out the
*Measured traffic* row and its controls, at 1 they show as described here.
At 0 `GET /map/traffic` answers 404 as well. The ride review's traffic step
and `/moderate/traffic` do not depend on it.

`GET /map/traffic` (`ROLE_CURATOR`, two-factor checked in the controller as
for every `/map` curator route, `Cache-Control: private, no-store`) returns
`{groups: [...], shown: [{way, dir, label, group, traffic, nearby, carSpeedBand|null,
days}]}`, `groups` being the active scheme's in display order. `TrafficView`
builds it from the totals of the period by applying §4.4. It is recomputed on a fixed cadence of six hours, never per
upload, so no single upload can be read off as the difference between two
views; the cache key carries the scheme, so a scheme change shows at once and
never mixes with the old grouping. The cached copy is sealed like the waiting
room's rows (`TrafficCipher`). The map adds a curator-only row "Measured
traffic" under Map overlays (off by default, `web/assets/map/traffic-layer.js`)
that styles the road-pieces tiles by feature state: colour by the band of the
busier direction (quiet, moderate, busy), line pattern by label (path dotted, lane dashed, road solid).
Clicking a piece shows both directions' values. The group selector lists the
active scheme's groups, and a link opens the comparison.

**Comparison** (`/moderate/traffic`, curator with 2FA, "Measured traffic" under More on the moderation bar and linked from the map layer): road-surface items
whose source ref is an OSM way and that carry a declared `traffic` value
(the Traffic field of the road surface form: Quiet, Moderate, Busy, Free),
beside the measured band for the same way, when shown.

**Progress per region**, at the top of the same page, from the first ride:
per onboarded country, then per operational region, how many roads with data
in the period are **building data** (no group past §4.4 yet) and how many are
**usable** (at least one group past it), for example "Noord-Holland: 214
building data, 0 usable". Counts of roads only: a road's own numbers stay
hidden until it is usable, so the rule in §4.4 holds for curators too. Regions
without data show 0.
`TrafficProgress` builds it with the shown entries, on the same 6-hour
cadence, sealed in the same cache entry, with the time it was built; the page
says "Last built" with that time in the rider's date and time format and the
browser's own time zone (`cc_datetime` on the server, `ccDateTime` in the
browser), so a curator knows how old the numbers are. A road's region comes with its lines:
the road-piece tiles carry it (`g`), the browser sends it (`region`), the totals
row keeps it. Lines still in the waiting room are not counted yet. No server table lists every road: for the onboarded countries such a
table would hold about 41 million rows (about 7 GB), and the tiles hold the
region anyway. The countries listed are the road-piece manifest's plus any
country that already has data; roads whose lines carry no region (tiles
without a `g`, or a road outside every region) are counted apart.

### 4.7 Account deletion

Nothing to find: no row names or codes a rider, so traffic data is not
personal data once it is stored, and account deletion leaves it as it is.

### 4.8 Development

`app:traffic:dump [--way=ID]`, registered only in the `dev` environment,
prints the plain totals and the waiting room's lines, decrypted, as tables. There is no
switch that turns the waiting room's encryption off.

## 5. Privacy notice and copy

The privacy page has a paragraph on traffic summaries (what is sent, that the
ride file is not, who sees results) in five locales, and the Scout wiki page
(`wiki/scout.md`, "Your ride stays yours") says the same; a change to what is
sent changes both. Copy never states the disclosure constants, the dedupe
method, endpoints or key handling.

## 6. Where it lives

Pipeline: `pipeline/coverage/roadpieces.py`, `tiles.build_roadpieces_pmtiles`,
`run.py --roadpieces`. Browser: `web/assets/lib/road-pieces.js`,
`traffic-match.js`, `traffic-summary.js`, `traffic-ride.js`, `ride-archive.js`,
`traffic-colours.js`; `web/assets/map/scout-traffic.js`, `scout-bulk.js`,
`traffic-layer.js`. Server: `web/src/Traffic/` (`TrafficKeys`, `TrafficCipher`,
`TrafficPool`, `TrafficStore`, `TrafficLine`, `TrafficPayloadRefused`,
`TrafficIntake`, `TrafficDisclosure`, `TrafficView`, `TrafficProgress`, the
holidays and dump commands), `TrafficController`, `ModerateTrafficController`,
`RoadPiecesManifest`. Migrations: `Version20261005020000` (`traffic_seen`),
`Version20261005030000`, `Version20261007120000`, `Version20261007130000`
(`traffic_pool`, `traffic_total`) and `Version20261009010000` (BIGINT sums). Tests: `web/tests/Traffic/`,
`web/tests/js/traffic-*`, `road-pieces`, `ride-archive`, `ride-batch`,
`scout-traffic`, `pipeline/tests/test_roadpieces.py`.

## 7. Out of scope

Tags in bulk mode; Karoo radar fields (needs a sample file); a public layer;
writing the road-surface `traffic` field; rider-speed correction (the average
speed is stored for it); close-pass distance.

## 8. Decisions

Owner decisions, 2026-10-05 to 2026-10-07, after three security reviews:

- Per road piece: distance, the car count and car speeds. The browser matches
  the ride to road pieces; the ride line never leaves it.
- A road piece is one OSM way, with three labels; a painted lane is its own
  label.
- Dedupe by content, not by rider, so a second account cannot double-count;
  codes are kept for good, so archives of any age can be sent.
- Time is stored as five parts of the day, day type (holidays count as
  weekend) and quarter; the shown groups are chosen from those (§4.5). The
  browser cuts everything within 500 m of the start and the end of every
  ride and sends a day group
  instead of the date.
- No rider code. A code cannot stop a modified client from posing as many
  riders, it brings a password or recovery step to every rider, and a
  password-derived code lets the server (and offline guessing with the
  database and key) recover it. Lines carry no rider at all (§3.5).
- What a rider code would guard is kept by other means: an encrypted waiting
  room per block, left one block per request at random once it has 5 lines
  from 3 day groups, so a ride reaches the totals in pieces (§4.3); curators
  see only quiet, moderate or busy (§4.4).
- The waiting room counts lines, not hours: in the first year a block may wait
  for months, and a time-based batch would hold one ride alone.
- The totals are plain: they hold sums per road and time block, no ride and
  no rider. Only the waiting room, where a ride's lines still sit together, is
  encrypted. Development reads it through a dump command, never by switching
  encryption off.
- Disclosure: several lines, several day groups, a minimum distance,
  non-overlapping groups, only bands, and nothing at all below the rules.
- No cap per day or per upload: a six-month archive is one upload. Traffic is
  available to an account at once; signing in already needs a confirmed
  address.
- Curators first, as a map layer plus a comparison table. Bulk mode for
  archives, traffic only.
- The error tracker never receives a request body
  (`max_request_body_size: never`) and stack traces carry no call arguments
  (§4.2); curator views refresh on a fixed cadence;
  copy says the GPS track stays on the device and which roads were ridden is
  sent.
- Accepted, and to revisit when there is more data: a road ridden by one
  person on enough days can show; a frequent rider weighs more than an
  occasional one; gaming with modified clients or extra accounts stays
  possible, bounded by the plausibility rules and the per-account limit.

## 9. For later: the stronger design (not built)

**Not built.** Nothing in this section exists in the code; it is kept on the
owner's request (2026-10-07) as the upgrade path. It would add an anonymous
rider per road to the built design, so a road would show only with several
different riders, each rider one voice:

- **Rider key.** A random 256-bit key made once in the browser and never
  sent, kept as a non-extractable WebCrypto key; shown once as a 12-word
  recovery code, typed or scanned (QR) on a second device. Unlike a key
  derived from the password, the server cannot make it (it sees the password
  at sign-in) and it cannot be guessed offline.
- **Rider code.** HMAC of the road under the rider key, per road, so codes of
  two roads cannot be joined. The server stores HMAC(`voice`, code), shortened
  to 64 bits, per cell.
- **Cells.** Encrypted; totals, day groups, and per voice `[distance, passes,
  nearby, car speed sum, cars with speed]`. Lines still pass through the
  waiting room (§4.3).
- **Disclosure.** At least 5 voices each with 500 m or more in the group, 3 day
  groups and 2 km; the value is the median of the voices' own cars per km, no
  pooled fallback; curators see only the band.
- **Delete my traffic.** The browser makes the rider's codes for every road in
  an area the rider picks; the server removes the matching voices and their
  share of the totals.
- **Still open after it:** server-assisted codes (an oblivious PRF, rate-limited
  per account), the only way to stop a modified client from posing as many
  riders; anonymous uploads (one-time tokens, a relay); a versioned traffic key
  with rotation, a key store and a split offline copy; cars per km corrected
  for the rider's own speed.
