# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Turn repo-path citations in the wiki into links to the source on GitHub.

The GIS course cites files constantly — `web/src/Contribution/SpatialResolver.php`
— and tells the reader to go and open them. Making that a link is the obvious
courtesy, but hand-linking several hundred citations would be unmaintainable
and would rot the moment a file moved.

So it happens at build time instead. Any inline code span whose text is a path
that ACTUALLY EXISTS in the repo becomes a link to that file on GitHub, opening
in a new tab. Three properties fall out of doing it this way:

  - Nothing in the Markdown changes, so the source stays readable and the
    code-drift gate (which reads Markdown) is unaffected.
  - A path that does not exist is left as plain code rather than becoming a
    dead link — so this can never manufacture a 404, and a stale citation
    simply stops being clickable.
  - The links are in the HTML, so they work without JavaScript and are
    followable by anything that reads the page.

Fenced code blocks are skipped: a path inside a code sample is part of the
sample, not a citation.
"""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Only these trees are linkable. Anything else in backticks (a table name, a
# CSS class, a shell word) is left alone.
LINKABLE = ("web/", "pipeline/", "tools/", "docs/", "developers/", "atlas/", "wiki/", ".github/")

CODE_SPAN = re.compile(r"<code>([^<>]+)</code>")
PRE_BLOCK = re.compile(r"<pre\b.*?</pre>", re.S)
# a path, optionally with a #Lnn or :nn line reference we strip for the URL
PATH_RE = re.compile(r"^(?P<path>[A-Za-z0-9_.\-/]+\.[A-Za-z0-9]+)(?::(?P<line>\d+))?$")

_missing: set[str] = set()
_linked: set[str] = set()


def _url(base: str, branch: str, path: str, line: str | None) -> str:
    frag = f"#L{line}" if line else ""
    return f"{base.rstrip('/')}/blob/{branch}/{path}{frag}"


def _link_spans(html: str, base: str, branch: str) -> str:
    def repl(m: re.Match) -> str:
        text = m.group(1)
        # html-escaped content is not a path; bail early
        if "&" in text or " " in text:
            return m.group(0)
        pm = PATH_RE.match(text)
        if not pm:
            return m.group(0)
        path = pm.group("path")
        if not path.startswith(LINKABLE):
            return m.group(0)
        if not (ROOT / path).is_file():
            _missing.add(path)
            return m.group(0)
        _linked.add(path)
        href = _url(base, branch, path, pm.group("line"))
        return (
            f'<a class="cc-src" href="{href}" target="_blank" rel="noopener noreferrer" '
            f'title="Open {path} on GitHub">{m.group(0)}</a>'
        )

    return CODE_SPAN.sub(repl, html)


def on_page_content(html: str, page=None, config=None, files=None) -> str:
    repo = (config or {}).get("repo_url") or ""
    if not repo:
        return html
    branch = (config or {}).get("extra", {}).get("source_branch", "main")

    # Skip <pre> blocks: a path inside a code sample is part of the sample.
    out, last = [], 0
    for m in PRE_BLOCK.finditer(html):
        out.append(_link_spans(html[last:m.start()], repo, branch))
        out.append(m.group(0))
        last = m.end()
    out.append(_link_spans(html[last:], repo, branch))
    return "".join(out)


def on_post_build(config=None) -> None:
    if _linked:
        print(f"INFO    -  github-links: linked {len(_linked)} distinct source paths")
    if _missing:
        print(
            "WARNING -  github-links: left unlinked, path not found in the repo "
            f"({len(_missing)}): " + ", ".join(sorted(_missing)[:8])
        )
