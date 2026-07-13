# Contributing to the Cycling Commons

Thanks for helping build an open commons of cycling knowledge. There are **two ways** to
contribute, under **different licences** — please read whichever applies to you.

## 1. Contribute data & media (the Commons)

Climbs, water points, viewpoints, bike-friendly stays, road conditions, photos — the map itself.

- The easiest path is through the site's *add* / *improve* flows (and, later, the API).
- **Licensing:** data you contribute is published under the **ODbL 1.0**, media under
  **CC BY-SA 4.0**. By contributing you grant the Commons and the public the licence described in
  the [Commons terms](licenses/COMMONS-TERMS-CLAUSE.md) (X.3) and confirm you have the right to
  share what you submit (X.4) — contribute only facts you observed and media you created or are
  licensed to share; **never** scrape or re-host third-party content.
- **Never** submit personal data. The Commons is the map, not the rider.

## 2. Contribute code (the platform)

The API, pipeline, site, and tooling.

- Open a pull request against `main`. Keep changes focused and match the surrounding style.
  Substantive design decisions belong in the [wiki](https://wiki.cyclingcommons.org/) so there's a
  record.
- **Licensing:** the code is **source-available** under the
  [PolyForm Shield License 1.0.0](LICENSE) — use it for any purpose **except** a product that
  competes with the Cycling Commons. By submitting a pull request you agree your contribution is
  provided under that licence.

## Running the web app

Requires PHP 8.4 and Composer. From the repo root:

    make app-install
    make app-serve     # http://127.0.0.1:8010
    make app-test      # phpunit + phpstan + psalm + cs-fixer + SPDX/licence gates

Config: copy any `web/.env` values you need into `web/.env.local` (gitignored).
Never commit real secrets — `web/.env` holds placeholders only.

**Secret-scanning hook (please install):** so a secret can't be committed by
accident, install the pre-commit hook — it blocks the commit *before* it is
created:

    pip install pre-commit    # or: pipx install pre-commit / brew install pre-commit
    pre-commit install

It runs [gitleaks](https://github.com/gitleaks/gitleaks) on your staged changes
(fast, fully offline; honours `.gitleaks.toml`). CI (`.github/workflows/secret-scan.yml`)
re-scans the full history with gitleaks + TruffleHog on every push/PR as a
backstop — but the local hook is the front line. You don't need to install
gitleaks or TruffleHog yourself: `pre-commit` fetches gitleaks, and TruffleHog
runs only in CI.

**Dev mail (Mailpit):** outbound email (registration confirmation, password-reset links, etc.)
is sent to a [Mailpit](https://mailpit.axllent.org/) on the host at `:1025` — no real mail is
sent in local development; read it at <http://localhost:8025>. The stack does **not** bundle its
own Mailpit. No Mailpit yet? `docker run -d -p 8025:8025 -p 1025:1025 axllent/mailpit`.

**Bootstrap an admin account** (requires the Docker DB to be running and migrations applied):

    make app-create-admin email=you@example.com

**Bootstrap a curator account** (to access `/moderate`):

    make app-create-curator email=you@example.com

The command prompts securely for the password (input hidden, never visible on screen or in shell history). Pass it as a positional argument only in non-interactive scripts.

## Contribution + moderation pages

### What exists

| Page | Route | Auth required |
|------|-------|---------------|
| `/contribute` | `contribute` | none (public hub) |
| `/add-climb` | `add_climb` | ROLE_USER (login required) |
| `/improve` | `improve` | ROLE_USER (login required) |
| `/vote` | `vote` | ROLE_USER (login required) |
| `/moderate` | `moderate` | ROLE_CURATOR + 2FA enrolled |

`/moderate` is the curator review surface (approve / reject / needs-info). Curators who have not enrolled in 2FA are redirected to `/2fa/setup` until enrolment is complete.

### Persistence boundary

> **Heads-up:** contribution and moderation form submissions are currently **stubbed**. Each submission generates a `CC-…` receipt and is logged, but **no data is persisted** and nothing is published to the Commons. The stub (`ContributionStubService`) is an explicit seam — the body will be replaced when the data-API spec lands; the interface and call sites remain unchanged.

### Seed sample accounts for local review

    cd web && php bin/console doctrine:fixtures:load

> **Warning: this PURGES the database** before seeding — use only on a local/dev DB.

Loads `curator@example.test` (ROLE_CURATOR, 2FA preset, password `curator-dev-pass!`) and `rider@example.test` (ROLE_USER, password `rider-dev-pass!`), plus a sample moderation queue at `/moderate`.

## Ground rules

- Be kind — this project follows the [Contributor Covenant](CODE_OF_CONDUCT.md).
- Security issues: **do not** open a public issue — see [SECURITY.md](SECURITY.md).
- More on how the Commons is built and governed:
  [wiki/contributing.md](wiki/contributing.md) and [GOVERNANCE.md](GOVERNANCE.md).

Questions: development@cyclingcommons.org
