#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Stage Copernicus GLO-30 for every continent, gzipped, one continent at a time.
# RUNS ON THE VALHALLA HOST.
#
#   ./fetch-global-dem.sh --plan                  # show what it would do, touch nothing
#   ./fetch-global-dem.sh europe                  # one continent, installed RAW
#   ./fetch-global-dem.sh --all                   # all six, in ascending size order
#   ./fetch-global-dem.sh --compress asia         # gzip a continent - SEE THE WARNING BELOW
#
# ---------------------------------------------------------------------------
# DO NOT REACH FOR --compress BY DEFAULT
#
# Gzip saves a lot of disk and costs memory in the one form the kernel cannot
# reclaim. Measured on one host, same load against two instances - 600 distinct
# 1-degree cells, 40 points each:
#
#     gzipped, 4 GB limit : anon +2474 MB, pinned at the limit, 985 max events
#     raw,    16 GB limit : anon    +0.8 MB
#
# A raw .hgt is mmap'ed, so its pages are file-backed and evictable; running out
# means slower requests. A gzipped tile is inflated into anonymous memory that
# can only be swapped and then OOM-killed, and skadi shows no sign of evicting
# it. The cost measured out at ~4.1 MB of unreclaimable memory per cell touched.
#
# Store raw unless you can prove the instance's working set is small, and keep
# the busiest instance raw regardless.
#
# ---------------------------------------------------------------------------
# WHY THIS EXISTS AND NOT JUST dem-install.sh
#
# `dem-install.sh` (owned by Cycling Commons) stages one bbox and is the right
# tool for onboarding a country. This is the different job: filling in the whole
# planet before a tile rebuild, where the two things that will hurt you are
# disk and time, not correctness.
#
#   - The intermediate GeoTIFFs are BIGGER than the finished tiles. Staging all
#     land at once is well over 500 GB and would fill this disk. So: fetch,
#     convert, install, DELETE the staging, then move to the next continent.
#   - `.hgt` is 24.7 MB per cell regardless of content. Gzipped it averages 30%
#     (measured: Dutch polder 7%, Swiss Alps 61%).
#
# WHY THIS INSTALLS RAW AND COMPRESSES SEPARATELY
#
# Two different pieces of Valhalla read elevation, and only one of them is
# proven to handle gzip:
#
#   - skadi, at runtime, answering /height. VERIFIED 2026-08-28 on this host:
#     the same tile raw and gzipped returned identical heights after a restart.
#   - valhalla_build_tiles -s enhance, at BUILD time, baking weighted_grade
#     into the road edges. NOT verified against .hgt.gz.
#
# A tile build that cannot read its elevation does not fail — it produces flat
# grades and says nothing, which is the exact failure this whole exercise is
# about. So do not gamble on it: install raw, build the tiles, then compress.
# `--compress <continent>` is the last step, after the grades are in.
#   - Every step is resumable. A cell that is already installed is skipped, so
#     re-running after an interruption continues rather than restarts.
#
# The authoritative cell list is Copernicus' own tileList.txt — the set of
# 1-degree cells that actually contain land. Antarctica is dropped: 7,044 tiles
# and no roads.
# ---------------------------------------------------------------------------
set -uo pipefail

VALHALLA_DATA="${VALHALLA_DATA:-/opt/valhalla/data}"
STAGE="${DEM_STAGE:-/opt/dem}"
DEM_BIN="${DEM_BIN:-/opt/dem/bin}"
GDAL_IMAGE="${GDAL_IMAGE:-ghcr.io/osgeo/gdal:ubuntu-small-latest}"
BUCKET="https://copernicus-dem-30m.s3.eu-central-1.amazonaws.com"
TILELIST="$STAGE/tileList.txt"
# Refuse to keep going when free disk drops under this. A tile build needs
# ~215 GB for Europe and a full disk takes the whole box down with it.
MIN_FREE_GB="${MIN_FREE_GB:-260}"
# Antarctica has no roads. Everything south of this is skipped.
MIN_LAT="${MIN_LAT:--60}"
GZIP_LEVEL="${GZIP_LEVEL:-6}"
# Half the box, so the routing containers keep their share while converting.
CONVERT_CPUS="${CONVERT_CPUS:-6}"
# Cells per batch. A whole continent staged at once is the trap: Asia is 8,551
# cells, which is ~240 GB of GeoTIFF plus ~206 GB of .hgt held simultaneously —
# more than this disk can spare once other continents are installed. Batching
# caps the staging at roughly BATCH x 53 MB regardless of continent size.
BATCH="${BATCH:-400}"

