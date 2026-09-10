#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Benchmark the public API on staging.
#
# Credentials come from ~/.netrc. curl reads that file itself; this script
# never prints it and never passes a password on a command line, so nothing
# secret can reach a terminal transcript.
#
# Usage: tools/bench/api-staging.sh [concurrency] [requests-per-worker]
set -euo pipefail

HOST="${CC_BENCH_HOST:-https://staging.cyclingcommons.org}"
# Four cases x (1 warm + CONC*REQS) + 8 single requests. At 2x5 that is 52,
# comfortably inside the 120-a-minute per-address budget the README explains.
# Raise it and the run measures the limiter instead of the application.
CONC="${1:-2}"
REQS="${2:-5}"

if [ ! -f "$HOME/.netrc" ]; then
  echo "No ~/.netrc. See tools/bench/README.md for the one-time setup." >&2
  exit 1
fi

# One entry per endpoint we want a number for. Cheap first, expensive last.
declare -a CASES=(
  "map-config|/v1/map-config"
  "search-small|/v1/search?letter=B&bbox=5.6,50.3,6.1,50.6"
  "search-wide|/v1/search?bbox=3.3,50.7,7.3,53.6&limit=500"
  "search-curated|/v1/search?tier=curated&bbox=3.3,50.7,7.3,53.6&limit=500"
)

OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"' EXIT

warm() {
  curl -sS -n -o /dev/null --max-time 30 "$HOST$1" || true
}

# Sequential timings: what one caller feels, cold cache and warm cache.
echo "=== single request (cold, then warm) ==="
printf '%-16s %8s %8s %8s %10s\n' case ttfb_cold ttfb_warm code bytes
for c in "${CASES[@]}"; do
  name="${c%%|*}"; path="${c#*|}"
  cold=$(curl -sS -n -o "$OUT/body" -w '%{time_starttransfer} %{http_code} %{size_download}' \
         -H 'Cache-Control: no-cache' --max-time 60 "$HOST$path")
  read -r t_cold code size <<<"$cold"
  warm=$(curl -sS -n -o /dev/null -w '%{time_starttransfer}' --max-time 60 "$HOST$path")
  printf '%-16s %8.3f %8.3f %8s %10s\n' "$name" "$t_cold" "$warm" "$code" "$size"
done

# Concurrent load: many callers at once, which is what a small box actually
# fails at. Each worker fires REQS sequential requests; we collect every
# total-time and compute the percentiles from the raw list.
echo
echo "=== concurrent load: ${CONC} workers x ${REQS} requests ==="
printf '%-16s %8s %8s %8s %8s %9s %7s\n' case p50 p95 p99 max req/s non200
for c in "${CASES[@]}"; do
  name="${c%%|*}"; path="${c#*|}"
  warm "$path"
  : > "$OUT/times"
  start=$(date +%s.%N)
  for ((w=0; w<CONC; w++)); do
    (
      for ((r=0; r<REQS; r++)); do
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
      wall = end - start
      i50 = int(0.50*n + 0.999); if (i50 < 1) i50 = 1
      i95 = int(0.95*n + 0.999); if (i95 < 1) i95 = 1
      i99 = int(0.99*n + 0.999); if (i99 < 1) i99 = 1
      printf "%-16s %8.3f %8.3f %8.3f %8.3f %9.1f %7d\n",
        name, t[i50], t[i95], t[i99], t[n], n/wall, bad+0
    }' "$OUT/sorted"
done

echo
echo "Note: 429 answers count as non200. If non200 is high, the per-IP rate"
echo "limit (120/min) hit first and these numbers measure the limiter, not the app."
