<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# The OpenStreetMap data we use

The Cycling Commons map shows two kinds of data. Our own catalogue (what riders, curators and
our open-data sources added) is in the [bulk export](https://cyclingcommons.org/developers/export)
and the API. The rest, by far the largest part (water taps, bakeries, shelters, road surfaces,
signed cycle routes and much more), comes from [OpenStreetMap](https://www.openstreetmap.org). We do not
republish that part: you get it from OpenStreetMap itself, fresher than any copy of ours. This
page shows how to get **exactly the same objects** our map is built from.

## What we take from OpenStreetMap

The pipeline reads one country extract at a time and makes four passes over it, each with its
own `osmium tags-filter` expression list:

| Pass | What it keeps | OpenStreetMap types |
|---|---|---|
| `points` | places: drinking water, food, repair, shelter, sleep, climbs and the other kinds of the [data catalog](../../data-catalog.md) | nodes and ways |
| `surface` | the roads whose surface the map colours | ways |
| `routes` | signed cycle and mountain-bike routes, and the numbered junctions of node networks | relations and nodes |
| `roadpieces` | every road, used to match a ride to the roads it took | ways |

The tags behind `points` and `surface` live in one file, the coverage contract
`pipeline/contract/coverage-contract.json`. It is the single source of truth: the pipeline reads
it, and so does the script below.

## The script

`pipeline/osm_filters.py` prints the expressions for one pass. It needs Python 3 and nothing
else. A test in the pipeline suite fails if its output ever differs from the filters the pipeline
really runs.

<!-- CODE-FROM pipeline/osm_filters.py -->
```python
def points(contract: dict) -> list[str]:
    exprs: list[str] = []
    for spec in contract["letters"].values():
        for selector in spec["selectors"]:
            expr = "nw/" + selector["tag"]
            if expr not in exprs:
                exprs.append(expr)
    return exprs


def surface(contract: dict) -> list[str]:
    return ["w/highway=" + highway for highway in contract["surface"]["highways"]]


def routes(contract: dict) -> list[str]:
    return ["r/route=bicycle,mtb", "n/rcn_ref", "n/lcn_ref"]


def roadpieces(contract: dict) -> list[str]:
    return ["w/highway"]
```

## Step by step

You need [osmium-tool](https://osmcode.org/osmium-tool/) and Python 3. The example takes
Belgium; any country extract from [Geofabrik](https://download.geofabrik.de) works the same way.
We use the same Geofabrik extracts, refreshed once a week.

<!-- CODE-ILLUSTRATIVE shell commands to reproduce the Commons' OpenStreetMap subset for one country -->
```bash
# 1. The country extract, from Geofabrik
curl -fLO https://download.geofabrik.de/europe/belgium-latest.osm.pbf

# 2. Our contract and the filter script, from the public repository
curl -fLO https://raw.githubusercontent.com/cycling-commons/cycling-commons/main/pipeline/contract/coverage-contract.json
curl -fLO https://raw.githubusercontent.com/cycling-commons/cycling-commons/main/pipeline/osm_filters.py

# 3. One filtered file per pass
for pass in points surface routes roadpieces; do
  osmium tags-filter --overwrite -o "belgium-$pass.osm.pbf" belgium-latest.osm.pbf \
    $(python3 osm_filters.py "$pass" coverage-contract.json)
done

# 4. Optional: GeoJSON instead of PBF
osmium export belgium-points.osm.pbf -o belgium-points.geojson
```

`belgium-points.osm.pbf` now holds every object our map can show as a place in Belgium.
`osmium tags-filter` keeps the untagged nodes that a matched way is drawn with, so you can
compute a way's position as we do.

## What we do with the objects

The filter gives you the same raw objects. What the map then shows is decided by the same
contract, in `pipeline/coverage/parse.py`:

- **One kind per object.** Each matched tag maps to one of our kinds (the `selectors` of each
  letter in the contract), so an `amenity=drinking_water` node becomes a drinking-water place.
- **Extra rules per letter.** Some letters keep an object only with a name or with certain tags
  (`nameOrTags`), drop some tag values (`excludeTagValues`), or need a nearby way (`nearWay`).
- **Ways become points.** A matched way is shown at the centre of its nodes.
- **Our edits win.** When a rider or curator changed an OpenStreetMap object in the Commons, our
  copy is in the [bulk export](https://cyclingcommons.org/developers/export) with its
  `osm_ref`, and it replaces the OpenStreetMap object on our map.

To rebuild our map exactly, take the OpenStreetMap objects from this page, then replace every
object whose `osm_ref` appears in the bulk export with the export's record.

## Licence and credit

OpenStreetMap data is under the [Open Database License](https://opendatacommons.org/licenses/odbl/)
and must be credited as **© OpenStreetMap contributors**. Our bulk export is under the same
licence. If you combine the two, credit both: "Contains data from the Cycling Commons © contributors
and © OpenStreetMap contributors, under the Open Database License."
