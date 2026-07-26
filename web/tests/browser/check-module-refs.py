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

bad = {}
for f in sorted(os.listdir(ROOT)):
    if not f.endswith('.js') or f in SKIP:
        continue
    code = strip(io.open(os.path.join(ROOT, f), encoding='utf-8').read())
    local = set(re.findall(r'(?:function|const|let|var)\s+([A-Za-z_$][\w$]*)', code))
    for m in re.finditer(r'(?:let|const|var)\s+([\w$,\s]+?);', code):      # multi-name declarations
        local |= {n.strip() for n in m.group(1).split(',') if n.strip().isidentifier()}
    for m in re.finditer(r'(?:const|let|\()\s*\{([^}]*)\}\s*=', code):      # destructured deps
        local |= {x.strip().split(':')[-1].strip() for x in m.group(1).split(',') if x.strip()}
    for m in re.finditer(r'import\s*\{([^}]*)\}', code):
        local |= {x.strip().split(' as ')[-1] for x in m.group(1).split(',') if x.strip()}
    hits = sorted(n for n in owned - local
                  if re.search(r'(?<![\w$.])' + re.escape(n) + r'(?![\w$])', code))
    if hits:
        bad[f] = hits

for f, h in bad.items():
    print('%s -> %s' % (f, ', '.join(h)))
print('FAIL' if bad else 'clean: no module references an entry-owned binding')
sys.exit(1 if bad else 0)
