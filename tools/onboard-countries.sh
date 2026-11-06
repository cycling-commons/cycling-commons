#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Onboard one or more countries on the dev stack in one command
# (wiki/developers/data-ops/onboarding-a-country.md):
#
#   tools/onboard-countries.sh AD MC PT
#   make country-onboard c="AD MC PT"
#
# The five documented steps, for every country given: plan (pipeline), one
# confirmation, apply (worker), one repair for all of them together
# (pipeline), elevation check and mark live (worker). A country already seeded
# skips plan and apply, a live one is left alone, so a run that stopped is
# finished by starting it again with the same codes.
#
# Options:
#   --release R        Overture release for the plans (default: the planner's pin)
#   --plan-args "..."  more planner flags for every country planned, e.g.
#                      "--extract europe/italy --shared" for San Marino
#   --yes              no confirmation after the plans
set -euo pipefail

here="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
compose=(docker compose -f "$(dirname "$here")/developers/docker/compose.yaml")
pipeline=("${compose[@]}" exec -T pipeline)
worker=("${compose[@]}" exec -T worker)

die() { printf 'onboard: %s\n' "$*" >&2; exit 1; }
say() { printf '\n\033[1m[onboard] %s\033[0m\n' "$*"; }

release=() plan_args=() yes=0 codes=()
while (($#)); do
  case "$1" in
    --release) [[ $# -ge 2 ]] || die "--release needs a value"; release=(--release "$2"); shift 2 ;;
    --plan-args) [[ $# -ge 2 ]] || die "--plan-args needs a value"; read -r -a plan_args <<<"$2"; shift 2 ;;
    --yes) yes=1; shift ;;
    -h|--help) sed -n '3,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*) die "unknown option $1" ;;
    *) for c in $1; do codes+=("${c^^}"); done; shift ;;
  esac
done
((${#codes[@]})) || die 'pass the country codes, e.g. tools/onboard-countries.sh AD MC PT'
for c in "${codes[@]}"; do [[ $c =~ ^[A-Z]{2}$ ]] || die "$c is not an ISO 3166-1 alpha-2 code"; done
# Each code once, in the order given.
mapfile -t codes < <(printf '%s\n' "${codes[@]}" | awk '!seen[$0]++')

status() {
  "${pipeline[@]}" python -c '
import os, sys, psycopg
from onboarding.neighbours import country_status
with psycopg.connect(os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")) as conn:
    print(country_status(conn, sys.argv[1]) or "none")' "$1"
}

declare -A state
for c in "${codes[@]}"; do state[$c]="$(status "$c")"; done

to_plan=() to_repair=() to_finish=()
for c in "${codes[@]}"; do
  case "${state[$c]}" in
    live) say "$c is live already; nothing to do" ;;
    seeded) to_repair+=("$c") ;;
    *) to_plan+=("$c") ;;
  esac
done

if ((${#to_plan[@]})); then
  for c in "${to_plan[@]}"; do
    say "plan $c"
    "${pipeline[@]}" python -m onboarding.plan "$c" "${release[@]}" "${plan_args[@]}" || die "the plan for $c stopped; fix it (the reason is above) and run again"
  done
  if ((!yes)); then
    read -r -p $'\nOnboard '"${to_plan[*]}"' as planned above? [y/N] ' answer
    [[ $answer =~ ^[Yy] ]] || die 'stopped; the plans are written, nothing else changed'
  fi
  for c in "${to_plan[@]}"; do
    say "apply $c"
    "${worker[@]}" php bin/console app:country:apply "$c" || die "apply $c failed"
    to_repair+=("$c")
  done
fi

if ((${#to_repair[@]})); then
  say "repair ${to_repair[*]}"
  rc=0; "${pipeline[@]}" python -m onboarding.repair "${to_repair[@]}" || rc=$?
  ((rc == 2)) && die 'another coverage run holds its lock; run the same command again once it has finished'
  ((rc == 0)) || die "repair failed (exit $rc)"
  to_finish=("${to_repair[@]}")
fi

for c in "${to_finish[@]}"; do
  say "elevation check $c"
  "${worker[@]}" php bin/console app:country:check-elevation "$c" \
    || die "the elevation check for $c failed; see wiki/developers/data-ops/elevation-tiles.md, then run the same command again"
  say "mark live $c"
  "${worker[@]}" php bin/console app:country:mark-live "$c" || die "mark live $c failed"
done

say "done: ${codes[*]}"
