#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Download the latest continent OSM extracts from Geofabrik.
# RUNS ON THE VALHALLA HOST (or anywhere with disk to spare).
#
#   ./fetch-geofabrik-pbf.sh <out_dir> [continent …]
#
#     ./fetch-geofabrik-pbf.sh /opt/valhalla/pbf europe
#     ./fetch-geofabrik-pbf.sh /opt/valhalla/pbf            # all six
#
# Continent names are OUR names — the same directory keys used under
# /opt/valhalla/data and by App\Elevation\ElevationEndpoints. They are mapped
# to Geofabrik's own paths below, which are not always the same word
# ("oceania" is "australia-oceania" over there).
#
# WHY TWO STREAMS. Geofabrik serves the planet to everyone for free and asks
# that clients not open many parallel connections. Two is polite and still
# roughly doubles throughput on a long fat pipe. Raise STREAMS only if you have
# a reason, and never for a whole-planet sweep.
#
# Every download is resumable: re-running after an interruption continues the
# partial file rather than starting again, and a file that already matches its
# published MD5 is skipped entirely.
set -euo pipefail

BASE="${GEOFABRIK_BASE:-https://download.geofabrik.de}"
STREAMS="${STREAMS:-2}"
# Geofabrik serves the planet for free and does throttle. A 502/503 is their
# server saying "not now", not a broken URL, and over a six-continent run you
# will meet one. Back off and come back rather than failing the whole sweep.
RETRIES="${RETRIES:-5}"
RETRY_WAIT_S="${RETRY_WAIT_S:-120}"

log()  { echo "[$(date -u +%H:%M:%S)] $*"; }
die()  { echo "ERROR: $*" >&2; exit 1; }

# our name -> geofabrik path
geofabrik_path() {
  case "$1" in
    africa)        echo "africa" ;;
    asia)          echo "asia" ;;
    europe)        echo "europe" ;;
    north-america) echo "north-america" ;;
    south-america) echo "south-america" ;;
    oceania)       echo "australia-oceania" ;;
    *) return 1 ;;
  esac
}

ALL_CONTINENTS=(europe north-america south-america asia africa oceania)

OUT="${1:-}"
[ -n "$OUT" ] || die "usage: $0 <out_dir> [continent …]"
shift || true

CONTINENTS=("$@")
[ ${#CONTINENTS[@]} -gt 0 ] || CONTINENTS=("${ALL_CONTINENTS[@]}")

mkdir -p "$OUT"

if command -v aria2c >/dev/null 2>&1; then
  DOWNLOADER=aria2c
else
  DOWNLOADER=curl
  log "WARNING: aria2c not installed — falling back to single-stream curl."
  log "         apt-get install -y aria2   for the multi-stream path."
fi

# Geofabrik publishes "<md5>  <filename>" next to every extract.
verify_md5() {
  local file="$1" md5file="$2"
  [ -s "$md5file" ] || return 1
  local want have
  want="$(awk '{print $1}' "$md5file")"
  have="$(md5sum "$file" | awk '{print $1}')"
  [ "$want" = "$have" ]
}

# One attempt. Callers retry.
download_once() {
  local url="$1" dest="$2"
  case "$DOWNLOADER" in
    aria2c)
      # -c resume, -x/-s connections per file, --auto-file-renaming=false so a
      # retry overwrites the partial rather than making europe-latest.1.osm.pbf
      aria2c -c -x "$STREAMS" -s "$STREAMS" \
             --auto-file-renaming=false --allow-overwrite=true \
             --summary-interval=30 --console-log-level=warn \
             -d "$(dirname "$dest")" -o "$(basename "$dest")" "$url"
      ;;
    curl)
      curl -fL -C - --retry 3 --retry-delay 5 -o "$dest" "$url"
      ;;
  esac
}

# Retry with a widening gap. Both downloaders resume, so a retry continues the
# partial file rather than starting again.
download() {
  local url="$1" dest="$2" attempt=1 wait=$RETRY_WAIT_S
  while :; do
    if download_once "$url" "$dest"; then return 0; fi
    if [ "$attempt" -ge "$RETRIES" ]; then
      log "  gave up after $attempt attempts"
      return 1
    fi
    log "  attempt $attempt failed (Geofabrik is often 502/503 under load) — retrying in ${wait}s"
    sleep "$wait"
    attempt=$(( attempt + 1 ))
    wait=$(( wait * 2 ))
  done
}

FAILED=()

for cont in "${CONTINENTS[@]}"; do
  gf="$(geofabrik_path "$cont")" || { log "SKIP $cont — not a known continent"; FAILED+=("$cont"); continue; }

  pbf="$OUT/${cont}-latest.osm.pbf"
  md5="$OUT/${cont}-latest.osm.pbf.md5"
  url="$BASE/${gf}-latest.osm.pbf"

  log "=== $cont  ($url)"

  # The MD5 is tiny and always refetched: it is how we tell "already have the
  # current extract" from "have last month's extract under the same name".
  curl -fsSL --retry 3 --retry-delay 10 -o "$md5" "${url}.md5" \
    || { log "  could not fetch md5 — continuing without verification"; : > "$md5"; }

  if [ -f "$pbf" ] && verify_md5 "$pbf" "$md5"; then
    log "  up to date ($(du -h "$pbf" | cut -f1)) — skipping"
    continue
  fi

  if ! download "$url" "$pbf"; then
    log "  DOWNLOAD FAILED"
    FAILED+=("$cont")
    continue
  fi

  if [ -s "$md5" ] && ! verify_md5 "$pbf" "$md5"; then
    log "  MD5 MISMATCH — the file is corrupt or the extract rotated mid-download"
    log "  re-run to fetch it again; the bad file is left at $pbf"
    FAILED+=("$cont")
    continue
  fi

  log "  OK  $(du -h "$pbf" | cut -f1)"
done

echo
log "Downloaded into $OUT:"
ls -lh "$OUT"/*.osm.pbf 2>/dev/null || true

if [ ${#FAILED[@]} -gt 0 ]; then
  echo
  die "these continents did not complete: ${FAILED[*]}"
fi

echo
log "Next: tools/valhalla/build-continent-tiles.sh <continent>"
