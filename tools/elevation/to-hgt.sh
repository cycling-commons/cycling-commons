#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# Convert Copernicus GLO-30 GeoTIFFs into SRTM-format .hgt tiles for Valhalla.
#
#   ./to-hgt.sh <geotiff_dir> [hgt_out_dir]
#
# Why a conversion is needed at all: Valhalla's elevation service (skadi) reads
# .hgt, a raw grid of big-endian int16 metres at 1 arc-second in BOTH axes.
# GLO-30 ships Cloud Optimized GeoTIFF at 1" latitude but DECIMATED longitude -
# 1.5" at 50N, coarser further north - because meridians converge and Copernicus
# keeps its ground cells roughly square. So this upsamples longitude onto the
# uniform .hgt grid. That adds no information and destroys none.
#
# The decimation factor CHANGES BY LATITUDE BAND, so nothing here hard-codes a
# pixel count: gdalwarp is told the target grid and reads whatever it is given.
#
# Tiles are cut from a VRT mosaic rather than converted one by one, so that a
# tile's north and east edges - which belong to its neighbours, since .hgt tiles
# overlap by one row and column - carry real values instead of nodata.
set -euo pipefail

# GDAL writes a .aux.xml statistics sidecar beside every output it computes
# stats for. Valhalla never reads them, and they double the file count in a
# directory whose whole job is to be copied somewhere else - which is how a
# 26-tile conversion got reported as 52 files.
export GDAL_PAM_ENABLED=NO

SRC="${1:?usage: $0 <geotiff_dir> [hgt_out_dir]}"
OUT="${2:-${SRC%/}/hgt}"
BUILD="$OUT/.build"

command -v gdalbuildvrt >/dev/null || { echo "GDAL not found (need gdalbuildvrt, gdalwarp, gdal_translate)" >&2; exit 1; }

mkdir -p "$OUT" "$BUILD"
shopt -s nullglob
tifs=("$SRC"/*.tif)
[ ${#tifs[@]} -gt 0 ] || { echo "no .tif in $SRC - run fetch-glo30.sh first" >&2; exit 1; }

echo "mosaicking ${#tifs[@]} GeoTIFFs..."
gdalbuildvrt -q "$BUILD/mosaic.vrt" "${tifs[@]}"

made=0
for tif in "${tifs[@]}"; do
  # Recover the tile's SW corner from the Copernicus filename.
  base=$(basename "$tif" .tif)
  [[ $base =~ ([NS])([0-9]{2})_00_([EW])([0-9]{3})_00 ]] || { echo "  skip $base (unrecognised name)"; continue; }
  ns=${BASH_REMATCH[1]}; lat=$((10#${BASH_REMATCH[2]}))
  ew=${BASH_REMATCH[3]}; lon=$((10#${BASH_REMATCH[4]}))
  [ "$ns" = "S" ] && lat=$((-lat))
  [ "$ew" = "W" ] && lon=$((-lon))
  name=$(printf '%s%02d%s%03d' "$ns" "${lat#-}" "$ew" "${lon#-}")

  # .hgt is pixel-is-POINT: 3601 samples spanning the full degree inclusive of
  # both edges. As an area extent that is half a cell beyond each corner.
  h=$(python3 -c "print(0.5/3600)")
  w=$(python3 -c "print($lon - $h)"); e=$(python3 -c "print($lon + 1 + $h)")
  s=$(python3 -c "print($lat - $h)"); n=$(python3 -c "print($lat + 1 + $h)")

  gdalwarp -q -overwrite -t_srs EPSG:4326 -te "$w" "$s" "$e" "$n" \
    -ts 3601 3601 -r bilinear -ot Int16 -dstnodata -32768 \
    "$BUILD/mosaic.vrt" "$BUILD/$name.tif"
  gdal_translate -q -of SRTMHGT "$BUILD/$name.tif" "$OUT/$name.hgt"
  rm -f "$BUILD/$name.tif"

  sz=$(stat -c%s "$OUT/$name.hgt")
  [ "$sz" -eq 25934402 ] || { echo "  $name.hgt is $sz bytes, expected 25934402" >&2; exit 1; }
  made=$((made + 1))
  printf '  %s.hgt\n' "$name"
done

rm -rf "$BUILD"
echo "done: $made .hgt tiles in $OUT ($(du -sh "$OUT" | cut -f1)) - copy the .hgt files, nothing else"
cat <<EOF

To serve them, on the Valhalla host:
  1. copy *.hgt into the directory valhalla.json's additional_data.elevation names
  2. restart Valhalla

No tile rebuild is needed: skadi reads elevation separately from the routing
graph. A rebuild (build_elevation) is only for hill-aware ROUTING costs.
EOF
