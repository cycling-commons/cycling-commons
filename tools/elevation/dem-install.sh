#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
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

# Defaults are the production routing host's layout, and every one of them is
# an env override so a contributor can run the whole thing against the dev
# stack instead (tools/elevation/README.md "Trying it locally"):
#
#   DEM_STAGE=./data/dem VALHALLA_DATA=./data \
#     tools/elevation/dem-install.sh valhalla SLOVENIA
#
# BIN defaults to THIS SCRIPT'S OWN directory, not $STAGE/bin, so a plain
# checkout works — the sibling scripts are right there. On the routing host all
# four are copied into /opt/dem/bin together, which satisfies it the same way.
STAGE="${DEM_STAGE:-/opt/dem}"
BIN="${DEM_BIN:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
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
#
# The script itself has to be visible INSIDE the container too. On the routing
# host $BIN lives under $STAGE (/opt/dem/bin) so one mount covers both; from a
# checkout it does not, and mounting only the stage dir fails with a
# "No such file or directory" on to-hgt.sh that reads like a missing script
# rather than a missing mount. So $BIN is mounted separately unless it is
# already inside $STAGE.
mounts=(-v "$STAGE:$STAGE")
case "$BIN/" in
  "$STAGE"/*) ;;                                   # already covered
  *) mounts+=(-v "$BIN:$BIN:ro") ;;
esac
log "convert $CONT in $GDAL_IMAGE (--cpus=$CONVERT_CPUS)"
docker run --rm --cpus="$CONVERT_CPUS" "${mounts[@]}" "$GDAL_IMAGE" \
  bash "$BIN/to-hgt.sh" "$TIF" "$HGT" || die "conversion failed for $CONT"
made=$(find "$HGT" -name '*.hgt' | wc -l)
log "converted $made .hgt, $(du -sh "$HGT" | cut -f1)"

# ── 3. install ────────────────────────────────────────────────────────────
# MOVE, not copy: the .hgt is the deliverable and a second copy on the same
# filesystem is 25.9 MB per tile of nothing. The .tif staging area is what to
# keep if anything — it is what a re-convert would need.
#
# THE INSTANCE MUST BE RESTARTED. Valhalla builds its elevation index at
# startup, so a running container answers `null` for a tile that is sitting in
# its own mount, readable, the whole time. An earlier version of this comment
# claimed skadi opens tiles lazily and no restart was needed; that is wrong,
# and it was wrong in the most expensive direction — 1,458 tiles installed on
# 2026-08-14 and every probe still returned null until the containers were
# restarted. `to-hgt.sh` said "restart Valhalla" all along.
#
# Restart PER CONTINENT, never the systemd unit: each continent is its own
# container (valhalla-africa, valhalla-south-america, …) and the unit's
# ExecStart starts all six, so `systemctl restart valhalla` interrupts routing
# for every continent and every project on the box. `docker restart
# valhalla-<continent>` touches one.
mkdir -p "$DEST"
moved=0
while IFS= read -r f; do
  mv -n "$f" "$DEST/" && moved=$((moved + 1))
done < <(find "$HGT" -name '*.hgt')
log "installed $moved tile(s) into $DEST"
log "instance $CONT now holds $(find "$DEST" -name '*.hgt' | wc -l) tiles, $(du -sh "$DEST" | cut -f1)"

cat <<EOF

NEXT, and none of the three is optional:
  1. RESTART THIS CONTINENT'S INSTANCE. Valhalla indexes elevation at startup,
     so until it restarts it answers null for tiles it can already read:
         docker restart valhalla-$CONT
     Per continent — NOT 'systemctl restart valhalla', which starts all six and
     so interrupts routing for every continent on the box.
  2. Add this continent to ELEVATION_URLS in the environment's local env file
     if it is not there. An instance with tiles that the app never asks is the
     same as no tiles: the box falls back to the DEFAULT instance, which
     answers null for ground it does not hold, and ElevationClient refuses it.
  3. Verify with a real summit whose height you know — AFTER the restart:
     curl -sG --data-urlencode 'json={"shape":[{"lat":..,"lon":..}]}' \\
       http://127.0.0.1:<port>/height
     A null means the tile is missing OR the instance has not been restarted;
     a plausible number means it is live.
EOF
