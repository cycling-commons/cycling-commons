<!-- SPDX-License-Identifier: AGPL-3.0-only -->

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

The page has two halves and they are governed differently. Everything
installable (packages, runtimes, images, fonts, vendored libraries) is covered
by the marker contract and the gate in §3 to §7 of this document, and needs no
further mechanism. The dataset half is generated from the provider registry
(`App\Provider`). See §8.

## 2. The three tiers of credit

The page mixes three different things, and confusing them has already caused
one near-miss (a mandated Copernicus notice was twice nearly reworded as if it
were courtesy copy). They are visually distinguished:

| Tier | Marked by | May it be reworded? | Example |
|------|-----------|---------------------|---------|
| **Required notice** | a `*` after the name, linking to `#credits-req` | **No.** The wording is the upstream's, not ours. | Copernicus DEM, OpenStreetMap, Overture |
| **Thank-you row** | a row with its own sentence, no `*` | Yes | Geofabrik, MapLibre, Symfony |
| **Dependency list** | one entry in the `.pkglist` run | Names only, no prose to reword | PHPStan, Ubuntu, boto3 |

The `*` is deliberately quiet: a loud badge on most rows would shout an
in-house reminder at readers who have no use for it. The footnote it links to
sits at the foot of
the page, not in the hero, so the page opens on credits rather than on
housekeeping. Screen readers get the wording from a visually hidden span,
because a bare `*` tells them nothing.

**Adding a required notice is a one-way door.** A row carrying `*` may be
reworded only by going back to the upstream licence text. Removing the `*` is a
licence decision, not a copy decision.

The tier is placed by hand on the hand-written rows. On the generated dataset
rows it comes from the registry: the row's licence code
(`LicenceObligation::requiresAttribution()`) or its `promoted` flag (§8.4.1),
which is the right place for it:
the person admitting the provider at `/moderate/providers` is the person
reading its licence. The three tiers themselves do not change (§8).

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

Every dataset credit is a generated row (§8) and carries
`data-pkg="manual:provider-<key>"`, `<key>` being the row's `provider_key`. In
the template that marker is a Twig expression, which `check_credits.py`
skips; on the rendered page it is a `manual:` marker. Either way the whole data
section sits outside the gate's comparison.

## 4. The inventory: where "reality" is read from

`tools/credits/check_credits.py` builds the set of things that must be credited
from five places. All five are already sources of truth for something else, so
none of them is a second list that can drift.

| Source | Ids it yields | Notes |
|--------|---------------|-------|
| `web/composer.json` | `require` + `require-dev` keys | includes `php` and every `ext-*` |
| `pipeline/requirements.txt`, `developers/docker/wiki/requirements.txt` | distribution names | version pins and extras stripped: `uvicorn[standard]==0.34.0` yields `uvicorn` |
| `developers/docker/**` compose `image:` and Dockerfile `FROM` | image name without tag | `nginx:alpine` yields `nginx`; `ghcr.io/valhalla/valhalla:latest` yields `valhalla` |
| `web/assets/lib/*.js`, `*.css` | filename with the version stripped | `pmtiles-4.4.1.js` yields `pmtiles` |
| `web/public/lib/<name>/<version>/` | the package directory | `maplibre-gl/6.8.0/maplibre-gl.mjs` yields `maplibre-gl`. A separate reader because the version is in the path here, not the filename: these are libraries whose files find each other by relative URL and so cannot be digested (security-architecture.md §2.5). The first-party exemption still applies, and still fails closed: a package counts as ours only when **every** file in it carries the header |

### 4.1 First-party vendored code is exempt, by its own header

`web/assets/lib/` holds both third-party libraries and code the project owns.
`scout-fit.js` is one example: MIT, copyright BikeCoders, vendored verbatim
from the Scout repository so that two implementations of the FIT binary format
cannot drift into disagreeing about somebody's ride.

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
| staged `composer.json`, any `requirements.txt`, `developers/docker/**`, `web/assets/lib/**` | **warn only** | Holding a dependency bump hostage to a copy page is backwards. The predictable result is `--no-verify`, which kills the gate's credibility. The commit lands and the developer is told what just went uncredited, while they still have the context to fix it. |
| `make credits-check`, `ci-credits.yml` | **hard fail** | The page therefore cannot *ship* stale, it just cannot block unrelated work. |
| `--links`, `ci-credits.yml` only | **advisory** | Needs network. An upstream site being down for an hour is not a reason to fail somebody's pull request. |

