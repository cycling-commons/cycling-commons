<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# The credits page and its freshness gate

**Status:** canonical reference · **implemented** · **Audience:** contributors to Cycling Commons

This document owns `/credits`: what the page promises, the three tiers of
credit it carries, and the machine gate that stops it drifting away from the
dependencies it claims to describe. Which upstream datasets are admitted at all
stays in [data-source-register.md](data-source-register.md). How an admitted
dataset is stored and ranked stays in
[data-provider-hierarchy.md](data-provider-hierarchy.md). The five-locale
parity rule the page's prose obeys stays in [translations.md](translations.md).
The repository's own outbound licence stays in the README.

---

## 1. What the page promises

`/credits` makes one claim, and the gate exists to keep it true:

> Everything the Cycling Commons is built on is named here.

Not "the interesting parts", not "the big ones". A reader who wants to know
what runs when they open the map should be able to finish this page and be
done. That is a stronger promise than most credits pages make, and a page that
quietly falls behind its own `composer.json` breaks it silently, which is the
worst way for it to break: it still reads perfectly.

The page is `web/templates/pages/credits.html.twig`. Project names, URLs and
licence identifiers live in the template; only prose is translated
([translations.md](translations.md) §1).

## 2. The three tiers of credit

The page mixes three different things, and confusing them has already caused
one near-miss (a mandated Copernicus notice was twice nearly reworded as if it
were courtesy copy). They are visually distinguished:

| Tier | Marked by | May it be reworded? | Example |
|------|-----------|---------------------|---------|
| **Required notice** | a `*` after the name, linking to `#credits-req` | **No.** The wording is the upstream's, not ours. | Copernicus DEM, OpenStreetMap, Overture |
| **Thank-you row** | a row with its own sentence, no `*` | Yes | Geofabrik, MapLibre, Symfony |
| **Dependency list** | one entry in the `.pkglist` run | Names only, no prose to reword | PHPStan, Ubuntu, boto3 |

The `*` is deliberately quiet. It was a filled `REQUIRED NOTICE` pill until
2026-08-27; on eight of fifteen rows a loud badge shouted an in-house reminder
at readers who have no use for it. The footnote it links to sits at the foot of
the page, not in the hero, so the page opens on credits rather than on
housekeeping. Screen readers get the wording from a visually hidden span,
because a bare `*` tells them nothing.

**Adding a required notice is a one-way door.** A row carrying `*` may be
reworded only by going back to the upstream licence text. Removing the `*` is a
licence decision, not a copy decision.

## 3. The marker contract

Every credit that corresponds to something installable declares what it covers,
in the template, next to the link:

```html
<a href="https://phpstan.org/"
   data-pkg="phpstan/phpstan phpstan/phpstan-doctrine">PHPStan</a>
```

`data-pkg` is a space-separated list. Three forms:

| Form | Meaning | Example |
|------|---------|---------|
| exact id | matches that one inventory id | `phpstan/phpstan` |
| prefix glob | matches every id under a prefix | `symfony/*` |
| `manual:<slug>` | asserted by a human, never matched against the inventory | `manual:production-host-os` |

The markers are attributes rather than Twig comments on purpose. They survive
into the rendered HTML, where they are inert; on an open-source project whose
`composer.json` is public anyway, showing a reader which package each credit
covers is a feature, not a leak. It also means the checker needs no Twig
parser.

Overlap is legal and expected. `php ext-*` on the PHP entry and `ext-imagick`
on the ImageMagick entry both match `ext-imagick`; an id is satisfied by *at
least one* marker, so the specific credit and the catch-all can coexist.

`manual:` is the escape hatch for real dependencies that are not written down
anywhere in this repository. There are three classes of them, and all three are
legitimate:

- **Host operating systems.** Ubuntu on the production hosts; Alpine and Debian
  underneath the container images. Nothing in the repository states these.
- **Data sources.** OpenStreetMap, Wikipedia, Copernicus. Datasets, not
  packages.
- **Services.** Mapillary and Esri as APIs, distinct from `mapillary-js` the
  library, which *is* discoverable and carries a real marker.

A `manual:` marker is a human's word. It is never an orphan and never a
missing entry, so it is also the way to defeat the gate. Reach for it only
when there is genuinely no file to point at.

## 4. The inventory: where "reality" is read from

`tools/credits/check_credits.py` builds the set of things that must be credited
from four places. All four are already sources of truth for something else, so
none of them is a second list that can drift.

| Source | Ids it yields | Notes |
|--------|---------------|-------|
| `web/composer.json` | `require` + `require-dev` keys | includes `php` and every `ext-*` |
| `pipeline/requirements.txt`, `tools/divisions/requirements.txt`, `developers/docker/wiki/requirements.txt` | distribution names | version pins and extras stripped: `uvicorn[standard]==0.34.0` yields `uvicorn` |
| `developers/docker/**` compose `image:` and Dockerfile `FROM` | image name without tag | `nginx:alpine` yields `nginx`; `ghcr.io/valhalla/valhalla:latest` yields `valhalla` |
| `web/assets/lib/*.js`, `*.css` | filename with the version stripped | `maplibre-gl-5.24.0.js` yields `maplibre-gl` |

