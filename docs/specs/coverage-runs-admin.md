<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# What the harvest did last night: /admin/coverage-runs

Status: **built 2026-09-22** (`c9979adc5`). Option 1 of two, with the
dropped-by-rule detail, chosen by the owner.

## 1. Why

The coverage batch runs unattended on the worker host every night. Its output
goes to the journal and, once the checks are registered, to a healthchecks.io
ping. Neither answers, from the
admin backend, the questions an operator asks the morning after: did it run,
what took how long, which regions changed, and why did a region lose points.
The answers already exist in two tables the pipeline writes
(`coverage_run`, `coverage_run_step`, [tracker.py](../../pipeline/coverage/tracker.py));
this page reads them. Nothing new is measured.

## 2. What the page shows

`/admin/coverage-runs`, `ROLE_ADMIN`, read-only. Two views.

**The list**, newest first, one row per run:

| Column | Source |
|---|---|
| started | `coverage_run.started_at`, local time |
| tiles | `family` (`points`, `routes`, `surface`), then every family the run published inside itself: a nightly run reads `points · routes · surface` |
| trigger | `trigger` (`dispatcher`, `bootstrap`, `manual`) |
| status | `status`; a `running` row older than twelve hours reads **abandoned** (a killed run never finishes its row) |
| regions | `regions_loaded` of `regions_requested`; routes and surface runs load nothing, so only `regions_requested` |
| took | `finished_at - started_at` |
| rebuilt | per family: the run's own from `published_url`, and routes and surface from the `detail` of the night's `routes_publish` / `surface_publish` steps (comma-separated). "nothing changed" when a family ran and rebuilt no country, "failed" when its publish failed. A surface pass adds `gaps` when it republished the world gap grid. Without the steps a night that rebuilt all three read "points" alone (owner, 2026-09-29) |

A `--routes` / `--surface` / `--roadpieces` run opens its own row with one step,
`<family>_extract` (with `--extract-only`) or `<family>_publish`, whose
`detail` repeats the rebuilt countries. Inside the dispatcher both the
per-region `--extract-only` runs and the post-loop publish passes get the
night's `--run-id`, so their steps land under the night's run and open no
row; an extract that died before it could record its own step is recorded by
the dispatcher instead (`tracker.has_step`), so none goes missing. The pipeline writes these rows over two short connections, one
before and one after the build; a database that is down costs the history, not
the tiles.

**One run**, `/admin/coverage-runs/{id}`: the same header, then a table of its
steps grouped by region, in `started_at` order:

```
europe/france      download  412 s  4.2 GB
                   filter    301 s
                   parse      88 s
                   near_way  140 s
                   load      655 s  +31,204 rows, previous 1,402,118
                             dropped by rule: P needs a name 1,363 · Q needs a name 2,579 ·
                             Q memorial 826 · F bicycle=no 16 · near-way 69
europe/belgium     download    0 s  cached
                   ...
(run)              export     17 s
                   tippecanoe 211 s  455 MB
                   upload      5 s
```

A failed step shows its `detail` (the exception text) in place of the numbers.

## 3. The one pipeline change: dropped points, per rule, per region

Before this change `load_region` printed each rule's drop count and returned
`LoadResult(inserted, previous)`; the tracker wrote `detail = "previous N"` on
the load step and the drop counts were lost with the log.

- `LoadResult` gained `dropped: dict[str, int]`
  ([load.py](../../pipeline/coverage/load.py)), keyed by a short rule label:
  `name:P`, `name:Q`, `exclude:F:bicycle`, `exclude:Q:memorial`, `near_way`.
  The print lines stayed as they were.
- The load step's `detail` is JSON:
  `{"previous": 1402118, "dropped": {"name:P": 1363, "near_way": 69}}`.
  `CoverageRunController::readLoadDetail()` parses it; a `detail` that does not
  parse as JSON (rows from before this change) is shown as the plain text it is.
- Tests: `test_load.py` asserts the dict for a fixture with known drops;
  `test_run.py` asserts the load step's detail is the JSON form.

## 4. Where it lives in the app

- `App\Controller\Admin\CoverageRunController`: two actions, both plain
  Doctrine DBAL queries (the tables have no entity and never will; the
  pipeline owns their shape). Registered in the EasyAdmin menu from
  `DashboardController` in the `admin.menu.system` section.
- Two Twig templates under `templates/admin/coverage_runs/`, plus the shared
  `_format.html.twig` partial.
- Numbers are formatted in Twig (`number_format`, bytes to MB/GB, seconds to
  `12m03s`), nothing computed in the template beyond that.
- Tests: `tests/Admin/CoverageRunsTest.php`: an admin sees a seeded run with its
  steps and the dropped labels; anonymous is redirected to login; a curator
  gets 403; a `running` row older than twelve hours is labelled abandoned.
  The test seeds the two tables itself, since the test database never runs the
  pipeline.

## 5. Out of scope

Retention (a night writes about 120 rows; years fit), editing, and
re-running a region from the page. Each is a separate decision.
