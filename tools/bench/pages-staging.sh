#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# Benchmark the public website on staging: the pages a visitor actually opens,
# not the API. The sibling of api-staging.sh and it shares that script's rule
# about credentials: staging sits behind basic auth, curl reads the password
# from ~/.netrc by itself, and nothing here ever prints or passes it. Setup is
# in tools/bench/README.md.
#
# Three things are measured, because a page can be slow in three different ways:
#
#   1. Time to first byte, cold then warm. Cold is what the first visitor of
#      the hour pays, and on this site that is where the tile-bucket manifests
#      are read (docs/specs/coverage-provider.md §4).
#   2. Whether the page actually rendered. A 200 is not proof: the error page
#      is served with markup and a 500, and a template that renders empty is a
#      200. Each page therefore has to carry a marker string.
#   3. Page weight. The HTML is the small half. The stylesheets and scripts it
#      pulls are what a rider on a phone waits for, so those are followed and
#      totalled for the two heaviest pages.
#
# Usage: tools/bench/pages-staging.sh [concurrency] [requests-per-worker]
set -euo pipefail

HOST="${CC_BENCH_HOST:-https://staging.cyclingcommons.org}"
CONC="${1:-2}"
REQS="${2:-5}"

if [ ! -f "$HOME/.netrc" ]; then
  echo "No ~/.netrc. See tools/bench/README.md for the one-time setup." >&2
  exit 1
fi

OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"' EXIT

get() { curl -sS -n --max-time 60 "$@"; }

# The error page's title. Any page carrying it rendered a failure, whatever
# status code came with it.
BROKE='something broke'

# name|path|marker the rendered page must contain
CASES=(
  "landing|/|Cycling Commons"
  "about|/about|About"
  "developers|/developers|/v1/search"
  "regions|/regions|Regions"
  "map|/map|CC_COVERAGE_URL"
  "blog|/blog|Blog"
  "contact|/contact|Contact"
  "licenses|/licenses|ODbL"
  "privacy|/privacy|Privacy"
  "accessibility|/accessibility|Accessibility"
)

# A region page is the one public page built per row rather than per template,
# so it is worth timing. Discover a live slug instead of pinning one: the
# region list changes as countries are onboarded.
slug=$(get "$HOST/regions" | grep -oE '/regions/[a-z0-9-]+' | head -1 || true)
if [ -n "$slug" ]; then
  CASES+=("region-detail|$slug|Cycling Commons")
fi

echo "=== does the page render, and how fast (cold, then warm) ==="
printf '%-16s %8s %8s %6s %9s %8s  %s\n' page ttfb_cold ttfb_warm code html_kb marker path
fail=0
for c in "${CASES[@]}"; do
  IFS='|' read -r name path marker <<<"$c"

  # Cold: ask any cache in the chain to revalidate.
  read -r t_cold code size < <(get -o "$OUT/body" -H 'Cache-Control: no-cache' \
    -w '%{time_starttransfer} %{http_code} %{size_download}\n' "$HOST$path")
  t_warm=$(get -o /dev/null -w '%{time_starttransfer}' "$HOST$path")

  if grep -qiF "$BROKE" "$OUT/body"; then
    verdict=ERROR-PAGE
  elif grep -qF "$marker" "$OUT/body"; then
    verdict=ok
  else
    verdict=NO-MARKER
  fi
  [ "$verdict" = ok ] && [ "$code" = 200 ] || fail=$((fail + 1))

  printf '%-16s %8.3f %8.3f %6s %9.1f %8s  %s\n' \
    "$name" "$t_cold" "$t_warm" "$code" "$(echo "$size / 1024" | bc -l)" "$verdict" "$path"
done

