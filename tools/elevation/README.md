<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Elevation tiles: Copernicus GLO-30 to Valhalla

Getting real elevation under climb profiles, so the gradients we publish are
measured rather than typed. The reasoning behind all of this — why the current
numbers cannot be trusted, which source, which bin width — is
[docs/specs/climb-elevation.md](../../docs/specs/climb-elevation.md). This file
is the procedure.

**Status:** the pipeline runs and has been used to evaluate GLO-30 against
EU-DEM on the seeded Wallonia climbs. Nothing in the application reads it yet.

## The short version

```bash
./fetch-glo30.sh BENELUX  ./data/dem/glo30      # download, no account needed
./to-hgt.sh     ./data/dem/glo30 ./data/dem/hgt # convert to Valhalla's format
node compare-sources.js <old_hgt_dir> ./data/dem/hgt   # prove it before shipping
```

Then copy the `.hgt` files to the Valhalla host and restart it.

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
climb                         length   eudem (A)       glo30 (B)       diff/bin  down A/B
Côte de la Redoute            2.08km   179m/8.6%       181m/8.7%         1.79pt  0/0
```

- **gain / average** are the published figures, so disagreement there is
  user-visible.
- **bins reading downhill** is the failure that made GLO-90 unusable. A climb
  that descends in its middle is impossible; any count above zero condemns the
  source at that bin width.
- **diff/bin** is the honest spread between two sources that are both plausible.

It also flags **geometry** faults that no elevation source can fix — a line
stored backwards, or running past its summit. Both exist in the seeded data.

## What the evaluation found

Measured 2026-08-04 on all seven seeded Wallonia climbs, comparing EU-DEM v1
against GLO-30:

- GLO-30 is **at least as good**. La Redoute: 181 m of gain against EU-DEM's
  179 and climbfinder's 180.
- GLO-30 produced **no impossible descent** on any short climb; EU-DEM produced
  one each on Mur de Huy and Bohissau.
- They disagree by **1.96 points per 100 m bin** on average. Both are Digital
  Surface Models — they include tree canopy, not bare ground — so local
  disagreement over a wooded stretch is expected from both.

That makes a **single worldwide source** viable, which would retire the
three-source chain, the per-region raster management, and EU-DEM's regulated
access terms. The evidence is one tile at one latitude, so Benelux is the
widening that would confirm it.

Storage, for planning: a `.hgt` tile is 24.7 MB. Benelux is tens of tiles;
global land is 14,000–26,000, or 340–630 GB. Scope rasters to onboarded
countries rather than the globe.

## Installing on Valhalla

```
additional_data.elevation  ->  the directory holding the .hgt files
```

Copy the tiles there and **restart** Valhalla. No tile rebuild is needed: the
elevation service reads this directory independently of the routing graph.

`build_elevation` is a different feature — it bakes grade into the routing tiles
so that bicycle costing can prefer flatter roads. That one does need a rebuild,
and it is not required for climb profiles.

## Requirements

GDAL (`gdalbuildvrt`, `gdalwarp`, `gdal_translate`), `curl`, `python3`, Node.
No AWS credentials, no API keys.

## Licensing

Copernicus DEM carries attribution requirements, and EU-DEM's access is governed
by a GMES delegated regulation rather than an open-data licence. Read
[climb-elevation.md §2c](../../docs/specs/climb-elevation.md) before publishing
anything derived from either — the credit belongs on every surface that shows a
profile, not on a licences page.
