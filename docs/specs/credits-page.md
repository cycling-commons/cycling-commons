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

The page has two halves and they are governed differently. Everything
installable (packages, runtimes, images, fonts, vendored libraries) is covered
by the marker contract and the gate in §3 to §7 of this document, and needs no
further mechanism. The dataset half is hand-written today and becomes
registry-generated when `App\Provider` lands. See §8.

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

The tier is placed by hand today. For generated dataset rows it will come from
the registry's own required-or-courtesy flag, which is the right place for it:
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

Every dataset credit is `manual:` today, which is why the whole data section
sits outside the gate's comparison. Those markers disappear with the rows they
sit on when §8 lands; nothing else about the contract changes, because a
generated row has no template to carry a marker in.

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
runs `--links` on a weekly schedule (`cron: '17 6 * * 1'`, UTC) as well as on
pull requests, and the Monday run is the one that matters.

**The weekly run is dormant until go-live, and this is not a bug to fix.**
GitHub runs `schedule:` triggers from the **default branch only**. The public
repository's default branch is `main`; this workflow lives on `symfony-base`,
which becomes `main` at go-live
([go-live plan](../TODO.md)). Until that swap the `pull_request` and `push`
triggers work normally and the cron simply never fires. Nothing needs
installing on a host or in a developer's shell to make it work afterwards:
GitHub schedules it, not us. Two GitHub behaviours worth knowing at that point:
schedule times are UTC and are best-effort rather than exact, and GitHub
disables scheduled workflows automatically in a repository with 60 days of no
activity.

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

## 8. When the provider registry lands, datasets stop being hand-written

**Not built yet.** `App\Provider` does not exist at the time of writing. This
section is the credits-page half of a contract owned by
[data-provider-hierarchy.md](data-provider-hierarchy.md) §9 and §9.2, recorded
here so the two documents cannot drift and so this page can be built against
before the registry exists.

The page's software half is finished and needs nothing from this. Packages,
runtimes, container images, fonts and vendored libraries are covered by the
marker contract (§3) and the gate (§5). Nothing below changes any of it.

The dataset half is what moves. Instead of a `.crow` per source written by hand
in the template, the template calls one Twig function and loops:

```
credited_providers()      App\Twig\ProviderCreditsExtension
```

It returns every enabled `data_provider` row that owes a credit, ordered for
display, each carrying `name`, `full_name`, `homepage`, `licence`,
`attribution`, and whether the credit is legally required or courtesy.

**"Keep the old data as is" means no wording changes, not no migration.** This
was misread once (2026-08-27) as "existing rows stay hand-written and the
registry only covers new sources", and the misreading is worth recording
because it is the natural one. What §9.2 actually guarantees is that seeded
providers carry a `blurb_key` pointing at the message key their row already
uses, so `credits.osm_p` and `credits.overture_p` keep all five translations
and `/fr/credits` reads exactly as it does today. The row moves; not one word
does. A `blurb_key` on `osm` would have nothing to do under an additive model,
which is the tell.

### 8.1 The contract, in both directions

| Direction | Rule |
|-----------|------|
| **Registry to page** | A provider admitted at `/moderate/providers` appears on `/credits` **with nobody editing a template**. Not a follow-up task, not a checklist item. If a curator has to open a Twig file, this has not landed. |
| **Page to registry** | A hand-written row for a dataset the registry also carries is a **duplicate and must be deleted** in the same change. Two rows for OpenStreetMap is the failure this replaces, not an acceptable transition state. |

### 8.2 What moves with the rows, and the one thing that does not

Deleting a hand-written dataset row is not just deleting markup:

- **Its `manual:` marker** goes with it. A generated row has no template to
  carry a marker in, so the ids in §3 for datasets (`manual:openstreetmap-data`,
  `manual:overture-divisions`, `manual:copernicus-dem` and the rest) simply
  cease to exist. `manual:` never orphans, so the gate stays green either way,
  which is exactly why this has to be done deliberately rather than left to a
  failing check.
- **Its required-notice `*`** stops being hand-placed and comes from the
  registry's flag (§2). The wording of a required notice still may not be
  edited; it now lives in the registry's `attribution` field, and the same
  one-way-door rule applies to it there.
