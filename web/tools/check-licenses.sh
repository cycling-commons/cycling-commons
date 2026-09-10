#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
# Fails if an installed Composer dependency carries a licence that cannot be
# combined with this project's own AGPL-3.0-only code.
#
# Copyleft is NOT what this looks for, so read this before "fixing" it. The
# platform's own licence is copyleft, so GPL, LGPL, MPL and AGPL dependencies
# all combine with it and all pass. What breaks an AGPL project is the opposite
# case: a dependency whose terms the AGPL cannot absorb.
#
# Rejected, and why each one:
#   GPL-2.0-only    no "or later" clause, so it cannot be taken up to v3.
#                   Bare "GPL-2.0" is the deprecated spelling of the same
#                   thing and is rejected with it. "GPL-2.0+" is NOT: it is
#                   the deprecated spelling of GPL-2.0-or-later, which goes
#                   up to v3 happily and passes
#   CDDL-*          file-level copyleft the FSF holds GPL-incompatible
#   EPL-*           patent and choice-of-law terms the GPL family disallows
#   OSL-*           its own external-deployment clause collides with AGPL §13
#   SSPL-*          not an open-source licence; its service-source demand is
#                   broader than the AGPL's and cannot be satisfied together
#   BUSL-*          time-delayed source-available, not free software today
#   Commons-Clause  a sale restriction bolted onto another licence
#   proprietary     no redistribution right at all
set -euo pipefail
cd "$(dirname "$0")/.."

json=$(composer licenses --no-dev --format=json 2>/dev/null || true)
if [ -z "$json" ]; then
  echo "check-licenses: 'composer licenses' returned nothing. Run composer install first." >&2
  exit 1
fi

# Each pattern is anchored on both quotes so a prefix cannot match by accident:
# "LGPL-2.0-only" must not trip the GPL-2.0-only rule, and "GPL-2.0-or-later"
# is compatible and must not trip it either.
bad=$(printf '%s' "$json" | grep -oE '"(GPL-2\.0|GPL-2\.0-only|CDDL[^"]*|EPL[^"]*|OSL[^"]*|SSPL[^"]*|BUSL[^"]*|Commons-Clause|proprietary)"' | sort -u || true)

if [ -n "$bad" ]; then
  echo "Dependency licence(s) incompatible with AGPL-3.0-only. Review before shipping:"
  printf '%s\n' "$bad"
  exit 1
fi

echo "licences OK (nothing incompatible with AGPL-3.0-only)"
