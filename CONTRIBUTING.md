# Contributing to the Cycling Commons

Thanks for helping build an open commons of cycling knowledge. There are **two ways** to
contribute, under **different licences**. Please read whichever applies to you.

## 1. Contribute data & media (the Commons)

Climbs, water points, viewpoints, bike-friendly stays, road conditions, photos: the map itself.

- The easiest path is through the site's *add* / *improve* flows (and, later, the API).
- **Licensing:** data you contribute is published under the **ODbL 1.0**, media under
  **CC BY-SA 4.0**. By contributing you grant the Commons and the public the licence described in
  the [Commons terms](licenses/COMMONS-TERMS-CLAUSE.md) (1.3) and confirm you have the right to
  share what you submit (1.4). Contribute only facts you observed and media you created or are
  licensed to share; **never** scrape or re-host third-party content.
- **Never** submit personal data. The Commons is the map, not the rider.

## 2. Contribute code (the platform)

The API, pipeline, site, and tooling.

### The licence

The code is free software under the [GNU Affero General Public License v3](LICENSE),
SPDX `AGPL-3.0-only`. Anyone may run it, study it, change it and fork it, for any
purpose, commercial purposes included. There is no field of use you have to stay out
of, and no permission to come and ask for.

What the AGPL asks back is source. Ship a modified version to anyone and you pass on
the Corresponding Source under the same licence. **Section 13 adds the part that
matters most for a web platform:** if you let people interact with a modified version
over a network, you have to offer *those* users the Corresponding Source, even though
you never handed them a copy to install. Running it as a service is not a way around
the licence; it is the case the licence was written for.

The one thing an AGPL licence does not hand over is the name. The Cycling Commons
name, logo and wordmark stay reserved, which AGPL v3 section 7(e) expressly allows.
Fork the code freely, then give the fork its own name and its own branding, and read
[TRADEMARK.md](TRADEMARK.md) for where the line sits.

Section 14 of the AGPL lets a designated proxy decide whether the project moves to a
future version of the licence. For this project the proxy is a named person, **Xander
Koevoet**, and deliberately not a web page: anyone able to edit such a page could
publish an acceptance that section 14 would then make permanently binding.

### How to contribute code

