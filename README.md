# Cycling Commons

Every rider carries a private map: the fountain that saved a hot July, the climb worth the extra
hour, the back road that's somehow always empty. Today that knowledge lives in one head and dies
there. Cycling Commons is where it lives instead.

An open, community-built commons of **non-personal** cycling data: both the practical (climbs,
water, bike-friendly stays, road conditions) and the experiential (the viewpoints worth stopping
for, the heritage worth a detour, the rides worth doing for their own sake). Free for anyone to
use, build on, and improve. The map belongs to everyone. Places, never people.

Maintained by **[BikeCoders](https://bikecoders.life)** as a standalone, open-data project.
Even commercial apps can build on top of the Commons, through its open data and API or by
running the platform code itself, and contribute back, but the Commons is independent and built
to outlive any single app.

## Why this exists

Cycling knowledge is scattered (climbs in one app, routes in another, road conditions nowhere)
and much of it is closed. OpenStreetMap is open, but raw and thin on the cycling-specific layers that
matter. The Commons's real value is the **curation layer** on top: riders don't just contribute facts,
they curate, vote, and keep them fresh, adding the cycling judgment that raw data lacks and closed
apps lock away. So rather than an endless list, it answers the question a rider actually asks:
**what's the best riding here?**

## What's open, and what never is

- **In the Commons (open, ODbL):** non-personal data about *the world*, so climbs, water, stays,
  hazards, viewpoints, services.
- **Never in the Commons:** personal data, meaning a person's identity, history, fitness metrics
  or movements. The Commons simply never collects it (see Manifesto §IV). The map, not the rider.

## Repository layout

| Path | Becomes | Holds |
|------|---------|-------|
| [`wiki/`](wiki/) | `wiki.cyclingcommons.org` | all reference documentation |
| [`atlas/demo/`](atlas/demo/) | `cyclingcommons.org` | the static map demo (the clickable prototype) |
| [`web/`](web/) | `cyclingcommons.org` | the Symfony web app: server-rendered site + future `/api/*` JSON |
| [`pipeline/`](pipeline/) | - | the Python/FastAPI geospatial tier *(to be built)* |
| [`tools/`](tools/) | - | data-generation & maintenance tooling (e.g. regional clustering) |
| [`developers/`](developers/) | - | the Docker dev stack and contributor setup |
| [`licenses/`](licenses/) | - | plain-language notices for the data, media, translations and terms licences: what they mean, in prose |
| [`LICENSES/`](LICENSES/) | - | the *verbatim* licence texts, named as [REUSE](https://reuse.software/) requires. One letter from the directory above and a different job: these are the legal texts a machine reads, those are the explanations a person reads |

## Developer setup with Docker

The whole stack runs in one reproducible environment so every contributor works the same way.

**Quick start, one command:**

```sh
make setup
```

`make setup` starts the Docker stack, installs PHP dependencies, runs the database migrations,
imports the world reference data (continents · countries · subdivisions) and loads four
ready-to-use demo accounts, then prints their logins. It is idempotent: re-run it any time to
reset the local dev data. (It uses sensible defaults; copy `developers/docker/.env.example` to
`developers/docker/.env` only if you want to override ports/credentials.)

**Demo logins**, all with password `password1234`:

| Account | Role | Notes |
|---------|------|-------|
| `admin@example.test` | `ROLE_ADMIN` | 2FA preset; reaches `/admin` |
| `moderator@example.test` | `ROLE_CURATOR` | 2FA preset; reaches `/moderate` |
| `user@example.test` | `ROLE_USER` | public profile, home country set |
| `anon@example.test` | `ROLE_USER` | private / anonymous profile |

Prefer to run it by hand? The stack is plain Docker Compose:

```sh
cd developers/docker
cp .env.example .env
docker compose up --build --wait
# then, once: migrate, seed reference data, load demo users
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:world:import
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction \
  --purge-exclusions=world_continent --purge-exclusions=world_country --purge-exclusions=world_subdivision
```

| Service | URL | What it is |
|---------|-----|------------|
| Frontend | http://localhost:8099 | the MapLibre prototype (`atlas/demo/`) |
| Wiki | http://localhost:8013 | MkDocs Material (`wiki/`) |
| Web app | http://localhost:8001/ · `/health` · `/api/db-check` | Symfony 7 LTS on nginx + PHP-FPM → PostGIS |
| Pipeline | http://localhost:8012/health · `/db` | Python/FastAPI geospatial tier → PostGIS |
| Database | `localhost:5433` | PostgreSQL 18 + PostGIS (host port 5433) |
| Object storage | http://localhost:9100 · console http://localhost:9101 | MinIO, behind the media (`:9102`) and tiles (`:9103`) nginx fronts |

One service is opt-in, because it needs a tile set you download yourself: `--profile routing`
(Valhalla). Full details and the architecture diagram are in
[`developers/docker/README.md`](developers/docker/README.md).

> The API and pipeline currently ship connectivity scaffolding (health + DB-version endpoints);
> the real query/contribution logic and the geo pipeline get built on top. See
> [`wiki/building.md`](wiki/building.md) for the target stack.

## Running the web app

Requires PHP 8.4 and Composer.

    make app-install
    make app-serve     # http://127.0.0.1:8010
    make app-test      # phpunit + phpstan + psalm + cs-fixer + SPDX/licence gates

Config: copy any `web/.env` values you need into `web/.env.local` (gitignored).
Never commit real secrets: `web/.env` holds placeholders only.

**Dev mail (Mailpit):** outbound email (registration confirmation, password-reset, etc.) is
caught by the **bundled** [Mailpit](https://mailpit.axllent.org/), so nothing leaves your machine.
Read it at <http://localhost:8025>. Already run a Mailpit on `:8025`? Set `MAILPIT_UI_PORT`
(e.g. `8026`), or point the app at yours with `MAILER_DSN=smtp://host.docker.internal:1025`.

**Bootstrap an admin account:**

    make app-create-admin email=you@example.com

**Bootstrap a curator account** (to access `/moderate`):

    make app-create-curator email=you@example.com

The command prompts securely for the password (input hidden, never visible on screen or in shell history). Pass it as a positional argument only in non-interactive scripts.

## Contribution + moderation workflow

### Contribution pages (requires login / ROLE_USER)

| Page | Route | What it does |
|------|-------|--------------|
| `/contribute` | `contribute` | Public hub: links to all contribution flows |
| `/improve` | `improve` | Step-by-step form to add, fix or update a place (`?type=climbs&mode=add` traces a new climb) |
| `/add-climb` | `add_climb` | 301 to `/improve?type=climbs&mode=add` (the dedicated wizard was retired 2026-08-25) |
| `/vote` | `vote` | Cast a seasonal vote for the best riding in a region |

Both action pages (`/improve`, `/vote`) require a verified account (`ROLE_USER`). The `/contribute` hub is public.

### Curator moderation queue (requires ROLE_CURATOR + 2FA)

`/moderate` is the branded review surface for curators: approve, reject, or request info on pending submissions. Access requires `ROLE_CURATOR`. Curators who have not yet enrolled in 2FA are automatically redirected to `/2fa/setup` until enrolment is complete.

### Persistence boundary: what is and is not saved

Contribution and moderation are **fully persisted**: a submission becomes a
moderation-queue row, an approval publishes to the live catalog, and every
decision keeps its audit trail (see
[`docs/specs/moderation-and-contribution.md`](docs/specs/moderation-and-contribution.md)).
The `CC-…` receipt a contributor sees references the stored submission.

### Seed the local dev database with demo accounts + world data

The one-command path is **`make setup`** (see [Developer setup](#developer-setup-with-docker)),
and it migrates, imports the world reference data (`app:world:import`: 7 continents · 249 countries ·
~5k subdivisions) and loads the four demo accounts below. To (re)load just the demo accounts
while keeping the world tables:

    docker compose exec app php bin/console doctrine:fixtures:load --no-interaction \
      --purge-exclusions=world_continent --purge-exclusions=world_country --purge-exclusions=world_subdivision

> **`doctrine:fixtures:load` purges the database before seeding.** That is why the world tables
> are excluded above, and why you should only run it against a local/dev DB.

| Email | Password | Role | Notes |
|-------|----------|------|-------|
| `admin@example.test` | `password1234` | ROLE_ADMIN | 2FA preset, reaches `/admin` |
| `moderator@example.test` | `password1234` | ROLE_CURATOR | 2FA preset, reaches `/moderate` |
| `user@example.test` | `password1234` | ROLE_USER | public profile, home country set |
| `anon@example.test` | `password1234` | ROLE_USER | private / anonymous profile |

The fixtures also drive a small sample moderation queue at `/moderate`. These are dev-only
fixtures; no real contribution data exists until the data-API layer is built.

## Documentation

- [Manifesto](wiki/manifesto.md): the principles, grounded in Ostrom's *Governing the Commons*
- [Data catalog](wiki/data-catalog.md): what data lives in the Commons
- [Curation & voting](wiki/curation-and-voting.md): how the regional "best of" is chosen and kept fresh
- [Contributing](wiki/contributing.md): how to take part
- [Governance](GOVERNANCE.md): who stewards it, and the path to an independent foundation (full model in [`wiki/governance.md`](wiki/governance.md))
- [Building the Commons](wiki/building.md): how it's built and run, and how to contribute

## License

The platform code and the Commons it serves are licensed separately, and both are open. The
code is free software: anyone may run it, study it, change it and pass it on, for any purpose,
including a commercial one. The one thing the licence asks back is the source, including from
anyone who runs a changed version as a network service. The data, the media and the wiki were
open from the start and are unchanged.

| What | Licence | Text |
|------|---------|------|
| **Code and UI** (API, pipeline, site, tooling) | `AGPL-3.0-only` | [`LICENSE`](LICENSE) |
| **Data** | ODbL 1.0 | [`licenses/COMMONS-DATA-LICENSE.md`](licenses/COMMONS-DATA-LICENSE.md) |
| **Media** (rider photos and other standalone media) | CC BY-SA 4.0 | [`licenses/COMMONS-MEDIA-LICENSE.md`](licenses/COMMONS-MEDIA-LICENSE.md) |
| **Wiki text** (`wiki/`, published at wiki.cyclingcommons.org) | CC BY-SA 4.0 | [`licenses/CC-BY-SA-4.0.txt`](licenses/CC-BY-SA-4.0.txt) |
| **UI translations** | `AGPL-3.0-only` | [`licenses/COMMONS-TRANSLATIONS-LICENSE.md`](licenses/COMMONS-TRANSLATIONS-LICENSE.md) |
| **Name and logo** | reserved, not open | [`TRADEMARK.md`](TRADEMARK.md) |

A few paths sit outside those rows and say so in their own headers, because they are not ours
to license. The country flags in `web/assets/flags` are third-party artwork, MIT, copyright
Panayiotis Lipiridis and the flag-icons contributors. `web/assets/lib/scout-fit.js` is a
verbatim copy from the Scout repository and stays MIT so a re-sync stays a copy rather than a
merge. The five photographs under `web/public/media` belong to their photographers, under the
licences they chose.

Forking is welcome and asks nobody's permission. Two things follow from that. The licence
travels: if you run a changed version as a network service, section 13 says the people using it
must be offered its Corresponding Source, and that duty is ours here too. The name does not
travel: the code freedoms come with the licence, the marks do not, which section 7(e) of the
AGPL expressly allows. So a fork rides under its own name and drops the files carrying
`LicenseRef-CyclingCommons-Brand`. [`TRADEMARK.md`](TRADEMARK.md) says which those are, and
what a fork may still say truthfully about where it came from.

Contributors keep their own copyright. Nothing is assigned: work comes in under the Developer
Certificate of Origin, signed off with `git commit -s`, and the inbound terms are in
[`licenses/COMMONS-TERMS-CLAUSE.md`](licenses/COMMONS-TERMS-CLAUSE.md).

```
Cycling Commons
Copyright (C) 2026 BikeCoders

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU Affero General Public License as published by the Free
Software Foundation, version 3.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License along
with this program. If not, see <https://www.gnu.org/licenses/>.

Xander Koevoet is designated the proxy under section 14 of the GNU Affero
General Public License, and may decide whether future versions of that licence
apply to this program. This designation passes to the Dutch stichting that will
hold the project once it is incorporated.
```

Every file in the repository declares its licence, and that is enforced rather
than trusted: the project is **[REUSE](https://reuse.software/) compliant**.
Files carry an `SPDX-License-Identifier` header; the ones that cannot (fonts,
brand assets, data exports) are covered by [`REUSE.toml`](REUSE.toml); the
verbatim texts live in [`LICENSES/`](LICENSES/); and `reuse lint` runs in CI on
every push. So "which licence covers this file?" always has an answer you can
check, not one you have to ask about.
