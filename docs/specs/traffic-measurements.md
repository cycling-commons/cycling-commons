# Traffic measurements from bike radar

**Status:** designed and built 2026-10-05. Owner-approved design; this document
describes what the code does.

A rider who rides with a rear radar records every overtaking car in the ride
file. Scout's ride review reads those passes in the browser and, with the
rider's consent per ride, sends a per-road summary: how far the rider rode on a
road piece, how many cars passed, how fast they drove when the device measured
it, and a coarse time key. The server adds each summary to encrypted running
totals. Curators see a measured-traffic map layer once a road piece passes the
disclosure rules. Nothing is public yet.

Related: [moderation-and-contribution.md](moderation-and-contribution.md)
(Scout intake), [privacy-notice.md](privacy-notice.md),
[coverage-provider.md](coverage-provider.md) (the tile pipeline),
[security-architecture.md](security-architecture.md).

## 1. Principles

1. **The ride never leaves the browser.** The server never receives a GPS
   track, a ride file, a time finer than a quarter hour or the order of a
   ride, not even in memory. It does receive the local date of each summary
   line, which the several-days rule needs, and stores it only inside an
   encrypted payload. The browser matches the ride to road pieces and sends per-piece
   summaries only. The Scout rule that a track-shaped payload is refused, not
   ignored, applies to the new endpoint too.
2. **No pattern of one rider is ever visible.** A road piece and time group is
   shown only when several riders on several days contributed and no single
   rider dominates. Below the rules nothing is shown, not even that data
   exists. This holds for curators too.
3. **A stolen database reveals nothing.** Every stored road, time, count and
   rider code is encrypted or keyed with secrets kept outside the database.
4. **Measure first, judge later.** Data is stored at fine resolution (15-minute
   slot, day type, season, quarter); the time groups shown are chosen later from
   the data. The measured value does not write the road-surface `traffic` field
   until curators have compared the two.
5. **Every value except the pass count is optional.** Devices differ: some
   record car speed and distance, some only count passes.

## 2. Road pieces

A road piece is one OpenStreetMap way. The pipeline builds a `roadpieces` tile
family next to the surface family (`pipeline/coverage/roadpieces.py`, run with
`run.py --roadpieces`), one PMTiles file per onboarded country
(`roadpieces-<cc>.pmtiles`), published under `roadpieces/<cc>/<stamp>/` and
listed in `roadpieces/manifest.json` in the same shape as the surface manifest.

**Zoom and geometry.** z14 only (`-z14 -Z14`), no feature dropping, feature id
= way id. One source layer per country, `roadpieces_<cc>`.

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

An extract older than `roadpieces.py` was labelled by other rules and is
rebuilt (`run.py` `ROADPIECES_RULES`), so a relabel never serves yesterday's
pieces.

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

`Review a ride` keeps its single-ride flow and gains a second step when the ride
has radar data (`countVehicles()` returns a result):

| The ride has | Step 1: tags | Step 2: traffic |
|---|---|---|
| tags and radar | as today | after "Next: traffic" |
| tags, no radar | as today | not shown |
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
review page (`#mk-scout`), and /map-key lists it. The radar total line is
hidden in step 2 because the step's own line carries it ("53 km matched to
roads, 41 cars").
Step 2 states the matched kilometres with their cars, and the radar total
beside them when some cars were off the matched roads ("52 km matched to roads,
39 of the 41 cars"); the unmatched kilometres ("1.2 km could not be matched to
roads (red on the map); that part and its cars are not sent", with an eye
button that zooms the map to one part at a time, longest first, with a "2/7"
counter when there are several); one button,
**Send traffic summary**; and below it one link, "Show what is sent", that
opens in place with the rule (per road, distance and cars; no ride line; times
to the quarter hour) and every line. With no road data at all, only that is
said. The click is the consent for that ride. The review page itself requires an account.

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

Records are grouped by **key** = (way, direction, label, slot, day type,
season, quarter), counting only seconds where the radar field is present:

- **slot**: local quarter hour of the day, 0-95. Local time = record time plus
  the offset `local_timestamp - timestamp` from the FIT activity message
  (message 34, field 5), rounded to whole quarter hours. Without it, the IANA zone of the ride's country (the
  tile's `cc`); multi-zone regions use the zone of the onboarded region.