- **Its licence chip changes language, deliberately.** The `lic_*` keys used
  only by data rows (`lic_open_data`, `lic_osm_extracts`, `lic_linked`,
  `lic_per_photo`) go orphan, because the registry supplies the licence label
  as an untranslated fact. A licence name is not prose. Accepted consequence,
  not an oversight: the chip on `/fr/credits` will read "Open data" rather than
  "Données ouvertes".
- **Its translation key stays exactly where it is.** The one thing that does
  *not* move, stated because the obvious assumption is wrong: a generated row
  does not mean untranslated prose. `blurb_key` keeps `credits.osm_p` and the
  rest alive and pointed at (§8.6). Deleting them would be a regression, not a
  cleanup, and [translations.md](translations.md) parity must still pass after
  the sweep.
- **The parked rows go too.** Drinkwaterkaart.nl and Nationaal Georegister
  currently sit inside Twig comments awaiting a decision. The owner's call
  (2026-08-27) is that both are simply deleted by hand at the switchover: the
  registry carries those sources afterwards, so there is nothing to unpark and
  no decision left to make.

### 8.3 The generated rows are their own group

Owner's call, 2026-08-27. `credited_providers()` renders into its **own
`.cgroup`**, not interleaved with whatever hand-written rows remain. Two
reasons, and the second is the one that shapes the markup:

- **Ordering stops being a problem.** The hand-written data rows are ordered
  editorially, not alphabetically: OpenStreetMap is first because its sentence
  opens "The foundation of the atlas". A registry sort order cannot express
  that, and does not have to once the two sets do not share a list.
- **The registry is expected to grow large.** Ten providers is a group of rows.
  A hundred is a wall, and a wall of equal-weight rows is how a page stops
  being read at all.

### 8.4 Two weights, and where the weight comes from

**Accepted and now owned by
[data-provider-hierarchy.md](data-provider-hierarchy.md) §9.3** (commit
`8ab9aa7a`). It was raised here because "one `.crow` per entry" and "could grow
to an enormous list" cannot both hold.

The software half of this page already solved this, and the split it uses is
not arbitrary: notable things get a row and a sentence, the long tail gets a
linked name in one comma-separated run (§2, the third tier). Forty-two entries
fit in five lines that way and stay completely readable.

For providers the split should not be an editorial judgement about which are
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
- **An explicit act on the desk**, recorded in moderation history like any other
  provider change.
- **The desk shows how many promoted rows exist**, so the count cannot creep
  unnoticed.

For the template this changes one line: a row is a `.crow` when the licence
requires a notice **or** `promoted` is true, and a comma-run entry otherwise.

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
That is exactly the mistake the current hand-written Nationaal Georegister row
makes, and why that row is hidden right now rather than merely stale.

**Open, and it is this page's call, not the registry's:** which slot renders
`creator`. The `.lic` chip is mono, uppercase and small, and already carries
the licence plus any mandated attribution. Pushing creator into it produces
`PUBLIC DOMAIN MARK 1.0 · CREATED BY DRINKWATERKAART.NL · PUBLISHED BY RIVM`,
where the uppercasing mangles a domain name and the chip stops being scannable.
The recommendation is a separate small line under the name, in normal case,
because creator and publisher are facts about people rather than a licence
string. Decide before the first promoted row ships, since the promoted rows are
precisely the ones that exist to carry it.

### 8.5 Curator-entered text is the provider module's problem, not this page's

Owner's call, 2026-08-27. Every string on `/credits` is developer-written
today. `attribution` and `full_name` become curator-entered, which is a new
escaping surface on a public page, and attribution strings frequently want a
link inside them.

`App\Provider` owns validating and sanitising that on the way in. Entry is
restricted to admins and curators, so this is trusted-but-checked input rather
than public input. The credits template renders what the registry hands it and
adds no sanitiser of its own; if that ever changes, it is a
[security-architecture.md](security-architecture.md) decision, not a template
one.

### 8.6 A row is facts plus one sentence, and only the sentence is translated

Owned by [data-provider-hierarchy.md](data-provider-hierarchy.md) §9.2 and
summarised here because it decides what this page renders.

