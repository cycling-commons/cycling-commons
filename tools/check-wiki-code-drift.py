#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Keep quoted code in the wiki honest against the code it quotes.

The GIS course teaches from real excerpts of this repo. That is the point —
a reader can open the file and see the thing being explained. It is also the
risk: the wiki is prose and nothing stops the code moving underneath it, so a
lesson can quietly become wrong while still reading perfectly.

This gate makes that failure loud. Every fenced block in `wiki/**/*.md` must
declare which of the two kinds it is, on the line before the fence:

    <!-- CODE-FROM web/src/Contribution/SpatialResolver.php -->
    ```sql
    SELECT id FROM region
    ```

        Quoted from the repo. Every significant line must still be findable,
        in order, in that file. If someone edits the source, this fails.

    <!-- CODE-ILLUSTRATIVE naive radius, not our code -->
    ```sql
    WHERE geom && ST_Expand(pt, 0.045)
    ```

        Hand-written for teaching — the naive version, a minimal GeoJSON, a
        shell command. Never checked, because there is nothing to check it
        against. The note after the keyword says what it is, and doubles as
        the reader-facing answer to "is this our code or an example?".

An unmarked fence fails: the point is that nobody can add a quote without
deciding which kind it is.

Matching is deliberately forgiving about layout and strict about content:
lines are whitespace-collapsed before comparison, so re-indenting a quote to
fit the page is fine, but changing an identifier is not. A line consisting of
an ellipsis (`...`, `…`, or those inside a comment) marks an elision and is
skipped, so a quote may omit the boring middle of a function.

Usage:  tools/check-wiki-code-drift.py [--list]
        --list  report every fence and its kind, then exit 0
"""
from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FENCE = re.compile(r"^\s*```")
FROM = re.compile(r"<!--\s*CODE-FROM\s+(\S+?)\s*-->")
ILLUSTRATIVE = re.compile(r"<!--\s*CODE-ILLUSTRATIVE\b([^>]*?)-->")
# an elision stands in for omitted lines; it is not content to verify
ELISION = re.compile(r"^[\s\-/#*]*(\.\.\.|…)[\s\-/#*]*$")


def norm(line: str) -> str:
    """Collapse whitespace so re-indenting a quote does not count as drift."""
    return " ".join(line.split())


def wiki_pages() -> list[Path]:
    out = subprocess.run(
        ["git", "ls-files", "wiki/*.md", "wiki/**/*.md"],
        cwd=ROOT, capture_output=True, text=True, check=True,
    ).stdout.split()
    return [ROOT / p for p in sorted(set(out))]


def fences(page: Path):
    """Yield (fence_line_no, marker_kind, marker_arg, body_lines)."""
    lines = page.read_text(encoding="utf-8").splitlines()
    i = 0
    while i < len(lines):
        if not FENCE.match(lines[i]):
            i += 1
            continue
        # look back over blank lines for the marker
        kind = arg = None
        j = i - 1
        while j >= 0 and not lines[j].strip():
            j -= 1
        if j >= 0:
            if m := FROM.search(lines[j]):
                kind, arg = "from", m.group(1)
            elif m := ILLUSTRATIVE.search(lines[j]):
                kind, arg = "illustrative", m.group(1).strip()
        body, k = [], i + 1
        while k < len(lines) and not FENCE.match(lines[k]):
            body.append(lines[k])
            k += 1
        yield i + 1, kind, arg, body
        i = k + 1


def check_quote(src: Path, body: list[str]) -> list[str]:
    """Return a list of quoted lines that are no longer present, in order."""
    haystack = [norm(l) for l in src.read_text(encoding="utf-8").splitlines()]
    missing, cursor = [], 0
    for raw in body:
        if not raw.strip() or ELISION.match(raw):
            continue
        needle = norm(raw)
        for idx in range(cursor, len(haystack)):
            if needle in haystack[idx]:
                cursor = idx + 1
                break
        else:
            missing.append(raw.strip())
    return missing


def main() -> int:
    listing = "--list" in sys.argv
    problems, counts = [], {"from": 0, "illustrative": 0}

    for page in wiki_pages():
        rel = page.relative_to(ROOT)
        for lineno, kind, arg, body in fences(page):
            if kind is None:
                problems.append(
                    f"{rel}:{lineno}: fenced block has no marker — add "
                    f"<!-- CODE-FROM <path> --> if it quotes the repo, or "
                    f"<!-- CODE-ILLUSTRATIVE <what it is> --> if it is a teaching example"
                )
                continue
            counts[kind] += 1
            if kind == "illustrative":
                if not arg:
                    problems.append(
                        f"{rel}:{lineno}: CODE-ILLUSTRATIVE needs a short note saying "
                        f"what the example is, so a reader knows it is not our code"
                    )
                if listing:
                    print(f"  illustrative  {rel}:{lineno}  ({arg})")
                continue

            src = ROOT / arg
            if not src.is_file():
                problems.append(f"{rel}:{lineno}: CODE-FROM points at a missing file: {arg}")
                continue
            if missing := check_quote(src, body):
                problems.append(
                    f"{rel}:{lineno}: quote no longer matches {arg} — "
                    f"{len(missing)} line(s) not found, first is:\n      {missing[0]}"
                )
            elif listing:
                print(f"  quoted        {rel}:{lineno}  <- {arg}")

    if listing:
        print(f"\n{counts['from']} quoted, {counts['illustrative']} illustrative")

    if problems:
        print("\nwiki code-drift check FAILED:\n", file=sys.stderr)
        for p in problems:
            print(f"  {p}", file=sys.stderr)
        print(
            "\nIf the code legitimately changed, update the wiki to match it — "
            "that is the whole point of this gate.",
            file=sys.stderr,
        )
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