### 4.1 First-party vendored code is exempt, by its own header

`web/assets/lib/` holds both third-party libraries and code the project owns.
`scout-fit.js` is the current example: MIT, copyright BikeCoders, vendored
verbatim from the Scout repository so that two implementations of the FIT
binary format cannot drift into disagreeing about somebody's ride.

The exemption is derived from the file, never from a list in the checker:

> A file in `web/assets/lib/` is first-party, and needs no credit, **only if**
> its first 20 lines carry an `SPDX-FileCopyrightText` line naming BikeCoders.

This fails closed. A vendored file with no header, or with somebody else's
header, must be credited. That is the important direction: if a future
contribution replaces `scout-fit.js` with a genuinely third-party FIT parser,
the BikeCoders header goes with it and the gate immediately demands a credit.
A hand-maintained allowlist would have gone on quietly exempting the filename.

## 5. The gate

One script, three modes.

| Mode | Reads | Cost | Fails on |
|------|-------|------|----------|
| default | files only | milliseconds | `MISSING`, `ORPHAN` |
| `--warn-only` | files only | milliseconds | nothing; prints findings, exits 0 |
| `--links` | the network | seconds | any credit URL that is not 2xx |

**`MISSING`** is an inventory id no marker covers. This is the failure that
matters: a dependency was added and nobody was told to credit it.

**`ORPHAN`** is a non-`manual:` marker matching nothing in the inventory. The
dependency is gone and its credit is now a lie about what the site runs.

### 5.1 When it fires

The trigger split is copied deliberately from the wiki drift gate, for the same
reason, recorded here so it is not "simplified" later:

| Trigger | Behaviour | Why |
|---------|-----------|-----|
| staged `credits.html.twig` | **hard fail** | You are editing the credits. They must be right. |
| staged `composer.json`, any `requirements.txt`, compose files, `web/assets/lib/**` | **warn only** | Holding a dependency bump hostage to a copy page is backwards. The predictable result is `--no-verify`, which kills the gate's credibility. The commit lands and the developer is told what just went uncredited, while they still have the context to fix it. |
| `make credits-check`, `ci-credits.yml` | **hard fail** | The page therefore cannot *ship* stale, it just cannot block unrelated work. |
| `--links`, `ci-credits.yml` only | **advisory** | Needs network. An upstream site being down for an hour is not a reason to fail somebody's pull request. |

`ci-credits.yml` carries **no `paths:` filter**, deliberately. The gate's inputs
are spread across `web/composer.json`, three `requirements.txt` files,
`developers/docker/**` and `web/assets/lib/**`; writing that set into a workflow
filter would be a second copy of the list in the script, and it would drift the
same way. Add a fourth `requirements.txt` and the filter silently stops covering
it while the job keeps going green, which is worse than no job at all. The
offline pass takes well under a second, so there is nothing to optimise. Same
reasoning as `warn-wiki-code-drift` being `always_run`.

### 5.2 Link rot needs a clock, not a trigger

`uvicorn.org` was credited on 2026-08-27 and did not resolve. The remaining 68
links were then checked by hand, all 2xx.

The lesson is not "add a link check". It is that **no commit causes link rot**,
so no change-triggered job can ever catch it. Nothing in this repository
changes when an upstream project moves its domain. `ci-credits.yml` therefore
runs `--links` on a weekly schedule (`cron: '17 6 * * 1'`) as well as on pull
requests, and the Monday run is the one that matters.

## 6. Adding a dependency: the playbook

1. Add it where it belongs (`composer.json`, a `requirements.txt`, compose,
   `web/assets/lib/`).
2. Commit. The warn-only hook tells you it is uncredited.
3. Add it to `/credits`. Small things go in the `.pkglist` run, alphabetical,
   case-insensitive. Something a rider would recognise, or that needs a
   sentence of explanation, earns its own row.
4. Give it a `data-pkg` naming the id from step 1.
5. If it is a *data* source rather than a package, it also belongs in
   [data-source-register.md](data-source-register.md), and it probably carries
   a required notice: check the upstream licence before writing the row.

## 7. What this gate deliberately does not check

Stated so nobody reads a green run as a broader guarantee than it is.

- **Transitive dependencies.** `composer.lock` has hundreds. The page credits
  what the project chose, not the whole graph. The `.pkglist` intro says
  "direct dependencies" for exactly this reason.
- **Licence identifiers.** That `sokil/php-isocodes` is MIT is asserted by a
  human. Nothing verifies the string against the package.
- **Whether a required notice is worded correctly.** The gate knows a row
  carries `*`. It cannot read the upstream licence.
- **The base OS under a container tag.** A switch from `nginx:alpine` to a
  Debian-based tag will not be noticed, because both yield the id `nginx`.
  Alpine and Debian are `manual:`.
- **Prose accuracy.** Whether a row's sentence still describes what the
  dependency does is a review question, not a machine one.