**Facts come from the registry and are never translated:** `name`, `full_name`
(publisher), `creator`, `homepage`, licence code and label, `attribution`,
release year. The attribution line
especially must never be translated, because it is the exact wording a licence
obliges us to show, which is the same one-way-door rule as §2.

**The sentence stays translated,** because it is copy about us, not about them.
Two columns carry it and the renderer prefers the first:

| Column | Holds | Used by |
|--------|-------|---------|
| `blurb_key` | a message key, e.g. `credits.osm_p` | seeded rows, which therefore keep every existing translation |
| `blurb` | free English text | a provider a curator adds at `/moderate/providers` |

A curator cannot write Spanish, so a `blurb` is registered as a
`translation_entry` under a synthetic key `provider.<key>.blurb` and translated
the way everything else on the site is. That fits the table's own rule that
identity is the message key and never the display wording
([translations.md](translations.md) §3.1), and needs exactly one change: the
sync that projects `messages.en.yaml` into `translation_entry` also projects
registry blurbs. Overlays resolve by key and do not care where the English came
from. Until a translation is approved, English shows, which is already what
happens for a new YAML key. A provider added on Tuesday is right in English
immediately and right in five languages when the queue clears, with no deploy
either time.

`credited_providers()` therefore returns facts as values plus an
already-resolved sentence. **The template renders and does not choose.**

### 8.7 The gate afterwards: one blind spot, one new check worth writing

The gate reads the template. Generated rows are invisible to it, so on the day
this lands the dataset half of the page leaves the gate's coverage entirely.
That is acceptable only because a different guarantee replaces it: the registry
is the single source, so a dataset cannot be *uncredited* the way a package
can.

One new check becomes possible and is worth writing at that point:

> **DUPLICATE**: a hand-written `.crow` whose link host matches the `homepage`
> of a provider returned by `credited_providers()`.

That is §8.1's page-to-registry rule made mechanical. It guards the migration
sweep, and then keeps guarding: any curator can later add a source the page
still credits by hand, and the page then names it twice. It cannot be written
before the registry exists, which is the only reason it is not in §5 already.

### 8.8 The line: scheduled ingest is a provider, on demand is not

Owner's call, 2026-08-27, and it settles the Mapillary and Esri question by
replacing a taste judgement with a mechanical one.

> A **provider** is a dataset this project imports, from a local file or an
> online API, on a schedule: once a week, a month, or a year. Something fetched
> **on demand**, per request, is not a provider and stays hand-written.

This is a better rule than the one it replaces ("software, fonts and basemap
stay hand-written") because it does not depend on what kind of thing something
is, only on how it reaches us. It also matches what the registry is *for*: rank
resolution, duplicate guards, coverage suppression and citation are all
questions about rows we hold. There is nothing to rank about an image tile
fetched while the reader is looking at it.

Applied to the page as it stands today:

| Row | How it reaches us | Verdict |
|-----|-------------------|---------|
| OpenStreetMap | Geofabrik extracts, harvested | provider |
| Overture Maps | pinned release, exported | provider |
| Géoportail de la Wallonie · PIVOT | harvested | provider |
| Geofabrik | harvested | provider |
| Wikimedia Commons | resolved and cached at harvest | provider |
| Wikipedia | fetched at build time | provider |
| Copernicus DEM GLO-30 | DEM tiles installed on the host | provider |
| Nationaal Georegister (parked) | scheduled import | provider |
| Drinkwaterkaart.nl (parked) | via the Georegister | provider |
| **Mapillary** | live API, per request | **hand-written** |
| **Esri World Imagery** | live tiles, per request | **hand-written** |
| OpenFreeMap | live tiles, per request | hand-written |
| Photon | live geocoding, per request | hand-written |
| Valhalla, MapLibre, Protomaps, Redoc | software we run or ship | hand-written (§3, gated) |

So the map group keeps its two required notices, hand-placed, after the
migration. `manual:mapillary-service` and `manual:esri-imagery` stay valid
markers, and the `#credits-req` footnote is still needed by rows outside the
generated group. Neither of those was safe to assume before this rule existed.
