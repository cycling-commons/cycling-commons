#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# Download Copernicus DEM GLO-30 tiles covering a bounding box.
#
# GLO-30 Public is on the AWS Open Data registry and needs no account, no
# credentials and no AWS CLI - plain HTTPS GETs against the public bucket.
#
#   ./fetch-glo30.sh <preset|bbox> [out_dir]
#
#     ./fetch-glo30.sh BENELUX
#     ./fetch-glo30.sh 49,2,54,8 ./data/dem/glo30
#
# bbox is min_lat,min_lon,max_lat,max_lon in degrees.
#
# Tiles are 1x1 degree, named for their south-west corner. Downloads are
# skipped when the file already exists, so re-running after an interruption
# resumes rather than restarts.
set -euo pipefail

BUCKET="https://copernicus-dem-30m.s3.eu-central-1.amazonaws.com"
OUT="${2:-./data/dem/glo30}"

case "${1:-}" in
  # Whole-degree boxes that fully contain the country. GLO-30 has no tiles over
  # open sea, so sea-only cells 404 and are skipped rather than being an error.
  BE)      BBOX="49,2,52,7" ;;
  NL)      BBOX="50,3,54,8" ;;
  LU)      BBOX="49,5,51,7" ;;
  BENELUX) BBOX="49,2,54,8" ;;
  # Mainland Europe plus the British Isles, Scandinavia and the western
  # Mediterranean. Deliberately NOT the full EU-DEM extent: this stops at 32E,
  # short of the Urals and the Caucasus, because nothing east of it is onboarded
  # and each degree cell costs ~25 MB of .hgt whether or not a climb is in it.
  # Widen the bbox when a country there is onboarded, not before.
  EUROPE)  BBOX="35,-11,72,32" ;;
  # The 2026-08-06 rollout's continents. Each is the onboarded ground, not the
  # continent: AUSTRALIA stops at 44S (Tasmania) and leaves out Macquarie
  # Island, and USWEST is California + Colorado only, because a degree cell
  # costs ~25 MB of .hgt whether or not a climb is in it.
  AUSTRALIA) BBOX="-44,112,-9,154" ;;
  JAPAN)     BBOX="24,122,46,146" ;;
  USWEST)    BBOX="32,-125,43,-113" ;;   # California
  USROCKY)   BBOX="36,-110,42,-101" ;;   # Colorado
  # The 2026-08-14 rollout. Same rule as above — the onboarded ground, not the
  # continent — and the same reason: ~25 MB of .hgt per degree cell whether or
  # not a climb is in it. NOTE which Valhalla instance each feeds
  # (App\Elevation\ElevationEndpoints::BOXES): SLOVENIA is inside the `europe`
  # box and so belongs to the DEFAULT instance, while the other five feed
  # instances that are currently EMPTY and are not even listed in
  # ELEVATION_URLS — until they are, a climb there resolves to the default,
  # gets all-zeros back, and is honestly refused a profile by
  # ElevationClient::MIN_NONZERO_SHARE.
  # NOTE on the upper bounds below: the loop runs `seq LAT0 $((LAT1-1))`, and a
  # cell is named for its SOUTH-WEST corner — so LAT1/LON1 must be one degree
  # PAST the ground you want, or the last strip is silently missing. Three of
  # these were wrong on first write (RW's northern strip, CO's Caribbean coast,
  # CL's Cape Horn) and the gap only ever shows up later as a climb with no
  # profile, which is why it is spelled out here.
  SLOVENIA)  BBOX="45,13,47,17" ;;
  RWANDA)    BBOX="-3,28,0,32" ;;
  # South Africa including Lesotho and Eswatini, which the Geofabrik extract
  # bundles anyway; the Prince Edward Islands (46S) are deliberately out.
  SOUTHAFRICA) BBOX="-35,16,-22,33" ;;
  COLOMBIA)  BBOX="-5,-82,13,-66" ;;
  # Mainland Chile only. Easter Island (27S 109W) is 3,500 km offshore and
  # would cost a whole column of cells for one volcano.
  CHILE)     BBOX="-56,-76,-17,-66" ;;
  # Both main islands. The Chatham Islands (44S 176.5W) cross the antimeridian
  # and need their own explicit bbox — the loop below counts up from LON0.
  NEWZEALAND) BBOX="-48,166,-34,179" ;;
  CANADAWEST) BBOX="48,-140,61,-113" ;;  # British Columbia
  CANADAEAST) BBOX="44,-80,63,-56" ;;    # Québec
  "")      echo "usage: $0 <BE|NL|LU|BENELUX|EUROPE|AUSTRALIA|JAPAN|USWEST|USROCKY|SLOVENIA|RWANDA|SOUTHAFRICA|COLOMBIA|CHILE|NEWZEALAND|CANADAWEST|CANADAEAST|min_lat,min_lon,max_lat,max_lon> [out_dir]" >&2; exit 2 ;;
  *)       BBOX="$1" ;;
esac

IFS=, read -r LAT0 LON0 LAT1 LON1 <<<"$BBOX"
mkdir -p "$OUT"

echo "GLO-30: lat $LAT0..$LAT1, lon $LON0..$LON1 -> $OUT"
have=0; got=0; missing=0

for lat in $(seq "$LAT0" $((LAT1 - 1))); do
  for lon in $(seq "$LON0" $((LON1 - 1))); do
    # N50E005 style: always zero-padded, hemisphere letters from the sign.
    if [ "$lat" -ge 0 ]; then NS=N; alat=$lat; else NS=S; alat=$((-lat)); fi
    if [ "$lon" -ge 0 ]; then EW=E; alon=$lon; else EW=W; alon=$((-lon)); fi
    name=$(printf '%s%02d_00_%s%03d_00' "$NS" "$alat" "$EW" "$alon")
    tile="Copernicus_DSM_COG_10_${name}_DEM"
    dest="$OUT/${tile}.tif"

    if [ -s "$dest" ]; then have=$((have + 1)); continue; fi

    code=$(curl -sS -w '%{http_code}' -o "$dest.part" "$BUCKET/$tile/$tile.tif" || echo 000)
    if [ "$code" = "200" ]; then
      mv "$dest.part" "$dest"; got=$((got + 1))
      printf '  %s  %s\n' "$tile" "$(du -h "$dest" | cut -f1)"
    else
      rm -f "$dest.part"; missing=$((missing + 1))   # sea, or withheld by GLO-30 Public
    fi
  done
done

echo "done: $got downloaded, $have already present, $missing absent (sea or withheld)"
echo "next: ./to-hgt.sh $OUT <hgt_out_dir>"
