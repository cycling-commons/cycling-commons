# The scope-change freeze — diagnosis, and why no code changed

**Status:** **DIAGNOSED 2026-07-27, no code change.** Branch `symfony-base`.
**Tracks:** `docs/plans/github-issues-backlog.md` #10 ("a scope change blocks the
main thread for ~5.4 s"), which this supersedes as the record of what is known.
**Related:** [2026-07-26-map-js-module-split-design.md](2026-07-26-map-js-module-split-design.md) §9
(where the symptom was surfaced), [region-scoping-design.md](region-scoping-design.md)
(`applyScope`), [map-and-search.md](map-and-search.md).

The backlog entry named the next step precisely: *"record a Performance trace
across a scope change and read the Style/Layout/Paint bands, or simply count
`.cc-pin` nodes before and after… If it is marker churn, the fix is
reuse-and-move instead of destroy-and-recreate — NOT loop optimisation."*

That was done. **It is not marker churn.** And the environment the number was
measured in cannot answer the question the number was being used to ask.

---

## 1. What was measured

Four rounds, all on `/map` at 1440×900, each triggering one real scope change
through `CCScope.set` (so the `cc:scopechange` → `applyScope` path runs exactly
as a chip click does), with a `PerformanceObserver` on `longtask` plus, in
round 1, a full `devtools.timeline` CDP trace. Probes:
`.playwright-mcp/probe-scope-trace{,-2,-3}.js` (gitignored, like every other
probe — see the split design §9).

### Round 1 — the trace, and the marker census

| | before | after |
|---|---|---|
| `.cc-pin` nodes | 16 | 20 |
| `.maplibregl-marker` nodes | 21 | 44 |
| total DOM nodes | 414 | 453 |

Timeline bands over the whole scope change:

| band | total |
|---|---|
| style (`UpdateLayoutTree`, invalidations) | **7.3 ms** |
| layout (`Layout`, `PrePaint`) | **58.2 ms** |
| script (`FunctionCall`, `EvaluateScript`, GC) | 1,006 ms |
| **`Commit`** (8 events) | **11,032 ms** |
| **`GPUTask`** (169 events) | **11,060 ms** |

Style recalculation and layout together are **65 ms** of a ~7–11 s stall. A
teardown-and-rebuild of hundreds of markers would land in exactly those two
bands, and it is not there — because there is no such teardown: four extra pins
appeared. **Marker churn is refuted.**

The entire cost is `Commit` — the main-thread task that hands a frame to the
compositor and waits on it — and `GPUTask`.

### Round 2 — is that GPU cost real?

Three discriminators, same scope change each time:

| arm | long tasks | total |
|---|---|---|
| baseline (canvas 1100×900) | 342, 1324, 2931, 2986 | **7,583 ms** |
| GL surface shrunk to 120×90 | 12 tasks, none over 280 ms | **2,002 ms** |
| map removed entirely | — | **0 ms** |

And the renderer this browser actually uses:

    ANGLE (Google, Vulkan 1.3.0 (SwiftShader Device (Subzero)), SwiftShader driver)

**There is no GPU.** SwiftShader is a software rasteriser, so every "GPU" frame
is CPU work whose cost scales with pixel count — which is precisely the
1100×900 → 120×90 result. The headless flags carry
`--use-angle=swiftshader-webgl --enable-unsafe-swiftshader`.

This invalidates the absolute numbers, **including the original 5.4 s**: that
figure and the CPU profile behind it were recorded in this same headless
browser, and `(program)` at 95.4% is exactly where a software rasteriser's time
lands. The backlog's own reading — "native style/layout/paint" — was right about
*native* and wrong about *which*.

(The map-removed arm reads 0 ms, but it is the weakest of the three: with the
map gone some `applyScope` callees bail early, and one threw. Take the
canvas-size arm as the load-bearing result — identical code, identical data,
identical network, only pixels differ.)

