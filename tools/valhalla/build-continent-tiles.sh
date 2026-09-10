#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Rebuild one continent's Valhalla routing tiles, with Copernicus GLO-30
# elevation baked into the road grades.
# RUNS ON THE VALHALLA HOST.
#
#   ./build-continent-tiles.sh <continent> [--pbf PATH] [--yes] [--keep-pbf]
#
#     ./build-continent-tiles.sh europe
#     ./build-continent-tiles.sh asia --pbf /opt/valhalla/pbf/asia-latest.osm.pbf
#
# ---------------------------------------------------------------------------
# WHY THIS SCRIPT EXISTS
#
# Road grades are frozen into the tiles at build time. `use_hills` reads those
# baked grades and nothing else. The DEM sitting in elevation_data at RUNTIME
# only answers /height and `elevation_interval` — it cannot retro-fit a grade
# onto a tile that was built without one.
#
# So a DEM swap with no rebuild leaves the two halves disagreeing: routes are
# CHOSEN on the old DEM's grades while their climb is MEASURED on the new DEM.
# That is exactly the state this box was in between 2026-08-05 and this script.
#
# ---------------------------------------------------------------------------
# WHY build_elevation IS FALSE, AND MUST STAY FALSE
#
# The image's own elevation step runs:
#
#     valhalla_build_elevation --from-tiles --decompress
#
# which pulls SRTM-derived tiles from elevation-tiles-prod. We deliberately use
# Copernicus GLO-30 instead — the alternatives are too coarse for the gradient
# work — and mixing the two inside one elevation_data directory would give
# neighbouring cells different vertical accuracy AND make the licence notice
# untrue. Stage GLO-30 first with dem-install.sh (owned by CyclingCommons,
# deployed to /opt/dem/bin on this host), then build with build_elevation=False.
#
# The tiles still get their grades: configure_valhalla.sh always writes
# additional_data.elevation into the config, and the `enhance` stage reads
# whatever .hgt files are already there. Downloading is the only thing we skip.
# ---------------------------------------------------------------------------
set -uo pipefail

VALHALLA_DATA="${VALHALLA_DATA:-/opt/valhalla/data}"
PBF_DIR="${PBF_DIR:-/opt/valhalla/pbf}"
IMAGE="${VALHALLA_IMAGE:-ghcr.io/valhalla/valhalla-scripted:3.8.3}"
# The host is 12 cores on NVMe RAID1 with 62 GB RAM. The widely-repeated
# "use 2 threads" advice comes from builds on network block storage, where the
# bottleneck is random I/O on the intermediate files and extra threads only
# starve each other. On local NVMe that does not apply, so leave 4 cores for the
# worker containers and give the build the rest.
BUILD_CPUS="${BUILD_CPUS:-0-7}"

log()  { echo "[$(date -u +%H:%M:%S)] $*"; }
die()  { echo "ERROR: $*" >&2; exit 1; }

port_for() {
  case "$1" in
    europe)        echo 8002 ;;
    north-america) echo 8003 ;;
    asia)          echo 8004 ;;
    south-america) echo 8005 ;;
    africa)        echo 8006 ;;
    oceania)       echo 8007 ;;
    *) return 1 ;;
  esac
}

CONT="${1:-}"
[ -n "$CONT" ] || die "usage: $0 <continent> [--pbf PATH] [--yes] [--keep-pbf]"
shift

PORT="$(port_for "$CONT")" || die "unknown continent '$CONT' (europe north-america south-america asia africa oceania)"

PBF=""
ASSUME_YES=0
KEEP_PBF=0
while [ $# -gt 0 ]; do
  case "$1" in
    --pbf)      PBF="${2:-}"; shift 2 ;;
    --yes|-y)   ASSUME_YES=1; shift ;;
    --keep-pbf) KEEP_PBF=1; shift ;;
    *) die "unknown option '$1'" ;;
  esac
done

DATA="$VALHALLA_DATA/$CONT"
ELEV="$DATA/elevation_data"
TILE_TAR="$DATA/valhalla_tiles.tar"
TILE_DIR="$DATA/valhalla_tiles"
CONTAINER="valhalla-$CONT"
[ -n "$PBF" ] || PBF="$PBF_DIR/${CONT}-latest.osm.pbf"