- Open a pull request against `main`. Keep changes focused and match the surrounding
  style. Substantive design decisions belong in the
  [wiki](https://wiki.cyclingcommons.org/) so there's a record.
- **Sign off every commit.** A sign-off is a `Signed-off-by:` trailer in the commit
  message, carrying your real name and the same email address as the commit author.

  **Run `pre-commit install` once** (see *Running the web app* below) and you never
  have to think about this again: a `prepare-commit-msg` hook adds the trailer to
  every commit you make, from the terminal or from an editor. Set your identity
  first, or the hook stops the commit rather than writing a trailer that names
  nobody:

      git config user.name  "Your Name"
      git config user.email "you@example.com"

  Without the hook, or to repair commits made before you installed it:

      git commit -s -m "your message"
      git commit --amend -s --no-edit          # fix the last one
      git rebase --signoff HEAD~3              # fix the last three

  After an amend or a rebase you will need `git push --force-with-lease`.

That trailer is the inbound licence grant for code. It is the only grant this project
asks of you, and there is no click-through, no form and no assignment alongside it.

### What you are certifying

The sign-off certifies the Developer Certificate of Origin 1.1, reproduced here in
full and published at <https://developercertificate.org/>:

```
Developer Certificate of Origin
Version 1.1

Copyright (C) 2004, 2006 The Linux Foundation and its contributors.

Everyone is permitted to copy and distribute verbatim copies of this
license document, but changing it is not allowed.


Developer's Certificate of Origin 1.1

By making a contribution to this project, I certify that:

(a) The contribution was created in whole or in part by me and I
    have the right to submit it under the open source license
    indicated in the file; or

(b) The contribution is based upon previous work that, to the best
    of my knowledge, is covered under an appropriate open source
    license and I have the right under that license to submit that
    work with modifications, whether created in whole or in part
    by me, under the same open source license (unless I am
    permitted to submit under a different license), as indicated
    in the file; or

(c) The contribution was provided directly to me by some other
    person who certified (a), (b) or (c) and I have not modified
    it.

(d) I understand and agree that this project and the contribution
    are public and that a record of the contribution (including all
    personal information I submit with it, including my sign-off) is
    maintained indefinitely and may be redistributed consistent with
    this project or the open source license(s) involved.
```

In plain terms, what signing off does:

- **You keep your copyright.** Your commits stay yours. Nothing here changes who owns
  them.
- **You are not assigning anything.** No transfer of rights takes place, in either
  direction.
- **You grant a non-exclusive licence.** The project may publish your work as part of
  the Cycling Commons under `AGPL-3.0-only`. Non-exclusive means you may still use,
  relicense or republish your own code anywhere else, on any terms you like.
- **You confirm you were entitled to grant it.** The work is yours, or it came to you
  under a compatible licence and you may pass it on. If your employer owns what you
  write at work, get their agreement before you sign off.

### Stewardship, and why your sign-off covers the handover

Copyright in this code is held today by BikeCoders (<https://bikecoders.life>), a
trading name of the project's sole owner. Within about a year stewardship moves to a
Dutch stichting (foundation) created for the purpose. By signing off your commits you
also agree that the licence you grant may be exercised by that stichting, and that the
section 14 proxy designation passes to the stichting on its incorporation.

**Why the clause exists.** Without it, the handover would have to be assembled by hand:
every past contributor asked, individually, for personal consent. One author who has
moved on, changed address or simply stopped answering would be enough to stall it. Your
agreement, given once at sign-off, is what keeps the project's future from resting on
everybody staying reachable forever.

**What the clause is not.** It is not a copyright assignment, and it could not be one
even if the project wanted it. Under Dutch law, art. 2 Auteurswet together with the Wet
versterking auteurscontractenrecht in force since 2026-01-01, transferring copyright
takes a signed written deed. A checkbox in a repository is not a deed and cannot be
made into one. You keep your copyright. The stichting inherits exactly the
non-exclusive licence you granted, and nothing beyond it.

### The check

[`.github/workflows/dco.yml`](.github/workflows/dco.yml) runs on every pull request. It
walks each non-merge commit on the branch and requires a `Signed-off-by:` trailer that
names the commit's own author. A commit without one fails the check, and the failure
output prints the commands above so you can fix it in place and push again.

## Running the web app

Requires PHP 8.4 and Composer. From the repo root:

    make app-install
    make app-serve     # http://127.0.0.1:8010
    make app-test      # phpunit + phpstan + psalm + cs-fixer + SPDX/licence gates

Config: copy any `web/.env` values you need into `web/.env.local` (gitignored).
Never commit real secrets: `web/.env` holds placeholders only.

**Secret-scanning hook (please install):** so a secret can't be committed by
accident, install the pre-commit hook. It blocks the commit *before* it is
created:

    pip install pre-commit    # or: pipx install pre-commit / brew install pre-commit
    pre-commit install

It runs [gitleaks](https://github.com/gitleaks/gitleaks) locally at two points:
on **commit** (your staged changes) and on **push** (the outgoing commits, so a
secret committed with `--no-verify` is still caught before it leaves the machine).
Both are fast, fully offline, and honour `.gitleaks.toml`. CI
(`.github/workflows/secret-scan.yml`) re-scans the full history with gitleaks +
TruffleHog on every push/PR as a backstop, and GitHub Push Protection blocks a
leaking push server-side, but the local hooks are the front line. You don't need
to install gitleaks or TruffleHog yourself: `pre-commit` fetches gitleaks
(the pre-push hook fetches a pinned copy on first use), and TruffleHog runs only in CI.

The same `pre-commit install` also enables the DCO sign-off hook described in
section 2: it writes the `Signed-off-by:` trailer into every commit message so the
pull-request check never has to fail you for a missing one.

The same `pre-commit install` also enables a translation-parity check: when you
stage a `web/translations/messages.*.yaml` file, it verifies every key exists in
all five locales (en/fr/nl/de/es). It needs PHP + `composer install` in `web/` and
skips with a notice otherwise. CI (`make app-test`) enforces it regardless.

**Pushing runs the whole test gate.** Any outgoing change under `web/` triggers
`tools/app-gate-prepush.sh`, which runs everything `make app-test` runs:
php-cs-fixer, the SPDX/licence/translation gates, the Node tests, PHPStan, Psalm,
then the full PHPUnit suite. Cheapest checks come first, so a formatting slip fails in seconds;
a clean run takes a few minutes. `staging` deploys on push, so this is the last
point before a red gate reaches a server. `git push --no-verify` skips it.

**Dev mail (Mailpit):** outbound email (registration confirmation, password-reset links, etc.)
is sent to a [Mailpit](https://mailpit.axllent.org/) on the host at `:1025`. No real mail is
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

> **Heads-up:** contribution and moderation form submissions are currently **stubbed**. Each submission generates a `CC-…` receipt and is logged, but **no data is persisted** and nothing is published to the Commons. The stub (`ContributionStubService`) is an explicit seam: the body will be replaced when the data-API spec lands; the interface and call sites remain unchanged.

### Seed sample accounts for local review

    cd web && php bin/console doctrine:fixtures:load

> **Warning: this PURGES the database** before seeding. Use only on a local/dev DB.

Loads `curator@example.test` (ROLE_CURATOR, 2FA preset, password `curator-dev-pass!`) and `rider@example.test` (ROLE_USER, password `rider-dev-pass!`), plus a sample moderation queue at `/moderate`.

## Ground rules

- Be kind. This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md).
- Security issues: **do not** open a public issue. See [SECURITY.md](SECURITY.md).
- More on how the Commons is built and governed:
  [wiki/contributing.md](wiki/contributing.md) and [GOVERNANCE.md](GOVERNANCE.md).

Questions: development@cyclingcommons.org
