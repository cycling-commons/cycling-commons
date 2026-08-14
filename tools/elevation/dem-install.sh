#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# Install Copernicus GLO-30 elevation for one continent's Valhalla instance.
# RUNS ON THE VALHALLA HOST. Onboarding playbook step 6b
# (tools/divisions/README.md) — a country with no DEM gets no climb profiles.
#
#   ./dem-install.sh <continent> <preset|bbox> [<preset|bbox> …]
#
#     ./dem-install.sh africa RWANDA SOUTHAFRICA
#     ./dem-install.sh south-america COLOMBIA CHILE
#     ./dem-install.sh north-america CANADAWEST CANADAEAST
#
# `continent` is the instance directory name AND the ElevationEndpoints box key
# (App\Elevation\ElevationEndpoints::BOXES): africa, asia, europe,
# north-america, oceania, south-america. Presets live in fetch-glo30.sh.
#
# Three stages, each resumable — re-running skips what is already there:
#   1. FETCH    GeoTIFFs from the AWS Open Data bucket into $STAGE/<c>/tif
#   2. CONVERT  to .hgt in the GDAL container into $STAGE/<c>/hgt
#   3. INSTALL  move the .hgt into the instance's elevation_data
#
# WHY THE CONVERSION RUNS IN A CONTAINER: the host deliberately has no GDAL —
# it is a routing box, not a GIS box. `ghcr.io/osgeo/gdal:ubuntu-small-latest`
# supplies gdalbuildvrt/gdalwarp/gdal_translate for the length of the job and
# leaves nothing behind. `--cpus` caps it at half the host so the LIVE routing
# instances keep their share; this box serves production traffic while the
# conversion runs.
set -uo pipefail

STAGE="${DEM_STAGE:-/opt/dem}"
BIN="${DEM_BIN:-$STAGE/bin}"
VALHALLA_DATA="${VALHALLA_DATA:-/opt/valhalla/data}"
GDAL_IMAGE="${GDAL_IMAGE:-ghcr.io/osgeo/gdal:ubuntu-small-latest}"
CONVERT_CPUS="${CONVERT_CPUS:-6}"

log() { echo "[$(date -u +%H:%M:%S)] $*"; }
die() { echo "ERROR: $*" >&2; exit 1; }

CONT="${1:-}"; shift || true
[ -n "$CONT" ] && [ $# -gt 0 ] || die "usage: $0 <continent> <preset|bbox> [<preset|bbox> …]"

DEST="$VALHALLA_DATA/$CONT/elevation_data"
[ -d "$VALHALLA_DATA/$CONT" ] || die "no instance directory $VALHALLA_DATA/$CONT"
[ -x "$BIN/fetch-glo30.sh" ] || die "missing $BIN/fetch-glo30.sh (copy tools/elevation/ up first)"

TIF="$STAGE/$CONT/tif"
HGT="$STAGE/$CONT/hgt"
mkdir -p "$TIF" "$HGT" || die "cannot create $STAGE/$CONT"

# ── 1. fetch ──────────────────────────────────────────────────────────────
for area in "$@"; do
  log "fetch $CONT <- $area"
  "$BIN/fetch-glo30.sh" "$area" "$TIF" || die "fetch failed for $area"
done
tifs=$(find "$TIF" -name '*.tif' | wc -l)
log "staged $tifs GeoTIFF(s), $(du -sh "$TIF" | cut -f1)"
[ "$tifs" -gt 0 ] || die "nothing fetched"

# ── 2. convert ────────────────────────────────────────────────────────────
# Note the mount: to-hgt.sh mosaics the WHOLE tif dir into one VRT and cuts
# every tile from it, so a tile's north and east edges — which belong to its
# neighbours, .hgt tiles overlapping by one row and column — carry real values
# instead of nodata. That is why the whole continent is converted at once and
# not per country.
log "convert $CONT in $GDAL_IMAGE (--cpus=$CONVERT_CPUS)"
docker run --rm --cpus="$CONVERT_CPUS" -v "$STAGE:$STAGE" "$GDAL_IMAGE" \
  bash "$BIN/to-hgt.sh" "$TIF" "$HGT" || die "conversion failed for $CONT"
made=$(find "$HGT" -name '*.hgt' | wc -l)
log "converted $made .hgt, $(du -sh "$HGT" | cut -f1)"

# ── 3. install ────────────────────────────────────────────────────────────
# MOVE, not copy: the .hgt is the deliverable and a second copy on the same
# filesystem is 25.9 MB per tile of nothing. The .tif staging area is what to
# keep if anything — it is what a re-convert would need.
#
# Valhalla's skadi opens a tile lazily, on the first request that needs it, so
# new files are visible WITHOUT restarting the service. Verify with a /height
# for a known summit rather than assuming (see the runbook); do not restart
# valhalla.service to "make it pick them up" — one unit serves every continent
# and every project on this box.
mkdir -p "$DEST"
moved=0
while IFS= read -r f; do
  mv -n "$f" "$DEST/" && moved=$((moved + 1))
done < <(find "$HGT" -name '*.hgt')
log "installed $moved tile(s) into $DEST"
log "instance $CONT now holds $(find "$DEST" -name '*.hgt' | wc -l) tiles, $(du -sh "$DEST" | cut -f1)"

cat <<EOF

NEXT, and neither is optional:
  1. Add this continent to ELEVATION_URLS in web/.env.<env>.local if it is not
     there. An instance with tiles that the app never asks is the same as no
     tiles: the box falls back to the DEFAULT instance, which answers null for
     ground it does not hold, and ElevationClient refuses the reply.
  2. Verify with a real summit whose height you know:
     curl -sG --data-urlencode 'json={"shape":[{"lat":..,"lon":..}]}' \\
       http://127.0.0.1:<port>/height
     A null means the tile is missing; a plausible number means it is live.
EOF
