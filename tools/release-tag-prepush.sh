#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# A push to production is a release, and a release carries a version tag.
#
# The footer's version stamp is `git describe --tags --match 'v*'`
# (BuildVersion), and the changelog's releases are named after the tags
# (ReleaseNotes::RELEASES, docs/specs/roadmap-and-changelog.md §3). An untagged
# production push ships as "v0.9.1-beta-12-g0f480ca", and a tag added later
# only shows from the next deploy on (owner 2026-09-29: "forgot to tag the
# release, can we have something reminding me before push").
#
# Only a push to `production` is stopped. main and staging are daily work.
# To push without a tag anyway, once: SKIP=release-tag git push ...
set -euo pipefail

BRANCH="${PRE_COMMIT_REMOTE_BRANCH:-}"
TO="${PRE_COMMIT_TO_REF:-}"
[ "$BRANCH" = "refs/heads/production" ] || exit 0
[ -n "$TO" ] || exit 0
# Deleting the branch pushes an all-zero commit: nothing to tag.
if printf '%s' "$TO" | grep -qE '^0+$'; then exit 0; fi

tag="$(git tag --points-at "$TO" --list 'v*' | head -1)"
if [ -n "$tag" ]; then
  echo "release tag: $tag on $(git rev-parse --short "$TO")"
  exit 0
fi

last="$(git describe --tags --match 'v*' --abbrev=0 "$TO" 2>/dev/null || echo 'none')"
short="$(git rev-parse --short "$TO")"
subject="$(git log -1 --format=%s "$TO")"
cat >&2 <<MSG

This push deploys production, and $short carries no release tag.
  $short $subject
  last release tag before it: $last

Tag it, then push the tag with the branch:
  git tag -a v<version> -m "<version>" $short
  git push --follow-tags origin production

Add the release to ReleaseNotes::RELEASES too (version = the tag without its v),
or the changelog and the footer disagree (roadmap-and-changelog.md §3).

To push without a tag anyway, once:
  SKIP=release-tag git push origin production

MSG
exit 1
