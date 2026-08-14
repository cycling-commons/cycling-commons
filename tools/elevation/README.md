<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Elevation tiles: Copernicus GLO-30 to Valhalla

Getting real elevation under climb profiles, so the gradients we publish are
measured rather than typed. The reasoning behind all of this — why the current
numbers cannot be trusted, which source, which bin width — is
[docs/specs/climb-elevation.md](../../docs/specs/climb-elevation.md). This file
is the procedure.

**Status:** in production. GLO-30 is the source, worldwide; the Europe set
(1137 `.hgt` tiles, 28 GB) replaced EU-DEM on the Valhalla host on 2026-08-05,
and the app reads it through `App\Elevation\ElevationClient` → Valhalla
`/height`.

## The short version

```bash
./fetch-glo30.sh EUROPE  ./data/dem/glo30      # download, no account needed
./to-hgt.sh     ./data/dem/glo30 ./data/dem/hgt # convert to Valhalla's format
node compare-sources.js <reference_hgt_dir> ./data/dem/hgt   # prove it first
```

Then copy the `.hgt` files to the Valhalla host and restart it.

`dem-install.sh` is those three stages plus the install, for one continent, as
one resumable command — it is what onboarding step 6b runs
(`tools/divisions/README.md`). Use it rather than the pieces unless you are
debugging one of them.

## Trying it locally

**Everything here runs from a checkout.** The defaults are the production
routing host's layout, but each is an env override, so nothing needs that host
— or root — to be exercised:

```bash
DEM_STAGE=./data/dem VALHALLA_DATA=./data \
  ./dem-install.sh valhalla RWANDA
```

That fetches the GeoTIFFs to `./data/dem/valhalla/tif`, converts them in the
GDAL container, and installs the `.hgt` into `./data/valhalla/elevation_data` —
which is exactly where the dev stack's own Valhalla looks, since
`compose.yaml` mounts `./data/valhalla` as `/custom_files`. So
`make up-routing` afterwards gives you a local instance answering `/height`
for that ground, and the whole step-6b procedure can be rehearsed before
anyone touches production.

Pick a small preset to try it on. `RWANDA` is 12 tiles / ~300 MB and finishes
in about a minute; `CANADAEAST` is 394 tiles and would spend an evening.

Two things that only bite outside the routing host, both now handled but worth
knowing if you edit the script:

- **`DEM_BIN` defaults to the script's own directory**, not `$DEM_STAGE/bin`.
  On the host all four scripts are copied into `/opt/dem/bin` together; from a
  checkout the siblings are simply next to it.
- **The GDAL container needs the scripts mounted as well as the data.** When
  `$DEM_BIN` is outside `$DEM_STAGE` — which is the normal case for a checkout
  — it gets its own read-only mount. Without it the run fails on
  `to-hgt.sh: No such file or directory`, which reads like a missing script
  and is really a missing mount.

### Which host, and which instance

The project runs **one Valhalla per continent**, each reading only the tiles in
its own `additional_data.elevation` directory. So a tile set belongs to the
instance whose ground it covers — Australia's tiles go to `valhalla-oceania`,
not to the Europe instance that happened to be first.

The app picks the instance from the climb's coordinates
(`App\Elevation\ElevationEndpoints`, configured by `ELEVATION_URLS`). Its
`europe` box is a copy of the `EUROPE` preset's bbox: **widen both together, or
a climb lands on an instance holding no tiles for it.** That failure is not
silent-but-wrong — the instance answers all-zeros and the client rejects the
reply — so the cost is a missing profile, never a fabricated one.

Fetch and convert **on the Valhalla host**, never at home: the tiles are tens of
GB and the host pulls them at ~55 MB/s. Cap the conversion (`docker run
--cpus=6`) so it does not starve the live routing instances.

The comparison needs a second tile set to read against. Once EU-DEM is gone the
obvious reference is the *previous* GLO-30 build, which still catches a broken
conversion — a truncated warp or a wrong bbox shows up immediately, even if it
cannot tell you anything new about the dataset itself.

