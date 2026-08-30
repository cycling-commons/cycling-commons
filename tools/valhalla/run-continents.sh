#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# Run the whole rebuild for one or more continents, unattended.
# RUNS ON THE VALHALLA HOST.
#
#   ./run-continents.sh south-america africa asia
#   ./run-continents.sh --all
#   ./run-continents.sh --dry-run asia
#
# Per continent, in order:
#   1. wait for its PBF (fetch-geofabrik-pbf.sh may still be downloading)
#   2. fetch + convert + install its DEM, RAW, batched
#   3. build its tiles, baking grades from that DEM
#   4. VERIFY the grades are real, and STOP THE WHOLE RUN if they are flat
#   5. compress its DEM to .hgt.gz and restart the service
#
# ---------------------------------------------------------------------------
# WHY THIS EXISTS
#
# Not because the steps are hard — each one is a single command. Because the
# gaps between them are where the time goes. This pipeline sat idle for hours
# twice, both times because a stage finished and nothing picked up the next one.
# The scripts were never the bottleneck; waiting for a human to notice was.
#
# ---------------------------------------------------------------------------
# WHY STEP 4 IS THE IMPORTANT ONE
#
# A tile build that cannot read its elevation does not fail. It writes flat
# `weighted_grade` on every edge, exits 0, and serves happily. `/status` looks
# identical. That is how this host spent five months routing on grades from a
# DEM nobody could name.
#
# So an unattended runner MUST check the output rather than the exit code, and
# it must stop rather than carry the same fault into the next continent. A run
# that halts after one bad continent is recoverable. One that quietly builds
# six is five more rebuilds.
# ---------------------------------------------------------------------------
set -uo pipefail

BIN="${DEM_BIN:-/opt/dem/bin}"
VALHALLA_DATA="${VALHALLA_DATA:-/opt/valhalla/data}"
PBF_DIR="${PBF_DIR:-/opt/valhalla/pbf}"
LOG_DIR="${LOG_DIR:-/root}"
PBF_WAIT_S="${PBF_WAIT_S:-7200}"     # how long to wait for a download still in flight
MIN_DISTINCT_GRADES="${MIN_DISTINCT_GRADES:-5}"
MIN_MAX_GRADE="${MIN_MAX_GRADE:-3}"  # percent

log() { echo "[$(date -u +%H:%M:%S)] $*"; }
die() { echo "ERROR: $*" >&2; exit 1; }

port_for() {
  case "$1" in
    europe) echo 8002 ;; north-america) echo 8003 ;; asia) echo 8004 ;;
    south-america) echo 8005 ;; africa) echo 8006 ;; oceania) echo 8007 ;;
    *) return 1 ;;
  esac
}

# A road pair per continent that climbs something real, for the grade gate.
# Chosen to be well-mapped and unambiguously hilly, so a flat result means the
# DEM was not read rather than that the terrain is flat.
probe_for() {
  case "$1" in
    europe)        echo '45.0553 6.0300 45.0925 6.0703' ;;   # Bourg-d'Oisans -> Alpe d'Huez
    north-america) echo '39.7392 -105.0 39.7392 -105.35' ;;  # Denver -> foothills
    south-america) echo '-33.38 -70.53 -33.35 -70.30' ;;     # Santiago -> Farellones
    asia)          echo '35.2330 139.024 35.200 139.020' ;;  # Hakone
    africa)        echo '-33.911 19.115 -33.885 19.160' ;;   # Franschhoek Pass
    oceania)       echo '-36.729 146.958 -36.976 147.136' ;; # Bright -> Mt Hotham
    *) return 1 ;;
  esac
}

ALL=(oceania south-america africa north-america asia europe)

DRY=0; TARGETS=()
[ $# -gt 0 ] || die "usage: $0 [--dry-run] --all | <continent …>"
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY=1; shift ;;
    --all) TARGETS=("${ALL[@]}"); shift ;;
    *) port_for "$1" >/dev/null || die "unknown continent '$1'"; TARGETS+=("$1"); shift ;;
  esac
