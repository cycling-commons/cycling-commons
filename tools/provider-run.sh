#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# One provider refresh, end to end (docs/specs/data-provider-hierarchy.md §8).
#
#   tools/provider-run.sh rivm-drinkwater           dry run: the counts, nothing written
#   tools/provider-run.sh --write rivm-drinkwater   the same run, applied
#
# A refresh has two halves in two containers that share no filesystem. The
# pipeline fetches the provider's service and normalises it (Python,
# `providers.run`); the app matches the records against the catalogue and
# writes them (PHP, `app:providers:harvest`). The normalised file travels
# through this script: the fetch writes it to standard output, the script
# holds it in a private temporary directory, and the ingest reads it on
# standard input. Holding it rather than piping straight through keeps a
# failed fetch from ever reaching the ingest.
#
# Both halves refuse a paused provider. Nothing is cleared afterwards: every
# catalogue write moves its region's stamp by trigger (`catalog_change`,
# catalog-data-model.md §9.1), so the map's documents rebuild on their next
# request, and the ingest drops the provider citation cache itself.
#
# Exit status is 0 only when the ingest succeeded; anything else exits
# non-zero with a message on standard error.
set -euo pipefail

here="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
dev_compose="$(dirname "$here")/developers/docker/compose.yaml"

usage() {
  cat <<'EOF'
Usage: tools/provider-run.sh [options] [--write] <provider-key>

Fetches one provider in the pipeline, hands the records to the app and
ingests them. Dry unless --write.

Where the two halves run (default: the dev stack, developers/docker/compose.yaml,
services `pipeline` and `app`):
  --worker-dir DIR          a worker host's environment directory:
                            DIR/compose.pipeline.yaml, service `pipeline`, and
                            DIR/compose.compute.yaml, service `worker`
  --pipeline-compose FILE   compose file holding the pipeline service
  --pipeline-service NAME   the pipeline service (default: pipeline)
  --app-compose FILE        compose file holding the PHP service
  --app-service NAME        the PHP service (default: app)
  --app-user UID:GID        run the ingest as this user (default: the service's own)
Options after --worker-dir override the part they name.

A service that is up is used with `exec`; one that is not runs the command
once with `run --rm --no-deps`.
EOF
}

die() {
  printf 'provider-run: %s\n' "$*" >&2
  exit 1
}

pipeline_compose="$dev_compose"
pipeline_service=pipeline
app_compose="$dev_compose"
app_service=app
app_user=""
write=false
key=""

while (($#)); do
  case "$1" in
    --write) write=true ;;
    --worker-dir|--pipeline-compose|--pipeline-service|--app-compose|--app-service|--app-user)
      (($# >= 2)) || die "$1 needs a value (try --help)"
      case "$1" in
        --worker-dir)
          pipeline_compose="${2%/}/compose.pipeline.yaml"
          app_compose="${2%/}/compose.compute.yaml"
          app_service=worker
          ;;
        --pipeline-compose) pipeline_compose="$2" ;;
        --pipeline-service) pipeline_service="$2" ;;
        --app-compose) app_compose="$2" ;;
        --app-service) app_service="$2" ;;
        --app-user) app_user="$2" ;;
      esac
      shift
      ;;
    -h|--help) usage; exit 0 ;;
    -*) die "unknown option $1 (try --help)" ;;
    *)
      [[ -z $key ]] || die "one provider key per run, got \"$key\" and \"$1\""
      key="$1"
      ;;
  esac
  shift
done

[[ -n $key ]] || { usage >&2; die "which provider? Pass its key, e.g. rivm-drinkwater"; }
[[ -f $pipeline_compose ]] || die "no compose file at $pipeline_compose"
[[ -f $app_compose ]] || die "no compose file at $app_compose"
command -v docker >/dev/null || die "docker is not on PATH"

# in_service FILE SERVICE USER CMD...: exec into the service when it is up,
# otherwise run the command once in a fresh container of it.
in_service() {
  local file=$1 service=$2 user=$3
  shift 3
  local running
  running="$(docker compose -f "$file" ps --status running --services 2>/dev/null || true)"
  local -a how
  if grep -qxF -- "$service" <<<"$running"; then
    how=(exec -T)
  else
    how=(run --rm --no-deps -T)
  fi
  if [[ -n $user ]]; then
    how+=(--user "$user")
  fi
  docker compose -f "$file" "${how[@]}" "$service" "$@"
}

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
records="$work/records.json"

printf 'provider-run: fetching %s (%s, service %s)\n' "$key" "$pipeline_compose" "$pipeline_service" >&2
if ! in_service "$pipeline_compose" "$pipeline_service" "" \
    python -m providers.run --key "$key" --out - </dev/null >"$records"; then
  die "the fetch failed (see above); nothing was handed to the ingest"
fi
[[ -s $records ]] || die "the fetch exited cleanly but handed over nothing; nothing was ingested"

ingest=(php bin/console app:providers:harvest --no-interaction "$key" -)
if $write; then
  ingest+=(--write)
fi

printf 'provider-run: ingesting %s (%s, service %s, %s)\n' "$key" "$app_compose" "$app_service" "$($write && echo write || echo dry run)" >&2
if ! in_service "$app_compose" "$app_service" "$app_user" "${ingest[@]}" <"$records"; then
  die "the ingest failed (see above)"
fi