`ci-credits.yml` carries **no `paths:` filter**, deliberately. The gate's inputs
are spread across `web/composer.json`, two `requirements.txt` files,
`developers/docker/**`, `web/assets/lib/**` and `web/public/lib/**`; writing
that set into a workflow filter would be a second copy of the list in the
script, and it would drift the same way. Add a third `requirements.txt` and the
filter silently stops covering
it while the job keeps going green, which is worse than no job at all. The
offline pass takes well under a second, so there is nothing to optimise. Same
reasoning as `warn-wiki-code-drift` being `always_run`.

### 5.2 Link rot needs a clock, not a trigger

A credited `uvicorn.org` once stopped resolving with nothing in the repository
having changed. The lesson is not "add a link check". It is that **no commit
causes link rot**,
so no change-triggered job can ever catch it. Nothing in this repository
changes when an upstream project moves its domain. `ci-credits.yml` therefore
runs `--links` on a weekly schedule (`cron: '17 6 * * 1'`, UTC) as well as on
pull requests, and the Monday run is the one that matters.

GitHub runs `schedule:` triggers from the **default branch only**, which is
`main`, where `ci-credits.yml` lives. Nothing needs installing on a host or
in a developer's shell: GitHub schedules it, not us. Two GitHub behaviours
worth knowing: schedule times are UTC and are best-effort rather than exact,
and GitHub disables scheduled workflows automatically in a repository with 60
days of no activity.

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

## 8. Dataset rows are generated from the provider registry

Built from `App\Provider` (`web/src/Provider/`),
`App\Twig\ProviderCreditsExtension`, and a loop over `credited_providers()` in
`credits.html.twig`. This section is the credits-page half of a contract owned
by [data-provider-hierarchy.md](data-provider-hierarchy.md) §9 and §9.2,
recorded here so the two documents cannot drift.

The page's software half does not use the registry. Packages, runtimes,
container images, fonts and vendored libraries are covered by the marker
contract (§3) and the gate (§5).

For the dataset half the template calls one Twig function and loops:

```
credited_providers()      App\Twig\ProviderCreditsExtension
```

