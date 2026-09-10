#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
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

Three more rules, each added because the thing it checks broke while every
existing gate stayed green:

    SAMPLE-FROM   A CODE-ILLUSTRATIVE block that shows "sample output" must
                  also say where that output was captured, using one of
                  fresh-clone / any-install / author-install / network. The
                  courses promise reproducible exercises, and a count taken on
                  a machine holding two million harvested rows is a fine thing
                  to show and a terrible thing to leave looking like something
                  a fresh clone will print.

    make targets  Every `make <target>` a page tells the reader to run must
                  exist in the Makefile. Only inside a code span or a fence, so
                  "make sure" in prose is never a candidate. `course-data` was
                  renumbered out from under the GIS course in 2026-08 and
                  nothing noticed for two weeks, because a make recipe is not
                  quoted code and no gate read it.

    gis-todo      A figure that still carries its drawing brief renders that
                  brief to the reader. There were two; both are drawn, so any
                  gis-todo at all now fails.

Numbers in prose are the other thing this gate cannot see, and they are handled
next door: tools/wiki-numbers.py derives them from the repository into
wiki/developers/numbers.md, and pages cite that page rather than restating.

Matching is deliberately forgiving about layout and strict about content:
lines are whitespace-collapsed before comparison, so re-indenting a quote to
fit the page is fine, but changing an identifier is not. A line consisting of
an ellipsis (`...`, `…`, or those inside a comment) marks an elision and is
skipped, so a quote may omit the boring middle of a function.

Two ways to run it, because the right answer depends on who is committing:

    --strict     (default) drift is an error. Used when WIKI pages are staged:
                 you are editing the docs, so the docs must be right.

    --warn-only  drift is reported loudly and exits 0. Used when SOURCE files
                 are staged. Blocking a RideCheckService change because a wiki
                 page quotes it is backwards — it holds business code hostage
                 to documentation, and the predictable result is --no-verify,
                 which kills the gate's credibility altogether. So the code
                 change lands, and the developer is told exactly which pages
                 just went stale while they still have the context to fix them.
                 `make wiki-check` and CI still fail, so the wiki cannot ship
                 stale; it just cannot block unrelated work.

Usage:  tools/check-wiki-code-drift.py [--list] [--warn-only]
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

# Where a printed output was captured. A course that promises reproducible
# exercises has to say which outputs actually are: a count taken on a machine
# holding two million harvested rows is a fine thing to show and a terrible
# thing to leave looking like something a fresh clone will print.
SAMPLE_ORIGINS = ("fresh-clone", "any-install", "author-install", "network")
SAMPLE_FROM = re.compile(r"\bSAMPLE-FROM\s+(" + "|".join(SAMPLE_ORIGINS) + r")\b")
LOOKS_LIKE_OUTPUT = re.compile(r"\bsample output\b", re.I)

# A `make <target>` the reader is told to run. Only inside a code span or a
# fence, so ordinary prose ("make sure", "make it fast") is never a candidate.
MAKE_INLINE = re.compile(r"`make\s+([a-z][a-z0-9._-]*)")
MAKE_FENCED = re.compile(r"^\s*(?:\$\s*)?make\s+([a-z][a-z0-9._-]*)")
MAKE_RULE = re.compile(r"^([a-zA-Z0-9._/-]+)\s*:(?!=)")

# An unfinished figure renders its own drawing brief to the reader. There were
# two, F14 and F15; both are drawn, so the allowlist that used to hold them is
# gone and any gis-todo at all is now a failure.


def make_targets() -> set[str]:
    """Every rule name the root Makefile defines."""
    names = set()
    for line in (ROOT / "Makefile").read_text(encoding="utf-8").splitlines():
        if line.startswith("\t") or not line.strip() or line.lstrip().startswith("#"):
            continue
        if m := MAKE_RULE.match(line):
            names.update(m.group(1).split())
    names.discard(".PHONY")
    names.discard(".DEFAULT_GOAL")
    return names


def norm(line: str) -> str:
    """Collapse whitespace so re-indenting a quote does not count as drift."""
    return " ".join(line.split())


def wiki_pages() -> list[Path]:
    # `--others --exclude-standard` includes NEW pages that are not staged yet.
    # Without it a brand-new chapter was invisible to this check: `git ls-files`
    # alone lists only tracked files, so `make wiki-check` passed while an
    # unmarked or drifted fence sat in an untracked page. It happened to be
    # caught at commit time (pre-commit stages first, which makes the file
    # tracked), but the manual and CI-on-a-fresh-branch paths both had the hole.
    # Ignored files stay excluded, so build output under wiki-dist/ is not read.
    out = subprocess.run(
        ["git", "ls-files", "--cached", "--others", "--exclude-standard",
         "wiki/*.md", "wiki/**/*.md"],
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


def check_make_targets(page: Path, targets: set[str]) -> list[str]:
    """Every `make <target>` the page tells a reader to run must exist.

    Prose numbers are not the only ungated thing a page can get wrong: a make
    target is an instruction, and an instruction that fails is worse than a
    stale sentence. The course-data target was renumbered out from under the
    GIS course in 2026-08 and nothing noticed for two weeks, because no gate
    read the Makefile.
    """
    out, in_fence = [], False
    rel = page.relative_to(ROOT)
    for n, line in enumerate(page.read_text(encoding="utf-8").splitlines(), 1):
        if FENCE.match(line):
            in_fence = not in_fence
            continue
        found = MAKE_INLINE.findall(line)
        if in_fence:
            found += MAKE_FENCED.findall(line)
        for name in found:
            if name not in targets:
                out.append(
                    f"{rel}:{n}: tells the reader to run `make {name}`, which the "
                    f"Makefile does not define"
                )
    return out


def check_placeholder_figures(page: Path) -> list[str]:
    """A figure that still renders its drawing brief is not finished."""
    rel = str(page.relative_to(ROOT))
    out = []
    for n, line in enumerate(page.read_text(encoding="utf-8").splitlines(), 1):
        if "gis-todo" in line:
            out.append(
                f"{rel}:{n}: unfinished figure. A `gis-todo` block renders its own "
                f"drawing brief to the reader. Finish the figure, or remove the block"
            )
    return out


def main() -> int:
    listing = "--list" in sys.argv
    warn_only = "--warn-only" in sys.argv
    problems, counts = [], {"from": 0, "illustrative": 0}
    targets = make_targets()

    for page in wiki_pages():
        rel = page.relative_to(ROOT)
        problems.extend(check_make_targets(page, targets))
        problems.extend(check_placeholder_figures(page))
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
                elif LOOKS_LIKE_OUTPUT.search(arg) and not SAMPLE_FROM.search(arg):
                    problems.append(
                        f"{rel}:{lineno}: this block shows sample output but does not say where it "
                        f"was captured. Add SAMPLE-FROM <"
                        + "|".join(SAMPLE_ORIGINS) + "> to the marker: "
                        f"fresh-clone reproduces after `make setup` + `make course-data`, "
                        f"any-install holds at any data size, author-install needs a real harvest, "
                        f"network needs the internet"
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
        if warn_only:
            print(
                "\n⚠  This change makes the wiki stale — NOT blocking your commit.\n",
                file=sys.stderr,
            )
            for p in problems:
                print(f"  {p}", file=sys.stderr)
            print(
                "\nYour code change is fine and is being committed. The pages above now\n"
                "quote code that no longer exists. Fix them while you still have the\n"
                "context — `make wiki-check` and CI will fail until you do.",
                file=sys.stderr,
            )
            return 0

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