### Round 3 — is anything in the frame worth fixing anyway?

A software rasteriser exaggerates fill cost but does not invent it, so two
candidates that would cost fill rate on a phone GPU too were tested by
re-running the identical change with only that one thing removed:

| arm | total | worst task |
|---|---|---|
| baseline | 10,966 ms | 2,696 ms |
| spotlight mask suppressed | 12,290 ms | 2,740 ms |
| `fitBounds` animation removed | 7,680 ms | 2,908 ms |
| both | 7,475 ms | 2,465 ms |

**Neither is supported.** Note the baseline moved from 7,583 ms (round 2) to
10,966 ms (round 3) with no change at all: run-to-run variance here is ±45%,
which is larger than any effect above. The worst single task stays 2.5–2.9 s in
every arm, including both "fixes".

### Round 4 — the question backlog #8 depends on

The ordering of this session's work assumed the freeze might be marker churn, in
which case defaulting the map to **Everything** (backlog #8) would make it worse
for every new visitor. Measured on a Wallonia → Flanders scope change, once in
each mode:

| mode | pins / DOM nodes before | after | long tasks |
|---|---|---|---|
| Curated | 20 / 453 | 0 / 388 | 8,953 ms |
| **Everything** | **21 / 456** | 0 / 388 | **7,504 ms** |

Everything mode costs **one extra pin and three extra DOM nodes**, and measured
*faster* (inside the ±45% variance, so: no difference). Both end at **zero
`.cc-pin` markers** after the change — Flanders has no curated dev data — so the
multi-second stall is reproducing with **no map markers on the page at all**.

That is the cleanest refutation in the set, and it clears #8: whatever this is,
it is not proportional to how many features are rendered as DOM.

(First attempt at this round selected the toggle by `data-m="everything"`. The
real values are `curated` / **`all`**, so both arms ran Curated and the
comparison was void. Re-run with the right selector; the numbers above are the
corrected ones.)

---

## 2. Conclusion

1. **Marker churn is refuted.** 7.3 ms of style and 58.2 ms of layout; markers go
   16 → 20; the stall reproduces with zero markers present. Do not implement
   reuse-and-move, and do not move point layers off DOM markers *for this
   reason* (there may be other reasons; this is not one).
2. **The cost is rasterisation of the MapLibre canvas**, and it scales with
   pixel count.
3. **This environment cannot size it.** It has no GPU, and its run-to-run
   variance (±45%) exceeds every effect tested. Every absolute figure in backlog
   #10, including the headline 5.4 s, is a software-rasteriser number.
4. **No code changed.** Three candidate fixes were tested against the evidence
   and none survived it. Shipping one anyway would be shipping against noise.

## 3. The next step, precisely

Re-measure **on real GPU hardware** before any optimisation:

- headed Chrome on the owner's machine (not this WSL headless profile), or the
  same headless run with `--use-gl=angle --use-angle=default` and a working GPU;
- same recipe: `PerformanceObserver` on `longtask` around one `CCScope.set`,
  five runs per arm to get past the variance;
- if the stall persists on hardware, the next discriminator is already written —
  the canvas-size arm in `probe-scope-trace-2.js` separates fill rate from
  everything else in one run.

If it does **not** reproduce on hardware, backlog #10 closes as an artefact of
the measurement environment, and the progressive spotlight paint already shipped
in `c548315` — which was measured properly, under CDP network throttling, and
does help — remains the one real win from this thread.

## 4. What this says about the harness

Recorded so it is not learned twice: **`.playwright-mcp` measurements of
rendering cost are not transferable.** The profile runs on SwiftShader, so any
number that includes compositing is a CPU-software-rasterisation number. This
sits alongside the two harness caveats the split design §9 already records (the
sweep's baseline is environment-dependent; the browser keeps one page across
probe runs, so a viewport sticks). Correctness probes are unaffected — only
timing is.