# =============================================================================
# Preflight — everything that can say no, says no before anything is touched
# =============================================================================
log "Preflight for $CONT"

[ -d "$DATA" ]  || die "no instance directory at $DATA"
[ -f "$PBF" ]   || die "no PBF at $PBF — run tools/valhalla/fetch-geofabrik-pbf.sh first"

ELEV_COUNT=$(ls -1 "$ELEV" 2>/dev/null | wc -l)
if [ "$ELEV_COUNT" -eq 0 ]; then
  die "elevation_data is EMPTY at $ELEV.
     Building now would produce tiles with no grades — the exact bug this
     script exists to prevent. Stage the DEM first:
       /opt/dem/bin/dem-install.sh $CONT <preset …>"
fi
log "  elevation: $ELEV_COUNT .hgt tiles ($(du -sh "$ELEV" | cut -f1))"

PBF_KB=$(du -sk "$PBF" | cut -f1)
# Measured: intermediate work files peak at roughly 5x the PBF, and the finished
# tiles add another 1x. 20 GB of headroom on top so a full disk never takes the
# routing service down with it.
NEED_KB=$(( PBF_KB * 6 + 20 * 1024 * 1024 ))
FREE_KB=$(df -Pk "$DATA" | tail -1 | awk '{print $4}')
log "  pbf:  $(du -h "$PBF" | cut -f1)"
log "  disk: $(( FREE_KB / 1024 / 1024 )) GB free, need ~$(( NEED_KB / 1024 / 1024 )) GB"
if [ "$FREE_KB" -lt "$NEED_KB" ]; then
  die "not enough free disk. Reclaim space first — the DEM staging dirs under
     \${DEM_STAGE:-/opt/dem}/<continent> are intermediate GeoTIFFs that can be
     deleted once their .hgt tiles are installed."
fi

command -v docker >/dev/null || die "docker not found"
docker image inspect "$IMAGE" >/dev/null 2>&1 || log "  image $IMAGE not present locally — it will be pulled"

OLD_STAMP=$(curl -fsS --max-time 10 "http://localhost:$PORT/status" 2>/dev/null \
            | sed -n 's/.*"tileset_last_modified":\([0-9]*\).*/\1/p')
[ -n "$OLD_STAMP" ] && log "  current tileset_last_modified: $OLD_STAMP ($(date -u -d "@$OLD_STAMP" '+%Y-%m-%d %H:%M UTC'))"

# =============================================================================
# Confirm — this replaces live routing tiles and takes the continent offline
# =============================================================================
cat <<EOF

  About to rebuild:   $CONT
  Instance dir:       $DATA
  PBF:                $PBF
  Elevation:          $ELEV_COUNT tiles (Copernicus GLO-30)
  Serving:            valhalla.service — ALL SIX CONTINENTS STOP, not just this one
  Old tiles:          kept as ${TILE_TAR}.bak until the new ones pass

  Expect 6-30 hours for a continent the size of Europe.
  Run this under tmux/screen.

  BEFORE YOU CONFIRM: the tiles you are about to replace currently carry
  grades for the WHOLE continent, baked from the SRTM the previous build
  downloaded itself. This rebuild bakes only what is in elevation_data above.
  Every cell without a GLO-30 tile comes back FLAT, silently.

  Check coverage from every consuming application first: each should be able
  to print the 1-degree cells it needs. Proceed only when all of them are
  covered - a cell nobody staged comes back FLAT.

EOF

if [ "$ASSUME_YES" -ne 1 ]; then
  printf "Type the continent name to proceed: "
  read -r CONFIRM
  [ "$CONFIRM" = "$CONT" ] || die "aborted"
fi

# =============================================================================
# Build
# =============================================================================
STARTED_AT=$(date -u +%s)

# The serving containers are created with --rm by valhalla.service, which owns
# ALL SIX at once. So they cannot be started or stopped individually with
# docker, and stopping one continent to rebuild it stops the others too. That
# is a property of the unit, not a choice made here.
log "Stopping valhalla.service (this stops all six continents)"
systemctl stop valhalla.service >/dev/null 2>&1 || log "  (was not running)"