It returns every credited `data_provider` row
(`ProviderCitations::creditedSql()`: serving, or paused with rows still on the
map, the same answer the map's citations give), ordered by `name` and split
into a `required` and a `courtesy` list (§8.4), each carrying `key`, `name`,
`homepage`, `licence`, `attribution`, `creator` and the already-resolved
sentence (§8.6). Every generated link carries
`data-pkg="manual:provider-<key>"` (§3).

**Seeded providers keep their wording.** Each seeded provider carries a
`blurb_key` pointing at the message key its hand-written row used, so
`credits.osm_p`, `credits.overture_p` and the rest keep all five
translations. The row moved into the registry; the words did not change.

### 8.1 The contract, in both directions

| Direction | Rule |
|-----------|------|
| **Registry to page** | A provider admitted at `/moderate/providers` appears on `/credits` **with nobody editing a template**. Not a follow-up task, not a checklist item. If a curator has to open a Twig file, this has not landed. |
| **Page to registry** | A hand-written row for a dataset the registry also carries is a **duplicate and must be deleted** in the same change. Two rows for OpenStreetMap is the failure this replaces, not an acceptable transition state. |

### 8.2 What a generated row carries, and what it keeps

- **Its marker** is `manual:provider-<key>` (§3). There are no hand-written
  dataset markers. `manual:` never orphans, so the gate cannot notice a
  dataset marker that should not be there; §8.1 and the registry carry that
  guarantee instead.
- **Its required-notice `*`** is not hand-placed: it comes from the registry
  (§2, §8.4.1). The wording of a required notice still may not be edited; it
  lives in the registry's `attribution` field, and the same one-way-door rule
  applies to it there.
- **Its licence chip is untranslated, deliberately.** The registry supplies
  the licence label as a fact, and a licence name is not prose, so the chip
  on `/fr/credits` reads "Open data" rather than "Données ouvertes".
- **Its sentence stays translated.** A generated row does not mean
  untranslated prose: `blurb_key` keeps `credits.osm_p` and the rest in use
  (§8.6). Deleting those keys would be a regression, not a cleanup.

### 8.3 The generated rows are their own group

Owner's call, 2026-08-27. `credited_providers()` renders into its **own
`.cgroup`**, not interleaved with hand-written rows, ordered by name. The
registry is expected to grow large: ten providers is a group of rows, a
hundred is a wall, and a wall of equal-weight rows is how a page stops being
read at all.

### 8.4 Two weights, and where the weight comes from

Owned by [data-provider-hierarchy.md](data-provider-hierarchy.md) §9.3.
"One `.crow` per entry" and "could grow to an enormous list" cannot both hold.

The software half of this page solves the same problem with the same split:
notable things get a row and a sentence, the long tail gets a linked name in
one comma-separated run (§2, the third tier). Forty-odd entries fit in a few
lines that way and stay readable.

For providers the split is not an editorial judgement about which are
interesting. It falls out of the licence:

| Provider | Rendering | Why it has to be this way |
|----------|-----------|---------------------------|
| **Required notice** | its own `.crow`, with `*` | A mandated attribution text must actually be displayed. A bare linked name in a comma run does not display it, so this is a licence obligation, not a design preference. |
| **Courtesy** | one linked name in a comma run | Nothing is owed beyond naming them. Completeness without prominence. |

The required set stays small by nature, so the page stays legible as the
registry grows into the hundreds. There is no prominence column: the weight
comes from the same licence code the curator desk already checks before it will
let a provider be enabled without an attribution.

#### 8.4.1 `promoted`: one bounded exception, default false

The licence sets the floor, not the ceiling, and the registry carries a
`promoted` boolean for the case where the floor is too low.

The worked example is the Dutch tap register. It is Public Domain Mark 1.0, so
it owes nothing and the licence rule correctly drops it into the comma run. But
its creator is a person the owner wants named, and a comma run cannot carry a
sentence. `promoted` moves that row up to a `.crow`.

What keeps this from becoming a prominence column by the back door, and what
this page therefore relies on:

- **Default false.** A provider is in the comma run unless somebody deliberately
  spends a promotion on it.
- **An explicit act on the desk**, recorded in `data_provider_change` like any
  other provider change.
- **The desk should show how many promoted rows exist**, so the count cannot
  creep unnoticed. Not built: `/moderate/providers` shows the checkbox per
  provider and no count.

A row is a `.crow` when the licence requires a notice **or** `promoted` is
true, and a comma-run entry otherwise.

As built, `ProviderCreditsExtension` puts a promoted row in the `required`
list, and every row in that list renders with the `*` required-notice marker.
A promoted public-domain row is therefore marked as carrying a required notice
although its licence owes none.
Open: whether a promoted row should get its `.crow` without the `*`.

#### 8.4.2 Publisher and creator are different people

`full_name` is who publishes. `creator` is who made the dataset, when that is
somebody else. They are separate registry fields because they are separately
true.

The Dutch taps are the case that proves it:

| Role | Who |
|------|-----|
| Creator | drinkwaterkaart.nl |
| Publisher | RIVM |
| Registry it is served from | the Kadaster's Nationaal Georegister |

A credit naming only the publisher credits the pipe rather than the person.

**Decided (owner, 2026-08-27): creator gets its own small line, in normal
case.** Not the `.lic` chip. That chip is mono and uppercased, and pushing
people into it produces
`PUBLIC DOMAIN MARK 1.0 · CREATED BY DRINKWATERKAART.NL · PUBLISHED BY RIVM`,
where the uppercasing mangles a domain name and the chip stops being scannable
as a licence. Creator and publisher are facts about people, not a licence
string, and they read as one.

The decided shape:

| Question | Answer |
|----------|--------|
| Where | inside `.who`, directly under the name, **above** the `.lic` chip |
| Why there | reading order is who made it, then under what terms. The chip stays the cell's machine-ish footer. |
| Case | normal, like `.crow p`. Not `text-transform: uppercase`. |
| When it renders | only when `creator` is set **and** differs from `full_name`. A dataset whose publisher made it needs no line: the name already said so. |
| Wording | a translation key with placeholders, e.g. `credits.provider_by: 'Created by %creator%, published by %publisher%'`. The two names are untranslated facts (§8.6); the words joining them are copy about us, so they translate like any other prose. |

Worked example, the row this whole field exists for:

```
Nationaal Georegister                          <- name
Created by drinkwaterkaart.nl, published by RIVM
PUBLIC DOMAIN MARK 1.0                         <- .lic chip
```

**What is built** is smaller than the decided shape. The template appends
`credits.made_by` (`'Dataset made by %creator%.'`) to the row's sentence,
inside its `<p>`, whenever `creator` is set; it does not compare `creator`
with `full_name` (the registry leaves `creator` NULL when the two are the same
body), and it names no publisher, because `credited_providers()` does not pass
`full_name` and the row's link text is `name`. Courtesy rows in the comma run
show no creator.
Open: whether to build the decided line (`credits.provider_by`, under the
name, above the chip) or keep `credits.made_by` in the sentence.

### 8.5 Curator-entered text is the provider module's problem, not this page's

Owner's call, 2026-08-27. Registry fields such as `attribution` and `blurb`
are curator-entered, which is an escaping surface on a public page.

`App\Provider` owns checking that on the way in: `ProviderRegistry` trims each
field and refuses to enable a provider whose licence requires an attribution
while the attribution is empty. Entry is restricted to curators
(`ROLE_CURATOR` on `/moderate/providers`), so this is trusted-but-checked input
rather than public input. The credits template prints the strings with Twig's
auto-escaping and adds no sanitiser of its own, so a link written into an
attribution shows as text. Allowing markup there would be a
[security-architecture.md](security-architecture.md) decision, not a template
one.

### 8.6 A row is facts plus one sentence, and only the sentence is translated

Owned by [data-provider-hierarchy.md](data-provider-hierarchy.md) §9.2 and
summarised here because it decides what this page renders.

**Facts come from the registry and are never translated:** `name`, `full_name`
(publisher), `creator`, `homepage`, licence code and label, `attribution`.
The attribution line
especially must never be translated, because it is the exact wording a licence
obliges us to show, which is the same one-way-door rule as §2.

**The sentence stays translated,** because it is copy about us, not about them.
Two columns carry it and the renderer prefers the first:

| Column | Holds | Used by |
|--------|-------|---------|
| `blurb_key` | a message key, e.g. `credits.osm_p` | seeded rows, which therefore keep every existing translation |
| `blurb` | free English text | a provider a curator adds at `/moderate/providers` |

A curator's `blurb` shows in English in every locale. The design for
translating it: register it as a `translation_entry` under a synthetic key
`provider.<key>.blurb`, translated the way everything else on the site is.
That fits the table's own rule that identity is the message key and never the
display wording ([translations.md](translations.md) §3.1), and needs one
change: the sync that projects `messages.en.yaml` into `translation_entry`
also projects registry blurbs. Overlays resolve by key and do not care where
the English came from. Not built: the sync does not read the registry.

`credited_providers()` therefore returns facts as values plus an
already-resolved sentence. **The template renders and does not choose.**

### 8.7 The gate and the generated rows: one blind spot, one check worth writing

The gate reads the template. Generated rows are invisible to it, so the
dataset half of the page is outside the gate's coverage entirely.
That is acceptable only because a different guarantee replaces it: the registry
is the single source, so a dataset cannot be *uncredited* the way a package
can.

One check is worth writing:

> **DUPLICATE**: a hand-written `.crow` whose link host matches the `homepage`
> of a provider returned by `credited_providers()`.

That is §8.1's page-to-registry rule made mechanical: any curator can add a
source the page still credits by hand, and the page then names it twice.
Open: not written; `tools/credits/check_credits.py` has no DUPLICATE check.

### 8.8 The line: scheduled ingest is a provider, on demand is not

Owner's call, 2026-08-27, and it settles the Mapillary and Esri question by
replacing a taste judgement with a mechanical one.

> A **provider** is a dataset this project imports, from a local file or an
> online API, on a schedule: once a week, a month, or a year. Something fetched
> **on demand**, per request, is not a provider and stays hand-written.

The rule does not depend on what kind of thing something is, only on how it
reaches us. It also matches what the registry is *for*: rank
resolution, duplicate guards, coverage suppression and citation are all
questions about rows we hold. There is nothing to rank about an image tile
fetched while the reader is looking at it.

Applied to the page:

| Row | How it reaches us | Verdict |
|-----|-------------------|---------|
| OpenStreetMap | Geofabrik extracts, harvested | provider |
| Overture Maps | pinned release, exported | provider |
| Géoportail de la Wallonie · PIVOT | harvested | provider |
| Geofabrik | harvested | provider |
| Wikimedia Commons | resolved and cached at harvest | provider |
| Wikipedia | fetched at build time | provider |
| Copernicus WorldDEM-30 | DEM tiles installed on the host | provider |
| RIVM drinking-water taps (via the Nationaal Georegister) | scheduled import | provider |
| **Mapillary** | live API, per request | **hand-written** |
| **Esri World Imagery** | live tiles, per request | **hand-written** |
| OpenFreeMap | live tiles, per request | hand-written |
| Photon | live geocoding, per request | hand-written |
| Valhalla, MapLibre, Protomaps, Redoc | software we run or ship | hand-written (§3, gated) |

So the map group keeps its two required notices, hand-placed:
`manual:mapillary-service` and `manual:esri-imagery` are valid markers, and the
`#credits-req` footnote is needed by rows outside the generated group.