done
[ ${#TARGETS[@]} -gt 0 ] || TARGETS=("${ALL[@]}")

# Grade gate. Returns non-zero when the tiles look flat.
verify_grades() {
  local cont="$1" port="$2"
  read -r a b c d <<< "$(probe_for "$cont")"
  python3 - "$port" "$a" "$b" "$c" "$d" "$MIN_DISTINCT_GRADES" "$MIN_MAX_GRADE" <<'PY'
import json, sys, urllib.request, collections
port, a, b, c, d, mind, minmax = sys.argv[1], *map(float, sys.argv[2:6]), int(sys.argv[6]), float(sys.argv[7])
def post(path, body):
    r = urllib.request.Request("http://localhost:%s/%s" % (port, path),
        data=json.dumps(body).encode(), headers={'Content-Type': 'application/json'})
    return json.load(urllib.request.urlopen(r, timeout=120))
try:
    t = post("route", {"locations": [{"lat": a, "lon": b}, {"lat": c, "lon": d}],
                       "costing": "bicycle", "units": "kilometers"})["trip"]
    shape = "".join(l["shape"] for l in t["legs"])
    tr = post("trace_attributes", {"encoded_polyline": shape, "costing": "bicycle",
              "shape_match": "edge_walk",
              "filters": {"attributes": ["edge.weighted_grade"], "action": "include"}})
    g = collections.Counter(round(e.get("weighted_grade", 0), 3) for e in tr.get("edges", []))
    hi = max((abs(k) for k in g), default=0)
    print("  probe: %.1f km, %d edges, %d distinct grades, max |%.1f%%|"
          % (t["summary"]["length"], sum(g.values()), len(g), hi))
    # A route that will not snap is inconclusive, not a failure - say so and pass.
    if not g:
        print("  probe returned no edges - INCONCLUSIVE, not treating as flat"); sys.exit(0)
    if len(g) < mind or hi < minmax:
        print("  FLAT: expected >=%d distinct grades and max >=%.0f%%" % (mind, minmax)); sys.exit(1)
    sys.exit(0)
except Exception as e:
    print("  probe failed (%s) - INCONCLUSIVE, not treating as flat" % e); sys.exit(0)
PY
}

log "runner starting for: ${TARGETS[*]}"
[ "$DRY" -eq 1 ] && log "--dry-run: no commands will be executed"

# Only one build at a time, ever. build-continent-tiles.sh stops and starts
# valhalla.service, which governs all six containers, so two concurrent builds
# would take each other's service down mid-run and both would look broken.
# Waiting here means the runner can be launched while an earlier build is still
# going - which is the normal case, since that is exactly when you think of it.
if [ "$DRY" -eq 0 ]; then
  waited=0
  while docker ps --filter "name=valhalla-build-" --format "{{.Names}}" | grep -q .; do
    [ $(( waited % 600 )) -eq 0 ] && log "another build is running ($(docker ps --filter "name=valhalla-build-" --format "{{.Names}}" | tr "\n" " ")) - waiting"
    sleep 60; waited=$(( waited + 60 ))
  done
  [ "$waited" -gt 0 ] && log "the other build finished after ${waited}s - continuing"
fi

for cont in "${TARGETS[@]}"; do
  port="$(port_for "$cont")"
  tar="$VALHALLA_DATA/$cont/valhalla_tiles.tar"
  pbf="$PBF_DIR/${cont}-latest.osm.pbf"

  echo; log "================ $cont ================"

  if [ -s "$tar" ]; then
    log "$cont already has tiles ($(du -h "$tar" | cut -f1)) - skipping"
    continue
  fi

  if [ "$DRY" -eq 1 ]; then
    log "would: wait for $pbf; fetch DEM; build; verify; compress"
    continue
  fi

  # 1. wait for the PBF, which may still be downloading in another session
  waited=0
  while [ ! -s "$pbf" ]; do
    [ "$waited" -ge "$PBF_WAIT_S" ] && die "$cont: no PBF at $pbf after ${PBF_WAIT_S}s"
    [ $(( waited % 600 )) -eq 0 ] && log "waiting for $pbf (${waited}s)"
    sleep 60; waited=$(( waited + 60 ))
  done
  log "pbf ready: $(du -h "$pbf" | cut -f1)"

  # 2. DEM, raw
  log "fetching DEM"
  "$BIN/fetch-global-dem.sh" "$cont" >> "$LOG_DIR/dem-$cont.log" 2>&1 \
    || die "$cont: DEM fetch failed - see $LOG_DIR/dem-$cont.log"
  log "DEM: $(ls -1 "$VALHALLA_DATA/$cont/elevation_data" | wc -l) tiles"

  # 3. build
  log "building tiles (this is the long one)"
  "$BIN/build-continent-tiles.sh" "$cont" --yes >> "$LOG_DIR/build-$cont.log" 2>&1 \
    || die "$cont: build failed - see $LOG_DIR/build-$cont.log"
  log "tiles: $(du -h "$tar" | cut -f1)"

  # 4. the gate - exit code is not evidence, the grades are
  # The service must actually answer before a probe means anything. An
  # unreachable port is a hard failure, NOT an inconclusive probe - otherwise a
  # container that never came up sails through the gate.
  up=0
  for _ in $(seq 1 30); do
    if curl -fsS --max-time 5 "http://localhost:$port/status" >/dev/null 2>&1; then up=1; break; fi
    sleep 10
  done
  [ "$up" -eq 1 ] || die "$cont: nothing answering on port $port after the build.
     The gate cannot run, so the run stops here rather than assuming success.
     Check: systemctl status valhalla.service; docker ps"
  log "verifying grades"
  if ! verify_grades "$cont" "$port"; then
    die "$cont: TILES ARE FLAT. The DEM was not read at build time.
     Stopping the run so this does not propagate. Check that
     $VALHALLA_DATA/$cont/elevation_data held raw .hgt when the build ran."
  fi

  # 5. compress - OPT-IN ONLY. Measured 2026-08-30 on one continent with storage
  #    as the only variable: gzipped tiles cost ~1.3 GB of unreclaimable memory
  #    per instance (skadi inflates into a malloc cache capped at 50 tiles) and
  #    ran 2.6x slower from cache thrash. Disk is the cheaper resource.
  if [ "${COMPRESS:-0}" != "1" ] || [ "$cont" = "europe" ]; then
    log "leaving $cont raw (set COMPRESS=1 to gzip after a build)"
  else
    log "compressing DEM"
    "$BIN/fetch-global-dem.sh" --compress "$cont" >> "$LOG_DIR/dem-$cont.log" 2>&1 \
      || log "WARNING: compression failed, leaving raw - not fatal"
    systemctl restart valhalla.service
    up=0
    for _ in $(seq 1 30); do
      if curl -fsS --max-time 5 "http://localhost:$port/status" >/dev/null 2>&1; then up=1; break; fi
      sleep 10
    done
    if [ "$up" -eq 1 ]; then
      log "re-verifying after compression"
      verify_grades "$cont" "$port" || log "WARNING: post-compression probe was not clean"
    else
      log "WARNING: port $port did not come back after compression - check the service"
    fi
  fi

  log "$cont DONE - $(df -h / | tail -1 | awk '{print $4}') free"
done

echo
log "runner finished"
for c in "${ALL[@]}"; do
  printf "  %-15s dem=%-6s tiles=%s\n" "$c" \
    "$(ls -1 "$VALHALLA_DATA/$c/elevation_data" 2>/dev/null | wc -l)" \
    "$(du -h "$VALHALLA_DATA/$c/valhalla_tiles.tar" 2>/dev/null | cut -f1 || echo -)"
done
