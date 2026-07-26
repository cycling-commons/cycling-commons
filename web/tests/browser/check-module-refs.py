#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Flag any map module that still references a binding the ENTRY owns.

The split's characteristic failure is a moved function that calls something left
behind: ES modules turn that into a runtime ReferenceError on a path the smoke
sweep's assertions may not walk, so it shows up only as a console error. This
catches it statically, before the browser does.

Run from the repo root: `make map-refs` (or `python3 web/tests/browser/check-module-refs.py`).
Written for 2026-07-26-map-js-module-split-design.md §6, after the coverage.js
extraction shipped exactly this bug past a fully green sweep.
"""
import io, re, os, sys
ROOT = 'web/assets/map'
SKIP = {'map.js', 'scope.js', 'scope-chips.js', 'scope-header.js', 'catalog-load.js'}

def strip(src):
    """Comments and string bodies out, so prose and data never look like code.

    Order matters, and getting it wrong is silent: template literals are the only
    strings that span lines, and they routinely CONTAIN apostrophes. Stripping
    'single quotes' first therefore pairs an apostrophe inside a template with
    some unrelated quote thousands of characters away and eats every declaration
    in between — which is exactly how the first version of this checker reported
    "clean" while a live ReferenceError sat in the console. Templates first,
    then the line-local quotes, and never across a newline."""
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    src = '\n'.join(re.sub(r'//.*', '', l) for l in src.split('\n'))
    src = re.sub(r'`(?:\\.|[^`\\])*`', '``', src)
    src = re.sub(r"'(?:\\.|[^'\\\n])*'", "''", src)
    src = re.sub(r'"(?:\\.|[^"\\\n])*"', '""', src)
    return src

entry = strip(io.open(os.path.join(ROOT, 'map.js'), encoding='utf-8').read())
owned = set()
for m in re.finditer(r'^  (?:function|const|let|var)\s+([A-Za-z_$][\w$]*)', entry, re.M):
    owned.add(m.group(1))
for m in re.finditer(r'^  (?:const|let|var)\s+([\w$]+)\s*=[^;\n]*,\s*([\w$]+)\s*=', entry, re.M):
    owned |= {m.group(1), m.group(2)}
# Second class, invisible to the first: a moved function reaching for something
# the ENTRY only had through ITS OWN imports. `styleReady` shipped exactly this
# way — declared in map-init.js, imported by map.js, used by render.js, imported
# by nobody there. Not entry-OWNED, so the pass above cannot see it.
for m in re.finditer(r'import\s*\{([^}]*)\}', entry):
    owned |= {x.strip().split(' as ')[-1] for x in m.group(1).split(',') if x.strip()}

bad = {}
for f in sorted(os.listdir(ROOT)):
    if not f.endswith('.js') or f in SKIP:
        continue
    code = strip(io.open(os.path.join(ROOT, f), encoding='utf-8').read())
    local = set(re.findall(r'(?:function|const|let|var)\s+([A-Za-z_$][\w$]*)', code))
    # Multi-name declarations, with or without initialisers: `const S=2, D=24*S,
    # R=D/2;` declares three locals, and taking only the first left D looking
    # like a reference to the entry's D (the i18n dictionary).
    for m in re.finditer(r'(?:let|const|var)\s+([^;\n]+)', code):
        for part in m.group(1).split(','):
            n = part.split('=')[0].strip()
            if n.isidentifier():
                local.add(n)
    for m in re.finditer(r'(?:const|let|\()\s*\{([^}]*)\}\s*=', code):      # destructured deps
        local |= {x.strip().split(':')[-1].strip() for x in m.group(1).split(',') if x.strip()}
    for m in re.finditer(r'import\s*\{([^}]*)\}', code):
        local |= {x.strip().split(' as ')[-1] for x in m.group(1).split(',') if x.strip()}
    # Parameters shadow outer names: `export function setSpotlight(slug)` is not
    # a reference to util.js's slug().
    for m in re.finditer(r'function\s*[\w$]*\s*\(([^)]*)\)', code):
        local |= {a.strip().split('=')[0].strip() for a in m.group(1).split(',')
                  if a.strip() and a.strip().split('=')[0].strip().isidentifier()}
    # ...and a name is only a reference when it is NOT an object-literal key or a
    # label: `{D:'services'}` mentions D without reading anything.
    hits = sorted(n for n in owned - local
                  if re.search(r'(?<![\w$.])' + re.escape(n) + r'(?![\w$])(?!\s*:)', code))
    if hits:
        bad[f] = hits

for f, h in bad.items():
    print('%s -> %s' % (f, ', '.join(h)))
print('FAIL' if bad else 'clean: no module references an entry-owned binding')
sys.exit(1 if bad else 0)