### Presets and what they cost

| preset | bbox | tiles (land only) | `.hgt` |
|---|---|---|---|
| `BE` / `NL` / `LU` | one country | ~15–20 each | ~0.4–0.5 GB |
| `BENELUX` | 49–54 N, 2–8 E | **26** (measured) | **644 MB** |
| `EUROPE` | 35–72 N, 11 W–32 E | **1137** (measured) | **28 GB** |
| `AUSTRALIA` | 44–9 S, 112–154 E | **890** (measured) | **22 GB** |
| `JAPAN` | 24–46 N, 122–146 E | **239** (measured) | **5.9 GB** |
| `USWEST` + `USROCKY` | California + Colorado | **165** (measured) | **4.1 GB** |

Sea-only cells do not exist in the bucket and are skipped, so the land count is
below the bbox cell count — 1137 of 1591 for Europe. The intermediate GeoTIFFs
are a further 27 GB, needed only during conversion.

The `EUROPE` figures were an estimate of "~900 tiles, 20–25 GB" until the run
happened; both were low. Budget from the measured numbers, not the guess.

`EUROPE` deliberately stops at 32 °E, short of the Urals and the Caucasus.
Every degree cell costs ~25 MB whether or not a climb is in it, so widen the
bbox when a country there is onboarded, not before.

The download is resumable — re-running skips whatever is already on disk, so an
interrupted `EUROPE` run picks up where it stopped.

## Why each step exists

### 1. Fetch — no account, no AWS CLI

GLO-30 Public is on the AWS Open Data registry, so the tiles are plain HTTPS
GETs against a public bucket. Registering with the Copernicus Data Space
Ecosystem is what unlocks the *restricted* instances; we do not need them.

Tiles are 1°×1°, named for their south-west corner. Sea-only cells do not
exist and are reported as absent rather than treated as failures — as are the
tiles GLO-30 Public withholds over a few countries.

Re-running skips what is already downloaded, so an interrupted fetch resumes.

### 2. Convert — and understand what it resamples

Valhalla's elevation service reads `.hgt`: a raw grid of big-endian `int16`
metres, 3601×3601, at **1 arc-second in both axes**.

GLO-30 is 1″ in latitude but **decimated in longitude** — 1.5″ at 50°N, coarser
further north. That is deliberate: meridians converge, so 1.5″ of longitude at
50°N is about 30 m of ground, the same as 1″ of latitude. Copernicus keeps its
cells roughly square. `.hgt` cannot express that, being 1″ by definition.

So the conversion **upsamples longitude onto the uniform grid**. It adds no
information and destroys none, which is why the measured accuracy holds up. Two
consequences:

- **The decimation factor changes by latitude band.** `to-hgt.sh` never
  hard-codes a pixel count; it gives `gdalwarp` a target grid and lets it read
  whatever the tile actually is. A converter that assumes 2400 columns will be
  wrong north of the band it was written in.
- **A `.hgt` from GLO-30 is larger than its information content warrants** —
  every tile is 24.7 MB regardless.

Tiles are cut from a **VRT mosaic**, not converted one at a time, because `.hgt`
tiles overlap their neighbours by one row and column. Convert them individually
and every tile gets a nodata stripe along its north and east edges.

Each output is checked to be exactly 25,934,402 bytes (3601 × 3601 × 2). A short
file means the warp silently clipped.

### 3. Compare — before trusting it

`compare-sources.js` reads two `.hgt` trees **with the same code** and reports
the numbers that matter. This matters more than it sounds: comparing through two
different services measures their interpolation as much as their data. Reading
the rasters directly does not.

```
climb                         length   eudem (A)       glo30 (B)       diff/bin  down A/B  disputed
Côte de la Redoute            2.08km   179m/8.6%       181m/8.7%         2.02pt  0/0       0/21
```

- **gain / average** are the published figures, so disagreement there is
  user-visible.