log() { echo "[$(date -u +%H:%M:%S)] $*"; }
die() { echo "ERROR: $*" >&2; exit 1; }

# Continent boxes: min_lat max_lat min_lon max_lon. Deliberately generous —
# the point of this pass is that no future country needs another tile rebuild.
# Cells with no GLO-30 tile simply are not in tileList.txt and cost nothing.
# A continent may need more than one box — Oceania straddles the dateline, and
# the leftovers of a single-box world are exactly the places people forget:
# Greenland, Svalbard, the Aleutians west of 172W, French Polynesia. Checked
# against Copernicus' own tileList: 22,611 of the 19,408 land cells above 60S are
# claimed (the excess is deliberate overlap between neighbouring continents).
#
# 51 cells are left out on purpose. They are the uninhabited sub-Antarctic and
# mid-ocean rocks — Kerguelen, Heard, Crozet, Bouvet, South Sandwich, Macquarie,
# the Antipodes, Tristan da Cunha, Pitcairn, Amsterdam Island, and a few Line
# Islands atolls. No road network, no riders. Everything with a road is in,
# including the ones a single-box world quietly drops: Reunion, Mauritius, the
# Seychelles, Cocos, Christmas Island, Easter Island, the Galapagos, Iceland,
# Svalbard, Greenland and French Polynesia. Small overlaps between continents are deliberate and harmless —
# each Valhalla instance has its own elevation directory.
box_for() {
  case "$1" in
    europe)        echo "34 82 -32 45" ;;                      # + Iceland, Svalbard, Azores
    north-america) echo "5 84 -180 -11" ;;                     # + Aleutians, Greenland
    south-america) echo "-56 14 -93 -33"$'\n'"-30 -24 -112 -104" ;; # + Galapagos, Easter Island
    asia)          echo "-13 82 25 180" ;;                     # + Cocos, Christmas Island
    africa)        echo "-36 38 -26 66" ;;                     # + Reunion, Mauritius, Seychelles
    oceania)       echo "-48 0 110 180"$'\n'"-30 0 -180 -128" ;; # + French Polynesia, Pitcairn
    *) return 1 ;;
  esac
}
ORDER=(oceania south-america africa north-america asia europe)

free_gb() { df -Pk "$VALHALLA_DATA" | tail -1 | awk '{print int($4/1024/1024)}'; }

cell_name() { # lat lon -> N49E006
  awk -v la="$1" -v lo="$2" 'BEGIN{
    printf "%s%02d%s%03d", (la<0?"S":"N"), (la<0?-la:la), (lo<0?"W":"E"), (lo<0?-lo:lo)
  }'
}

fetch_tilelist() {
  [ -s "$TILELIST" ] && return 0
  mkdir -p "$STAGE"
  log "Fetching the Copernicus tile list"
  curl -fsSL --max-time 300 "$BUCKET/tileList.txt" -o "$TILELIST" \
    || die "could not fetch tileList.txt"
  log "  $(wc -l < "$TILELIST") tiles exist worldwide"
}

# Cells for one continent that are on land, above MIN_LAT, and not yet installed.
cells_wanted() {
  local cont="$1" inst="$VALHALLA_DATA/$1/elevation_data"
  while read -r la0 la1 lo0 lo1; do
    [ -n "${la0:-}" ] || continue
    awk -v la0="$la0" -v la1="$la1" -v lo0="$lo0" -v lo1="$lo1" -v minlat="$MIN_LAT" '
      match($0, /_(N|S)([0-9][0-9])_00_(E|W)([0-9][0-9][0-9])_00_/, m) {
        la = (m[1]=="S" ? -m[2] : m[2]+0); lo = (m[3]=="W" ? -m[4] : m[4]+0)
        if (la < minlat) next
        if (la < la0 || la >= la1 || lo < lo0 || lo >= lo1) next
        printf "%s%02d%s%03d\n", (la<0?"S":"N"), (la<0?-la:la), (lo<0?"W":"E"), (lo<0?-lo:lo)
      }' "$TILELIST"
  done <<< "$(box_for "$cont")" | sort -u | while read -r c; do
      [ -f "$inst/$c.hgt.gz" ] || [ -f "$inst/$c.hgt" ] || echo "$c"
    done
}

