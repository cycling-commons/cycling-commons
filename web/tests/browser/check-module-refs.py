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
    # Template literals are NOT inert text in this codebase: nearly every panel
    # builds its HTML as `...${escPend(x)}...`, and those ${} interiors are real
    # code. Blanking the whole literal hid a live `escPend is not defined` in
    # lightbox.js. Keep the interiors, drop only the literal text around them.
    def _tpl(m):
        return ';'.join(re.findall(r'\$\{([^{}]*)\}', m.group(0)))
    src = re.sub(r'`(?:\\.|[^`\\])*`', _tpl, src)
    src = re.sub(r"'(?:\\.|[^'\\\n])*'", "''", src)
    src = re.sub(r'"(?:\\.|[^"\\\n])*"', '""', src)
    return src

def read(name):
    return io.open(os.path.join(ROOT, name), encoding='utf-8').read()


# ---- Arm 0: every imported name must actually be EXPORTED by its module ----
# The split retires deps by deleting an initX() and letting the importer take a
# real import instead, and it is trivially easy to delete the export while a
# caller still imports it. The browser answers that with
# "SyntaxError: The requested module './x.js' does not provide an export named
# 'y'", which kills the whole module graph — every checkpoint after it fails, so
# the smoke sweep DOES catch it, but only after a full browser round trip, and
# only if the sweep is run. It is a static question, so ask it statically.
def exports_of(src):
    names = set()
    for m in re.finditer(r'^export\s+(?:async\s+)?(?:function|class|const|let|var)\s+([A-Za-z_$][\w$]*)', src, re.M):
        names.add(m.group(1))
    # `export const a = 1, b = 2;` and `export let x, y;`
    for m in re.finditer(r'^export\s+(?:const|let|var)\s+([^;\n=]+(?:=[^;\n]*)?)', src, re.M):
        for part in m.group(1).split(','):
            n = part.split('=')[0].strip()
            if n.isidentifier():
                names.add(n)
    for m in re.finditer(r'^export\s*\{([^}]*)\}', src, re.M):
        names |= {x.strip().split(' as ')[-1].strip() for x in m.group(1).split(',') if x.strip()}
    return names


missing = {}
for f in sorted(os.listdir(ROOT)):
    if not f.endswith('.js') or f in (SKIP - {'map.js'}):
        continue
    src = read(f)
    for m in re.finditer(r'import\s*\{([^}]*)\}\s*from\s*[\'"]\./([\w.-]+)[\'"]', src):
        want = {x.strip().split(' as ')[0].strip() for x in m.group(1).split(',') if x.strip()}
        target = m.group(2)
        if not os.path.exists(os.path.join(ROOT, target)):
            missing.setdefault(f, []).append('%s (no such module)' % target)
            continue
        have = exports_of(read(target))
        for n in sorted(want - have):
            missing.setdefault(f, []).append('%s <- %s' % (n, target))

for f, names in missing.items():
    print('%s imports names its module does not export -> %s' % (f, ', '.join(names)))
if missing:
    print('FAIL')
    sys.exit(1)

entry = strip(read('map.js'))
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