- **day type**: `weekend` on Saturday, Sunday and the public holidays of the
  ride's country (`web/public/data/holidays/<cc>.json`, official holidays only,
  built by `app:traffic:holidays` from the Yasumi library for 2010 to two years
  ahead; rerun yearly), else `workday`. Chile and Rwanda have no provider and
  use the calendar only.
- **season**: meteorological, flipped south of the equator: Dec-Feb is
  `winter` north and `summer` south.
- **quarter**: `YYYY-Qn` of the local date.
- **day**: the local date as days since 1970-01-01. Not part of the key; it
  feeds the distinct-days rule (§4.4) and is stored only in the encrypted rider
  payload.

Per key: `distanceM` (sum of distances between consecutive radar-on records on
the same piece and direction, counted on the later record's line so a slot
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
latSemicircles + "|" + lonSemicircles)` of the first record inside the block,
full precision. The same file, edited or cut differently, yields the same codes;
two riders riding together yield different ones. A line lists the codes of its
blocks (a 15-minute slot holds 3).

Lines that share a block code, which in practice is one ride, always travel in
the same request: the server counts a line as a duplicate once another request
claimed one of its codes, so a ride split over two requests would lose its
second half. Rides are shuffled and packed into requests of about 500 lines
(never above 2000), and lines are shuffled inside each request
(`web/assets/lib/traffic-ride.js`: `makeChunks()`, `sendChunks()`). The review
keeps which requests were acknowledged, so sending again after a failure
resends only the rest.

## 4. On the server

### 4.1 Endpoint

`POST /scout/traffic`, `ROLE_USER`, stateless CSRF token id `scout-traffic` in
`X-CC-Token` (`window.CC_TRAFFIC_TOKEN`, review page only), limiter
`traffic_submit` (per user, 120 per hour, sized for bulk chunks). JSON:

```json
{"v": 1, "lines": [{
  "way": 4521877, "dir": "f", "label": "r",
  "slot": 73, "dayType": "workday", "season": "autumn", "quarter": "2026-Q4", "day": 20366,
  "distanceM": 3210, "timeS": 421, "passes": 7, "nearby": 0,
  "avgSpeedKmh": 27.4, "carSpeedBins": [0,0,0,0,0,1,3,2,1,0,0,0,0,0,0,0],
  "blocks": ["9f3a…", "c71e…", "02bd…"]
}]}
```

**Refused (422):** any top-level or line key in the Scout track list
(`track`, `points`, `trkpt`, `polyline`, `records`, `route`, `coordinates`,
`gpx`, `fit`, plus `lat`, `lng`, `lon`, `time`, `timestamp`); unknown keys;
more than 2000 lines; `v` other than 1.

**Plausibility, per line (line dropped, counted in the response):**
`timeS` 1..905 (a quarter hour plus the one interval a slot boundary hands to
the later line); `distanceM` ≤ 20000; average speed 3..80 km/h; `passes` and
`nearby` each ≤ 60 per km ridden and ≤ 300; `passes` 0 on a `p` line and
`nearby` 0 (or absent) on an `l` or `r` line; `day` within the line's `quarter`, at most one day
ahead of UTC (east of Greenwich), and the quarter likewise; `carSpeedBins` 16 non-negative integers summing to
≤ `passes` + `nearby`; `slot` 0..95; `quarter` from 2010-Q1 to the current quarter; 1 to 3
block codes of 64 hex characters; `label` in `p`, `l`, `r`; `dir` in `f`, `b`;
`region` absent, null or an integer ≥ 1.

Response: `{"ok": true, "added": n, "duplicate": n, "dropped": n}`.

### 4.2 Keys

`TRAFFIC_SECRET` (32 random bytes, base64), its own variable, never
`APP_SECRET` or `ENCRYPTION_SECRET`. HKDF-SHA256 derives five subkeys: `enc`
(AES-256-GCM payloads), `way`, `bucket`, `rider`, `seen` (HMAC-SHA256). The
committed base settings file leaves it empty; the dev stack passes a public
development key (`developers/docker/compose.yaml`), tests use the committed test
settings, and staging and production set their own. `TrafficKeys` refuses both
committed values in staging and production, and refuses an empty or short key
everywhere. Losing it makes every stored total unreadable,
so it is in the deploy secrets checklist (operations.md).

### 4.3 Tables

All three hold no plaintext road, time, count or user.

| Table | Columns | Holds |
|---|---|---|
| `traffic_cell` | `bucket_key bytea PK`, `payload bytea` | encrypted totals per key: way, region (the first line's, kept), dir, label, slot, day type, season, quarter, distance, time, passes, nearby (absent in a cell written before it existed: none), speed sum (km/h × s), passes with speed, car speed bins, contribution count |
| `traffic_rider` | `rider_key bytea PK`, `payload bytea` | encrypted per rider per way: per bucket key, distance and the set of local dates (days since epoch) |
| `traffic_seen` | `code bytea PK` | HMAC of each block code received; kept for good |

`bucket_key` = HMAC(`bucket`, the full key);
`rider_key` = HMAC(`rider`, user id + way id); `seen` code = HMAC(`seen`, the
client's block hash). No `created_at`/`updated_at`: write times would leak when
people ride. No column links the rows of one road: a row holds its own key and
its payload only. Each payload is bound to its row as AES-GCM associated data
(a payload moved to another row does not open) and padded to a multiple of
256 bytes, so its size says little about how much a rider row holds.

**Write path** (`TrafficIntake`), one transaction per request:

1. Claim the request's codes, in sorted order, with `INSERT … ON CONFLICT DO
   NOTHING`. A code another request holds, even one not yet committed, is not
   claimed (the insert waits for that request), so a retry of a request still
   in flight counts nothing twice.
2. A line with any code this request did not claim is a duplicate.
3. For each accepted line, in cell-key order: create the cell empty if absent,
   `SELECT … FOR UPDATE` it, decrypt, add, re-encrypt (fresh nonce); then, in
   road order, the same for the rider row, adding the line's distance and local
   date to its bucket entry. Creating the row before locking it makes two
   requests adding to a new row queue on one lock instead of both starting from
   zero, and the fixed order keeps them from deadlocking.

### 4.4 Disclosure rules (`TrafficDisclosure`)

A *group* is a set of keys of one way and direction, chosen by the active
grouping scheme (§4.5) and the period (default: the last 12 quarters). A group
is shown only when all hold:

- at least `MIN_RIDERS = 5` distinct rider rows contributed distance to it;
- their dates cover at least `MIN_DAYS = 3` distinct days;
- no rider contributed more than `MAX_SHARE = 0.5` of its distance, measured
  against the group's total; distance whose rider row is gone (an account
  deleted since) counts as one more contributor;
- its total distance is at least `MIN_DISTANCE_M = 2000`.

Constants live in `TrafficDisclosure`; public copy never states them.

Shown values: cars per km (passes ÷ km, one decimal), nearby cars per km (nearby ÷ km,
one decimal; a cycle path's only car figure, shown as nearby on the curator
layer and in the comparison table, while the path colours by its passing cars,
which is none), the median car speed band when at
least 5 passes in the group had a measured speed, and coarse bands instead of counts:
riders `5-9`, `10-19`, `20+`; days `3-9`, `10+`.

### 4.5 Grouping scheme

One scheme is active platform-wide (system setting `traffic.grouping`),
never chosen per request, so groups never overlap and cannot be subtracted.
Schemes: `all` (one group per way and direction), `daytype` (workday,
weekend), `daytype_band` (day type × 00-06, 06-09, 09-16, 16-19, 19-24). The
default is `daytype`. Changing it is an admin action, logged.

### 4.6 Curator layer

`GET /map/traffic` (`ROLE_CURATOR`, two-factor checked in the controller as
for every `/map` curator route, `Cache-Control: private, no-store`) returns
`{groups: [...], shown: [{way, dir, label, group, carsPerKm, carSpeedBand|null,
riders, days}]}`, `groups` being the active scheme's in display order. `TrafficView` builds it by decrypting all cells and rider rows
and applying §4.4. It is recomputed on a fixed cadence of six hours, never per
upload, so no single upload can be read off as the difference between two
views; the cache key carries the scheme, so a scheme change shows at once and
never mixes with the old grouping. The cached copy is sealed like the rows. The map adds a curator-only row "Measured
traffic" under Map overlays (off by default, `web/assets/map/traffic-layer.js`)
that styles the road-pieces tiles by feature state: colour by cars per km of
the busier direction (under 1, 1 to 3, 3 or more; a first reading for curators
to judge), line pattern by label (path dotted, lane dashed, road solid).
Clicking a piece shows both directions' values. The group selector lists the
active scheme's groups, and a link opens the comparison.

**Comparison** (`/moderate/traffic`, curator with 2FA, "Measured traffic" under More on the moderation bar and linked from the map layer): road-surface items
whose source ref is an OSM way and that carry a declared `traffic` value
(the Traffic field of the road surface form: Quiet, Moderate, Busy, Free),
beside the measured cars per km for the same way, when shown.

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
the road-piece tiles carry it (`g`), the browser sends it (`region`), the cell
keeps it. No server table lists every road: for the onboarded countries such a
table would hold about 41 million rows (about 7 GB), and the tiles hold the
region anyway. The countries listed are the road-piece manifest's plus any
country that already has data; roads whose lines carry no region (sent before
the tiles named regions, or outside every region) are counted apart.

### 4.7 Account deletion

`TrafficRiderDeletion` (an account-deletion hook) streams the rider rows one at
a time, reads each row's way from its payload, and deletes in one statement the
rows whose key is the leaving user's key for that way. The cells stay: they
hold no rider.

### 4.8 Development

`app:traffic:dump [--way=ID]`, registered only in the `dev` environment,
prints the decrypted cells and rider rows as tables. There is no switch that
turns encryption off.

## 5. Privacy notice and copy

The privacy page gains a paragraph on traffic summaries (what is sent, that the
ride file is not, who sees results) in five locales, and the Scout wiki page
("Your ride stays yours") is updated in the same change. Copy never states the
disclosure constants, the dedupe method, endpoints or key handling.

## 6. Where it lives

Pipeline: `pipeline/coverage/roadpieces.py`, `tiles.build_roadpieces_pmtiles`,
`run.py --roadpieces`. Browser: `web/assets/lib/road-pieces.js`,
`traffic-match.js`, `traffic-summary.js`, `traffic-ride.js`, `ride-archive.js`,
`traffic-colours.js`; `web/assets/map/scout-traffic.js`, `scout-bulk.js`,
`traffic-layer.js`. Server: `web/src/Traffic/` (`TrafficKeys`, `TrafficCipher`,
`TrafficStore`, `TrafficLine`, `TrafficIntake`, `TrafficDisclosure`,
`TrafficView`, `TrafficRiderDeletion`, the holidays and dump commands),
`TrafficController`, `ModerateTrafficController`, migration
`Version20261005020000`. Tests: `web/tests/Traffic/`, `web/tests/js/traffic-*`,
`road-pieces`, `ride-archive`, `scout-traffic`, `pipeline/tests/test_roadpieces.py`.

## 7. Out of scope

Tags in bulk mode; Karoo radar fields (needs a sample file); a public layer;
writing the road-surface `traffic` field; rider-speed correction (the average
speed is stored for it); close-pass distance.

## 8. Decisions

Recorded 2026-10-05 with the owner:

- Option C: count, distance and car speeds per road piece.
- The browser matches; the ride line never leaves it.
- A road piece is one OSM way, with three labels; a painted lane is its own label.
- One rider code per road piece, kept (not deleted at the threshold) so groups
  chosen later can still be checked and dominance computed.
- Dedupe by content, not by rider, so a second account cannot double-count;
  codes kept for good, so archives of any age can be sent.
- Stored at 15-minute slots with day type (holidays count as weekend), season
  and quarter; shown groups chosen later.
- Disclosure: several riders, several days, no dominant rider, minimum distance,
  non-overlapping groups, nothing at all below the rules.
- Encrypted at rest from the first row; development reads data through a dump
  command, never by switching encryption off.
- Curators first, as a map layer plus a comparison table.
- Bulk mode for archives, traffic only.
- After the first review: the error tracker never receives a request body
  (`max_request_body_size: never`); curator views refresh on a fixed cadence
  with one decimal; copy says the GPS track stays on the device and which roads
  were ridden is sent.
- Open for the owner: a curator who controls several accounts can still learn
  one other rider's contribution on a road where only that rider and the curator's own
  accounts ride; requiring an account age or verification before a rider
  counts would close it.
