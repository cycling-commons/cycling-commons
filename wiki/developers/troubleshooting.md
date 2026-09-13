<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# When an exercise does not work

Every course on this site ends its chapters with something to run. They all assume the same stack in
the same state, so when one fails it is almost always the state and not the exercise. This page is
the short list, in the order the failures actually happen.

## The exercise returns no rows

Usually not a bug in the query.

`make setup` is **not enough for these courses**. It seeds world reference data and four demo
accounts, which leaves `item`, `recommended_route` and `heat_point` empty, and leaves `coverage_poi`
not existing at all, because the coverage batch creates that table rather than a migration. One more
target fills them from data already committed to the repository, with no network:

<!-- CODE-ILLUSTRATIVE the one target that seeds what every exercise selects -->
```sh
make course-data
```

Course 1's [own Try it box](gis/index.md#try-it) is the check: 736 items and 10 coverage rows on a
fresh clone. If you get those two numbers, the seed is fine and the empty result is telling you
something real about the query.

A second, quieter cause: several exercises select by a seeded **name** rather than an id, because
ids differ on every install. If you renamed or re-seeded a pin, the `WHERE` clause matches nothing.

## `relation "coverage_poi" does not exist`

The coverage batch has never run on this stack. `make course-data` runs it against a committed
fixture, which creates the table and loads ten rows. Nothing else creates it.

## `make course-data` fails partway

The import is one transaction, so a failure anywhere rolls the whole thing back and the later steps
never run. Read the first error rather than the last line.

`No geometry kind for letter X` means the Makefile's export and the importer disagree about a
catalogue letter. That exact failure shipped once and went unnoticed for two weeks, which is why
`tools/check-course-data.py` now runs in CI and in `make wiki-check`. Run it directly for a precise
answer:

<!-- CODE-ILLUSTRATIVE the gate that names the mismatch precisely -->
```sh
python3 tools/check-course-data.py
```

## The stack answers on the wrong port, or not at all

The ports the exercises use, as the compose file maps them:

| what | port |
|---|---|
| the app, and the public API | 8001 |
| the wiki you are reading | 8013 |
| Postgres, from the host | 5433 |
| MinIO, where tiles are published | 9100 |
| the pipeline container's own endpoint | 8012 |

Every database exercise goes through the container rather than the host port, which is why they all
start `docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons`. If
that command fails, nothing further on the page will work.

## The map draws nothing, or draws almost nothing

Read the published manifest before anything else:

<!-- CODE-ILLUSTRATIVE read what is actually published before assuming the map is broken -->
```sh
curl -s http://localhost:9100/cc-maps/coverage/manifest.json | jq '{url, regions: (.regions|length), counts}'
```

A manifest naming one region with a handful of counts means something republished over your real
index. The usual cause is a coverage run against a fixture, including the one inside
`make course-data`, because the point arm publishes even when `--no-publish` is passed. Put it back
with `make coverage-tiles`, which rebuilds and publishes from the rows already in PostGIS in about
five minutes, and **not** with `make coverage-refresh`, which re-harvests every region over the
network.

A `404` on that URL means no coverage tiles have ever been published on this stack.

## A harvest exits non-zero

| exit | what it means | what to do |
|---|---|---|
| `2` | another coverage run holds the advisory lock | wait for it, or find the run that never finished |
| `1`, with a drift message | the new extract is more than 40% smaller than the last good one | investigate the download; do not lower the threshold |
| `1`, unseeded country | the country's regions are not seeded, so the ownership filter would drop every row | onboard the country first |
| `1`, md5 mismatch | Geofabrik's mirror is mid-update and its checksum does not match its file | wait and re-run |

After any harvest, one number is a pass or fail rather than a judgement: `SELECT count(*) FROM
coverage_poi WHERE region_id IS NULL` must be zero. Anything else means rows landed outside every
region polygon, and those rows are invisible to every scoped query on the site.

## The elevation exercises answer zero

A DEM that has no tile for a place does not raise an error. It answers `0`, and zero is a number, so
it flows all the way through to a published climb reading flat. There are three ways to be absent
and all of them are silent: no tiles installed, the instance not restarted after installing them, or
the instance not named in `ELEVATION_URLS`.

Ask for a summit whose height you know rather than a random point, and treat a zero as a failure
rather than an answer. [Building elevation tiles](data-ops/elevation-tiles.md) has the check and the
fix.

## The API exercises fail from a browser but work in curl

Two headers, and both are on the tile host rather than the API. A browser reading byte ranges out of
a PMTiles archive needs `Access-Control-Allow-Origin` **and** `Content-Range` exposed. A host that
allows the origin but hides the range header serves a map that silently draws nothing.

If the request is to `/v1/` and comes back `429`, read `Retry-After` and wait. The limit is per
client address, and the exercises are well under it unless something is looping.

## Nothing here matches

Two commands answer most of what is left:

<!-- CODE-ILLUSTRATIVE two checks that between them catch most of what is left -->
```sh
make wiki-check    # the wiki builds, its code quotes match, the numbers page is current
make check-env     # warns when the running app image no longer matches the repo's Dockerfile
```

The second one catches a class of failure that reads like a product bug: an image built before a
Dockerfile change, still running, with settings the repository no longer declares.
