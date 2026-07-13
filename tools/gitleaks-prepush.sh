#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
#
# pre-push secret net: scan the commits being pushed (only the OUTGOING range)
# with gitleaks before they leave the machine — belt-and-suspenders behind the
# pre-commit hook and GitHub Push Protection. Catches a secret in a commit that
# bypassed pre-commit (e.g. `--no-verify`, or committed before the hook was
# installed), with fast local feedback.
#
# Self-fetches a pinned gitleaks into a cache if one isn't already on PATH, so a
# contributor needs nothing installed but `pre-commit`.
set -euo pipefail

GL_VER="8.30.1"
REPO_ROOT="$(git rev-parse --show-toplevel)"

# Range to scan: pre-commit sets these env vars for pre-push-stage hooks.
FROM="${PRE_COMMIT_FROM_REF:-}"
TO="${PRE_COMMIT_TO_REF:-}"
[ -n "$TO" ] || TO="$(git rev-parse HEAD)"
if [ -z "$FROM" ] || printf '%s' "$FROM" | grep -qE '^0+$'; then
  LOGOPTS="$TO"                # branch not on the remote yet → scan all reachable commits
else
  LOGOPTS="${FROM}..${TO}"     # only the new commits being pushed
fi

# gitleaks binary: prefer one on PATH, else download the pinned release to a cache.
if command -v gitleaks >/dev/null 2>&1; then
  GL="gitleaks"
else
  os="$(uname -s | tr '[:upper:]' '[:lower:]')"      # linux | darwin
  case "$(uname -m)" in
    x86_64|amd64) arch="x64" ;;
    arm64|aarch64) arch="arm64" ;;
    *) arch="$(uname -m)" ;;
  esac
  cache="${XDG_CACHE_HOME:-$HOME/.cache}/cyclingcommons/gitleaks-${GL_VER}"
  GL="${cache}/gitleaks"
  if [ ! -x "$GL" ]; then
    mkdir -p "$cache"
    url="https://github.com/gitleaks/gitleaks/releases/download/v${GL_VER}/gitleaks_${GL_VER}_${os}_${arch}.tar.gz"
    echo "pre-push: fetching gitleaks ${GL_VER} (${os}/${arch}) once…" >&2
    curl -sSfL "$url" | tar -xz -C "$cache" gitleaks
    chmod +x "$GL"
  fi
fi

echo "pre-push: scanning ${LOGOPTS} for secrets…" >&2
exec "$GL" git "$REPO_ROOT" --redact --config "${REPO_ROOT}/.gitleaks.toml" --log-opts="${LOGOPTS}"