PLAN_ONLY=0
COMPRESS=0
TARGETS=()
[ $# -gt 0 ] || die "usage: $0 --plan | --all | --compress <continent> | <continent …>"
while [ $# -gt 0 ]; do
  case "$1" in
    --plan) PLAN_ONLY=1; shift ;;
    --compress) COMPRESS=1; shift ;;
    --all)  TARGETS=("${ORDER[@]}"); shift ;;
    *) box_for "$1" >/dev/null || die "unknown continent '$1'"; TARGETS+=("$1"); shift ;;
  esac
done
[ ${#TARGETS[@]} -gt 0 ] || TARGETS=("${ORDER[@]}")

# ---------------------------------------------------------------------------
# --compress: gzip an already-installed continent. Run this AFTER its tiles are
# built, never before — the tile builder is not proven to read .hgt.gz.
# ---------------------------------------------------------------------------
if [ "$COMPRESS" -eq 1 ]; then
  for cont in "${TARGETS[@]}"; do
    inst="$VALHALLA_DATA/$cont/elevation_data"
    [ -d "$inst" ] || die "no elevation dir at $inst"
    n=$(ls -1 "$inst"/*.hgt 2>/dev/null | wc -l)
    if [ "$n" -eq 0 ]; then log "$cont: nothing raw left to compress"; continue; fi
    log "$cont: compressing $n tiles ($(du -sh "$inst" | cut -f1))"
    i=0
    for f in "$inst"/*.hgt; do
      [ -e "$f" ] || continue
      gzip -"$GZIP_LEVEL" -c "$f" > "$f.gz.part" && mv -f "$f.gz.part" "$f.gz" && rm -f "$f"
      i=$(( i + 1 ))
      [ $(( i % 500 )) -eq 0 ] && log "  $i/$n"
    done
    log "  $cont now $(du -sh "$inst" | cut -f1), $(free_gb) GB free"
    log "  RESTART REQUIRED: systemctl restart valhalla.service"
  done
  exit 0
fi

fetch_tilelist

# ---------------------------------------------------------------------------
# Plan
# ---------------------------------------------------------------------------
TOTAL=0
echo
printf "%-15s %10s %12s %12s\n" "continent" "to fetch" "raw" "gzipped"
for c in "${TARGETS[@]}"; do
  n=$(cells_wanted "$c" | wc -l)
  TOTAL=$(( TOTAL + n ))
  printf "%-15s %10s %9.0f GB %9.0f GB\n" "$c" "$n" \
    "$(awk -v n="$n" 'BEGIN{print n*24.7/1024}')" \
    "$(awk -v n="$n" 'BEGIN{print n*24.7*0.30/1024}')"
done
printf "%-15s %10s %9.0f GB %9.0f GB\n" "TOTAL" "$TOTAL" \
  "$(awk -v n="$TOTAL" 'BEGIN{print n*24.7/1024}')" \
  "$(awk -v n="$TOTAL" 'BEGIN{print n*24.7*0.30/1024}')"
echo
log "Installed RAW. Build the tiles, then: $0 --compress <continent>"
log "Free disk now: $(free_gb) GB (floor $MIN_FREE_GB GB)"

if [ "$PLAN_ONLY" -eq 1 ]; then
  log "--plan: nothing was changed."
  exit 0
fi

[ -r "$DEM_BIN/to-hgt.sh" ] || die "no to-hgt.sh at $DEM_BIN (source: CyclingCommons tools/elevation)"
docker image inspect "$GDAL_IMAGE" >/dev/null 2>&1 || { log "pulling $GDAL_IMAGE"; docker pull -q "$GDAL_IMAGE" >/dev/null || die "cannot pull $GDAL_IMAGE"; }

# ---------------------------------------------------------------------------
# Fetch, convert, gzip, install, clean — one continent at a time
# ---------------------------------------------------------------------------
for cont in "${TARGETS[@]}"; do
  inst="$VALHALLA_DATA/$cont/elevation_data"
  tif="$STAGE/$cont/tif"
  hgt="$STAGE/$cont/hgt"

  mapfile -t WANT < <(cells_wanted "$cont")
  if [ ${#WANT[@]} -eq 0 ]; then log "$cont: already complete"; continue; fi

  if [ "$(free_gb)" -lt "$MIN_FREE_GB" ]; then
    die "only $(free_gb) GB free, floor is $MIN_FREE_GB GB — stopping before $cont"
  fi

  batches=$(( (${#WANT[@]} + BATCH - 1) / BATCH ))
  log "=== $cont: ${#WANT[@]} cells in $batches batches of $BATCH ==="

  done_cells=0
  for (( off=0; off<${#WANT[@]}; off+=BATCH )); do
    slice=("${WANT[@]:off:BATCH}")
    bn=$(( off / BATCH + 1 ))

    if [ "$(free_gb)" -lt "$MIN_FREE_GB" ]; then
      die "only $(free_gb) GB free, floor is $MIN_FREE_GB GB — stopping in $cont batch $bn.
     Nothing is lost: re-run and it resumes from the first uninstalled cell."
    fi

    rm -rf "$tif" "$hgt"; mkdir -p "$tif" "$hgt" "$inst"

    # 1. download this batch's COGs
    got=0
    for c in "${slice[@]}"; do
      la="${c:0:3}"; lo="${c:3}"
      name="Copernicus_DSM_COG_10_${la:0:1}${la:1:2}_00_${lo:0:1}${lo:1:3}_00_DEM"
      out="$tif/${name}.tif"
      [ -s "$out" ] && continue
      # A cell in tileList always exists; a failure here is transient, so leave
      # the gap and let the next run pick it up rather than aborting the batch.
      curl -fsSL --max-time 300 "$BUCKET/${name}/${name}.tif" -o "$out" || rm -f "$out"
      got=$(( got + 1 ))
    done

    # 2. convert in the GDAL container.
    #    to-hgt.sh needs gdalbuildvrt/gdalwarp/gdal_translate and exits 1 without
    #    them. This host deliberately has no GDAL — it is a routing box, not a
    #    GIS box — so the converter runs in a throwaway container, the same way
    #    dem-install.sh does it. Mounting $STAGE covers both the staging dirs and
    #    $DEM_BIN, which lives inside it. --cpus leaves headroom for the routing
    #    containers, which serve traffic while this runs.
    #    Output is kept: a conversion failure needs its stderr, and suppressing
    #    it cost a batch on the first run.
    if ! docker run --rm --cpus="$CONVERT_CPUS" \
         -v "$STAGE:$STAGE" -e GDAL_PAM_ENABLED=NO "$GDAL_IMAGE" \
         bash "$DEM_BIN/to-hgt.sh" "$tif" "$hgt" > "$STAGE/convert-$cont.log" 2>&1; then
      tail -15 "$STAGE/convert-$cont.log" >&2
      die "conversion failed for $cont batch $bn — staging left at $tif, log at $STAGE/convert-$cont.log"
    fi

    # 3. install RAW. The tile builder reads these when it bakes grades, and it
    #    is not proven to handle .hgt.gz — compress with --compress afterwards.
    #    Write to .part and rename, so an interrupted run never leaves a
    #    truncated tile that looks installed.
    n=0
    for f in "$hgt"/*.hgt; do
      [ -e "$f" ] || continue
      b=$(basename "$f")
      cp -f "$f" "$inst/${b}.part" && mv -f "$inst/${b}.part" "$inst/${b}"
      n=$(( n + 1 ))
    done

    # 4. drop the staging NOW, before the next batch
    rm -rf "$tif" "$hgt"
    done_cells=$(( done_cells + n ))
    log "  batch $bn/$batches: $got fetched, $n installed  (total $done_cells/${#WANT[@]}, $(free_gb) GB free)"
  done

  log "  $cont done: $(ls -1 "$inst" | wc -l) tiles, $(du -sh "$inst" | cut -f1)"
done

echo
log "DONE. Installed per continent:"
du -sh "$VALHALLA_DATA"/*/elevation_data 2>/dev/null
log "Free disk: $(free_gb) GB"
cat <<'EOF'

Next:
  1. Check coverage from every consuming application:
       each should print the 1-degree cells it needs
  2. Fetch the OSM extracts:
       tools/valhalla/fetch-geofabrik-pbf.sh /opt/valhalla/pbf
  3. Build, one continent at a time, smallest first:
       tools/valhalla/build-continent-tiles.sh oceania
EOF