- **diff/bin** is the honest spread between two sources that are both plausible.
- **disputed** is the one that generalises: bins where the two sources disagree
  about whether the road *rises*. A descent both see is terrain; one only a
  single source sees is an artifact — and it needs no prior knowledge of the
  road, so it works on climbs nobody has profiled.
- **bins reading downhill** is reported but is **not** a quality score. Real
  climbs descend in their middles — 27 of Hockai's bins are genuine rail-trail
  descent. It only condemns a source on a road known to rise monotonically,
  which is what made it decisive against GLO-90 on La Redoute.

It also flags **geometry** faults that no elevation source can fix — a line
stored backwards, or running past its summit. Both exist in the seeded data.

## What the evaluation found

Measured 2026-08-04 on all seven seeded Wallonia climbs, comparing EU-DEM v1
against GLO-30:

- GLO-30 is **at least as good**. La Redoute: 181 m of gain against EU-DEM's
  179 and climbfinder's 180. That match against an independent reference is the
  load-bearing result.
- They disagree by **2.02 points per 100 m bin** on average. Both are Digital
  Surface Models — they include tree canopy, not bare ground — so local
  disagreement over a wooded stretch is expected from both.
- The sources dispute the **direction** of 33 of 266 bins, and the split is
  clean: Hockai's 27 descents are agreed between them and therefore real, while
  EU-DEM's lone downhill bin on Mur de Huy and on Bohissau are both disputed and
  therefore artifacts.

**Decision: GLO-30 is the single worldwide source.** No chain, no per-coordinate
order, no regional fallback — which retires the per-region raster management and
EU-DEM's regulated access terms rather than solving them.

The evidence is one tile at one latitude, so Benelux is the widening that
confirms it. Two limits survive the decision: GLO-30 Public withholds tiles over
a few countries, and it is a DSM.

Storage, for planning: a `.hgt` tile is 24.7 MB. Benelux is 26 tiles (644 MB);
global land is 14,000–26,000, or 340–630 GB. Scope rasters to onboarded
countries rather than the globe.

## Installing on Valhalla

The config key is `additional_data.elevation`, and its value is a path **inside
the container**. Find the real directory rather than assuming one:

```bash
docker inspect <container> --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{println}}{{end}}'
docker exec <container> sh -lc 'grep -A3 additional_data /custom_files/valhalla.json'
```

The `ghcr.io/valhalla/*` images keep config and data under `/custom_files`, not
`/data/valhalla` — guessing the latter wastes a round trip.

Swap by moving, never by copying over:

```bash
mv elevation_data elevation_data.old      # nothing is destroyed yet
mv <new hgt dir>  elevation_data
docker restart <container>
```

Copying *into* the existing directory is the trap: tile filenames are identical
between datasets **and so are the file sizes** (every 1-arc-second tile is
exactly 25,934,402 bytes), so a copy into the wrong place fails silently and the
old tiles simply keep answering. Only the timestamp distinguishes them.

**Verify before deleting the old set.** Ask the running service for heights along
a known climb and compare them against both tile sets locally — the one it
matches is the one it is serving. Expect a close but not exact match: two
conversions of the same source with different GDAL versions differed by 0.76 of a
percentage point per 100 m bin here, which is resampling noise, not a wrong tile.

Check ownership afterwards. A `mv` from a build directory can leave the live
directory owned by `root` where the rest of the deployment expects the service
account.

**No tile rebuild is needed** — the elevation service reads this directory
independently of the routing graph. `build_elevation` is a different feature: it
bakes grade into the *routing* tiles so bicycle costing can prefer flatter roads,
and that does need a rebuild. It is not required for climb profiles.

## Requirements

GDAL (`gdalbuildvrt`, `gdalwarp`, `gdal_translate`), `curl`, `python3`, Node.
No AWS credentials, no API keys.

## Licensing

Copernicus DEM carries attribution requirements, and EU-DEM's access is governed
by a GMES delegated regulation rather than an open-data licence. Read
[climb-elevation.md §2c](../../docs/specs/climb-elevation.md) before publishing
anything derived from either — the credit belongs on every surface that shows a
profile, not on a licences page.
