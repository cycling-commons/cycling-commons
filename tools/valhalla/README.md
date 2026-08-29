<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Building Valhalla routing tiles

`tools/elevation/` gets elevation *served*. These get it **baked into the road
grades**, which is a different thing and needs a tile rebuild.

| script | runs on | does |
|---|---|---|
| `fetch-geofabrik-pbf.sh` | anywhere | continent OSM extracts, 2 streams, resumable, MD5-verified, backs off on Geofabrik's 502s |
| `fetch-global-dem.sh` | routing host | stages GLO-30 for a continent, batched so staging never exceeds ~21 GB |
| `build-continent-tiles.sh` | routing host | rebuilds one continent's tiles with the staged DEM baked in |
| `run-continents.sh` | routing host | the whole sequence, unattended, gated on real grades |

## The one thing to understand

**Road grades are frozen into the tiles at build time.** `use_hills` reads
`weighted_grade` off each edge, written during the `enhance` stage from whatever
DEM was in `elevation_data` at that moment. The DEM sitting there at *runtime*
answers `/height` only — it cannot retro-fit a grade onto a tile built without
one.

So swapping the DEM without rebuilding leaves the two halves disagreeing: routes
are **chosen** on the old DEM's grades while their climb is **measured** on the
new one. One host ran that way for five months without anything reporting a
problem.

## Order matters

```bash
./fetch-geofabrik-pbf.sh /opt/valhalla/pbf europe
./fetch-global-dem.sh europe            # installs RAW
./build-continent-tiles.sh europe       # bakes grades from those raw tiles
```

`fetch-global-dem.sh` installs **raw** on purpose. Skadi is proven to read
`.hgt.gz` at runtime; the tile *builder* is not, and a build that cannot read its
elevation writes flat grades and exits 0. Do not gamble a rebuild on it.

`--compress` exists, but read the warning in that script first: compression
trades disk for unreclaimable memory, and the measurement is not close.

## Verify the output, never the exit code

A tile build with no elevation succeeds and looks identical from `/status`. The
only proof is the grades:

```bash
# route a road you know climbs, then trace it
curl -s http://localhost:8002/trace_attributes -H 'Content-Type: application/json' \
  -d '{"encoded_polyline":"<shape>","costing":"bicycle","shape_match":"edge_walk",
       "filters":{"attributes":["edge.weighted_grade"],"action":"include"}}'
```

A healthy build returns a spread of distinct `weighted_grade` values. One value,
or all zeroes, means the DEM was not read. `run-continents.sh` does this
automatically after every build and stops the run if it fails.

A second check needing no tracing: route the same pair at `use_hills=0.0` and
`use_hills=1.0`. Different results means grades exist.

## Measured, on 12 cores and NVMe

| continent | PBF | build |
|---|---|---|
| oceania | 1.5 GB | 6 min |
| south-america | 3.9 GB | 21 min |
| africa | 7.4 GB | 31 min |
| north-america | 18 GB | 64 min |
| asia | 16 GB | 82 min |
| europe | 33 GB | 2 h 2 min |

Far short of the 6-30 hours older guides quote — that range comes from builds on
network block storage, which is also where the "use only 2 threads" advice comes
from. Neither applies on local NVMe.

See `wiki/developers/data-ops/elevation-tiles.md` for the reasoning, and
`docs/specs/operations.md` §3a for how the serving units should be arranged.
