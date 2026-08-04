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
  "")      echo "usage: $0 <BE|NL|LU|BENELUX|EUROPE|min_lat,min_lon,max_lat,max_lon> [out_dir]" >&2; exit 2 ;;
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
