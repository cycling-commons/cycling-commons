# Cycling Commons

Every rider carries a private map: the fountain that saved a hot July, the climb worth the extra
hour, the back road that's somehow always empty. Today that knowledge lives in one head and dies
there. Cycling Commons is where it lives instead.

An open, community-built commons of **non-personal** cycling data — both the practical (climbs,
water, bike-friendly stays, road conditions) and the experiential (the viewpoints worth stopping
for, the heritage worth a detour, the rides worth doing for their own sake) — free for anyone to
use, build on, and improve. The map belongs to everyone — places, never people.

Maintained by **[BikeCoders](https://bikecoders.life)** as a standalone, open-data project.
Even commercial apps can build on top of the Commons — through its open data and API — and contribute back,
but the Commons is independent and built to outlive any single app.

## Why this exists

Cycling knowledge is scattered — climbs in one app, routes in another, road conditions nowhere — and
much of it is closed. OpenStreetMap is open, but raw and thin on the cycling-specific layers that
matter. The Commons's real value is the **curation layer** on top: riders don't just contribute facts
— they curate, vote, and keep them fresh, adding the cycling judgment that raw data lacks and closed
apps lock away. So rather than an endless list, it answers the question a rider actually asks:
**what's the best riding here?**

## What's open — and what never is

- **In the Commons (open, ODbL):** non-personal data about *the world* — climbs, water, stays,
  hazards, viewpoints, services.
- **Never in the Commons:** personal data — a person's identity, history, fitness metrics, movements.
  The Commons simply never collects it (see Manifesto §IV) — the map, not the rider.

## Repository layout

| Path | Becomes | Holds |
|------|---------|-------|
| [`wiki/`](wiki/) | `wiki.cyclingcommons.org` | all reference documentation |
| [`atlas/demo/`](atlas/demo/) | `cyclingcommons.org` | the static map demo (the clickable prototype) |
| [`web/`](web/) | `cyclingcommons.org` | the Symfony web app — server-rendered site + future `/api/*` JSON |
| [`pipeline/`](pipeline/) | — | the Python/FastAPI geospatial tier *(to be built)* |
| [`tools/`](tools/) | — | data-generation & maintenance tooling (e.g. regional clustering) |
| [`developers/`](developers/) | — | the Docker dev stack and contributor setup |
| [`licenses/`](licenses/) | — | the data, media & terms licenses |

## Developer setup — Docker

The whole stack runs in one reproducible environment so every contributor works the same way:

```sh
cd developers/docker
cp .env.example .env
docker compose up --build
```

| Service | URL | What it is |
|---------|-----|------------|
| Frontend | http://localhost:8099 | the MapLibre prototype (`atlas/demo/`) |
| Wiki | http://localhost:8000 | MkDocs Material (`wiki/`) |
| Web app | http://localhost:8001/ · `/health` · `/api/db-check` | Symfony 7 LTS on nginx + PHP-FPM → PostGIS |
| Pipeline | http://localhost:8002/health · `/db` | Python/FastAPI geospatial tier → PostGIS |
| Database | `localhost:5433` | PostgreSQL 18 + PostGIS (host port 5433) |

Heavy, data-dependent services are opt-in: `--profile routing` (Valhalla, on a prebuilt tile set
you download) and `--profile storage` (MinIO for PMTiles). Full details and the architecture
diagram are in [`developers/docker/README.md`](developers/docker/README.md).

> The API and pipeline currently ship connectivity scaffolding (health + DB-version endpoints);
> the real query/contribution logic and the geo pipeline get built on top. See
> [`wiki/building.md`](wiki/building.md) for the target stack.

## Running the web app

Requires PHP 8.4 and Composer.

    make app-install
    make app-serve     # http://127.0.0.1:8010
    make app-test      # phpunit + phpstan + psalm + cs-fixer + SPDX/licence gates

Config: copy any `web/.env` values you need into `web/.env.local` (gitignored).
Never commit real secrets — `web/.env` holds placeholders only.

**Dev mail (Mailpit):** outbound email (registration confirmation, password-reset, etc.) is
sent to a [Mailpit](https://mailpit.axllent.org/) on the host at `:1025`; read it at
<http://localhost:8025>. The stack does **not** bundle its own Mailpit (it collided with a
shared host instance). No Mailpit yet? `docker run -d -p 8025:8025 -p 1025:1025 axllent/mailpit`.

**Bootstrap an admin account:**

    make app-create-admin email=you@example.com

**Bootstrap a curator account** (to access `/moderate`):

    make app-create-curator email=you@example.com

The command prompts securely for the password (input hidden, never visible on screen or in shell history). Pass it as a positional argument only in non-interactive scripts.

## Contribution + moderation workflow

### Contribution pages (requires login / ROLE_USER)

| Page | Route | What it does |
|------|-------|--------------|
| `/contribute` | `contribute` | Public hub — links to all contribution flows |
| `/add-climb` | `add_climb` | Wizard to describe and trace a climb |
| `/improve` | `improve` | Step-by-step form to fix or update a place |
| `/vote` | `vote` | Cast a seasonal vote for the best riding in a region |

All three action pages (`/add-climb`, `/improve`, `/vote`) require a verified account (`ROLE_USER`). The `/contribute` hub is public.

### Curator moderation queue (requires ROLE_CURATOR + 2FA)

`/moderate` is the branded review surface for curators — approve, reject, or request info on pending submissions. Access requires `ROLE_CURATOR`. Curators who have not yet enrolled in 2FA are automatically redirected to `/2fa/setup` until enrolment is complete.

### Persistence boundary — what is and is not saved

> **Important:** contribution and moderation actions are currently **stubbed**. Submitting a form records intent — a flash, a log line, and a `CC-…` receipt reference — via `ContributionStubService`, but **no domain data is persisted** and nothing is published. Real persistence will be wired in as part of the future data-API spec. Users and curators are told this explicitly in the UI.

### Seed the local dev database with sample accounts and a queue

    cd web && php bin/console doctrine:fixtures:load

> **Warning: `doctrine:fixtures:load` PURGES the entire database before seeding.** Only run this against a local/dev DB.

After loading, two accounts are available:

| Email | Password | Role | Notes |
|-------|----------|------|-------|
| `curator@example.test` | `curator-dev-pass!` | ROLE_CURATOR | 2FA preset — can reach `/moderate` immediately |
| `rider@example.test` | `rider-dev-pass!` | ROLE_USER | plain rider account |

The fixtures also populate a small sample moderation queue visible at `/moderate`. These are dev-only fixtures; no real submissions exist until the data-API layer is built.

## Documentation

- [Manifesto](wiki/manifesto.md) — the principles, grounded in Ostrom's *Governing the Commons*
- [Data catalog](wiki/data-catalog.md) — what data lives in the Commons
- [Curation & voting](wiki/curation-and-voting.md) — how the regional "best of" is chosen and kept fresh
- [Contributing](wiki/contributing.md) — how to take part
- [Governance](GOVERNANCE.md) — who stewards it, and the path to an independent foundation (full model in [`wiki/governance.md`](wiki/governance.md))
- [Building the Commons](wiki/building.md) — how it's built and run, and how to contribute

## License

The platform code and the Commons it serves are licensed separately — open data,
source-available code:

- **Code** — [PolyForm Shield 1.0.0](LICENSE) (the platform: API, pipeline, site, tooling). Source-available: use it for any purpose **except** building a product that competes with the Cycling Commons. To build *on the Commons*, use the open data and API — free for any use, including commercial — rather than forking the platform. See [`NOTICE`](NOTICE).
- **Data** — [Open Database License (ODbL)](licenses/COMMONS-DATA-LICENSE.md)
- **Media** — [Commons Media License](licenses/COMMONS-MEDIA-LICENSE.md)
- **Terms** — [Commons Terms & Clause](licenses/COMMONS-TERMS-CLAUSE.md)