# Concurrent load on the three pages that cost the most: the landing page
# everybody hits, the map, and a database-built region page. Kept to a few
# workers on purpose, so the run stays well inside the per-IP rate limit and
# measures the site rather than the limiter.
echo
echo "=== concurrent load: ${CONC} workers x ${REQS} requests ==="
printf '%-16s %8s %8s %8s %9s %7s\n' page p50 p95 max req/s non200
for c in "landing|/" "map|/map" "regions|/regions"; do
  IFS='|' read -r name path <<<"$c"
  get -o /dev/null "$HOST$path" >/dev/null 2>&1 || true
  : > "$OUT/times"
  start=$(date +%s.%N)
  for ((w = 0; w < CONC; w++)); do
    (
      for ((r = 0; r < REQS; r++)); do
        curl -sS -n -o /dev/null -w '%{time_total} %{http_code}\n' \
          --max-time 60 "$HOST$path" 2>/dev/null || echo "60.0 000"
      done
    ) >> "$OUT/times" &
  done
  wait
  end=$(date +%s.%N)
  sort -n "$OUT/times" > "$OUT/sorted"
  awk -v name="$name" -v start="$start" -v end="$end" '
    { t[NR]=$1; if ($2 != "200" && $2 != "304") bad++ }
    END {
      n=NR
      if (n == 0) { printf "%-16s no samples\n", name; exit }
      i50 = int(0.50*n + 0.999); if (i50 < 1) i50 = 1
      i95 = int(0.95*n + 0.999); if (i95 < 1) i95 = 1
      printf "%-16s %8.3f %8.3f %8.3f %9.1f %7d\n",
        name, t[i50], t[i95], t[n], n/(end-start), bad+0
    }' "$OUT/sorted"
done

# What the browser really downloads. The HTML names its stylesheets and
# scripts; follow them once each and total the bytes.
#
# Two numbers, because only one of them is real. A browser sends
# Accept-Encoding, so "wire" is what crosses the network and "raw" is what the
# server holds. For text assets the two differ several times over, so raw
# alone makes a page look much heavier than it is. Same-origin only: a font or
# a tile from another host is somebody else's budget, not this server's.
#
# Asking for gzip WITHOUT --compressed is deliberate: curl then leaves the body
# encoded, so %{size_download} counts the bytes that actually crossed the
# network. --compressed would decode first and report the raw size, and the
# content-length header cannot stand in for it either, because nginx sends
# gzipped responses chunked and omits that header.
wire_size() {
  get -H 'Accept-Encoding: gzip' -o /dev/null -w '%{size_download}' "$1"
}
raw_size() {
  get -o "${2:-/dev/null}" -w '%{size_download}' "$1"
}

echo
echo "=== page weight, HTML plus its own CSS and JS ==="
printf '%-16s %8s %10s %11s %11s %10s\n' page assets html_kb assets_kb wire_kb raw_kb
for c in "landing|/" "map|/map"; do
  IFS='|' read -r name path <<<"$c"

  html_wire=$(wire_size "$HOST$path")
  html_raw=$(raw_size "$HOST$path" "$OUT/page")

  refs=$(grep -oE '(href|src)="/[^"]+\.(css|js)[^"]*"' "$OUT/page" \
    | sed -E 's/^(href|src)="//; s/"$//' | sort -u)
  wire=0
  raw=0
  count=0
  for ref in $refs; do
    wire=$((wire + $(wire_size "$HOST$ref")))
    raw=$((raw + $(raw_size "$HOST$ref")))
    count=$((count + 1))
  done

  printf '%-16s %8s %10.1f %11.1f %11.1f %10.1f\n' "$name" "$count" \
    "$(echo "$html_wire / 1024" | bc -l)" \
    "$(echo "$wire / 1024" | bc -l)" \
    "$(echo "($html_wire + $wire) / 1024" | bc -l)" \
    "$(echo "($html_raw + $raw) / 1024" | bc -l)"
done

echo
if [ "$fail" -gt 0 ]; then
  echo "$fail page(s) did not render. ERROR-PAGE means the site served its own"
  echo "failure page; NO-MARKER means the page came back 200 without the"
  echo "content it is supposed to carry, which is the quieter of the two."
  exit 1
fi
echo "every page rendered."