if [ -f "$TILE_TAR" ]; then
  log "Keeping the old tar as $(basename "$TILE_TAR").bak for rollback"
  mv -f "$TILE_TAR" "${TILE_TAR}.bak"
fi
rm -rf "$TILE_DIR"

log "Staging PBF into the instance directory"
cp -f "$PBF" "$DATA/"
STAGED_PBF="$DATA/$(basename "$PBF")"

BUILDER="valhalla-build-$CONT"
docker rm -f "$BUILDER" >/dev/null 2>&1 || true

log "Starting build (cpus $BUILD_CPUS). Follow with: docker logs -f $BUILDER"
docker run --rm --name "$BUILDER" \
  --cpuset-cpus="$BUILD_CPUS" \
  -v "$DATA:/custom_files" \
  -e use_tiles_ignore_pbf=False \
  -e force_rebuild=True \
  -e build_elevation=False \
  -e build_admins=True \
  -e build_time_zones=True \
  -e build_tar=True \
  -e serve_tiles=False \
  "$IMAGE"
BUILD_RC=$?

ELAPSED=$(( $(date -u +%s) - STARTED_AT ))
log "Build exited rc=$BUILD_RC after $(( ELAPSED / 3600 ))h $(( (ELAPSED % 3600) / 60 ))m"

if [ "$BUILD_RC" -ne 0 ] || [ ! -s "$TILE_TAR" ]; then
  log "BUILD FAILED — rolling back"
  [ -f "${TILE_TAR}.bak" ] && mv -f "${TILE_TAR}.bak" "$TILE_TAR"
  systemctl start valhalla.service >/dev/null 2>&1 || true
  die "build failed; the previous tiles were restored and valhalla.service restarted"
fi

log "New tar: $(du -h "$TILE_TAR" | cut -f1)"

[ "$KEEP_PBF" -eq 1 ] || { log "Removing the staged PBF copy"; rm -f "$STAGED_PBF"; }

# =============================================================================
# Serve + verify
# =============================================================================
log "Starting valhalla.service"
systemctl start valhalla.service || die "could not start valhalla.service"

for _ in $(seq 1 60); do
  curl -fsS --max-time 5 "http://localhost:$PORT/status" >/dev/null 2>&1 && break
  sleep 5
done

NEW_STAMP=$(curl -fsS --max-time 10 "http://localhost:$PORT/status" 2>/dev/null \
            | sed -n 's/.*"tileset_last_modified":\([0-9]*\).*/\1/p')
[ -n "$NEW_STAMP" ] || die "valhalla-$CONT is not answering /status on port $PORT"

log "tileset_last_modified: ${OLD_STAMP:-none} -> $NEW_STAMP ($(date -u -d "@$NEW_STAMP" '+%Y-%m-%d %H:%M UTC'))"

cat <<EOF

DONE — $CONT rebuilt.

  Rollback if something looks wrong:
    systemctl stop valhalla.service
    mv -f ${TILE_TAR}.bak $TILE_TAR
    systemctl start valhalla.service

  Delete the rollback copy once you are happy:
    rm -f ${TILE_TAR}.bak

VERIFY THE GRADES ACTUALLY LANDED. This is the whole point of the rebuild —
a tar that builds fine but carries no elevation looks identical from /status.
Trace a road you know is steep and check weighted_grade spreads across buckets:

  curl -s http://localhost:$PORT/trace_attributes -H 'Content-Type: application/json' \\
    -d '{"encoded_polyline":"<shape from a /route call>","costing":"bicycle",
         "shape_match":"edge_walk",
         "filters":{"attributes":["edge.weighted_grade"],"action":"include"}}'

A healthy Europe build returns ~16 distinct weighted_grade values. All-zero, or
one single value, means the DEM was not read — rebuild with elevation staged.

Any cache keyed on tileset_last_modified invalidates itself here, because that
stamp just changed. The next run that uses it pays a full cold rebuild. That is
intended - the stored values were measured on the old road graph.
EOF
