# HTML → Symfony migration (presentation + auth foundation) — design

**Date:** 2026-06-26
**Status:** Approved — writing the implementation plan next
**Scope of this spec:** Move the static `atlas/demo/` prototype into the Symfony app as server-rendered pages, and stand up a real authentication foundation (accounts, login, registration, email verification, password reset, 2FA, roles). The query/contribution data API and domain entities (climbs, votes, hazards…) are **explicitly out of scope** here.

> **Route-domain carve-out (2026-07-08):** This spec's "votes and contribution persistence are deferred to a later spec" framing is now superseded on the route (K / Quality rides) side: the [route-domain design](2026-07-08-route-domain-design.md) defines a purpose-built pipeline — rider proposals persisted as `RecommendedRoute` rows (state `submitted`), typed seasonal votes in `route_vote`, ride-confirmations in `route_ride`, moderated corrections in `route_suggestion`, and a dedicated Routes queue in the moderation shell. The generic verify → votable → best-of funnel (§12) is replaced for routes by the `submitted → unverified → verified` (+ `retired`) state machine, with voting opening at `verified` and best-of computed per (region, season, bike type). Inline notes below mark the affected passages; the migration/auth content itself is unaffected.

---

## 1. Decisions locked in

| Decision | Choice | Why |
|---|---|---|
| PHP version | **8.4** | Newest battle-tested release, security support to Dec 2028, universal bundle support. Bundle reference (`bikecoderslife/bundle`) already requires `>=8.4`. |
| Framework | **Symfony 7.4 LTS** | Long-term support (~2029), maximum bundle maturity, matches the "boring / self-hostable / foundation-handoff" principle. Near-zero bump from the current `^7.1` scaffold. |
| Rendering | **Hybrid Twig + JS map** | Server-render the ~21 content/auth/form pages as Twig; keep `map.html` as a MapLibre client app inside a thin Twig shell. Best SEO for content/governance pages, CSRF-safe forms, least rewrite. |
| Scope | **Presentation + auth foundation** | Migrate all pages now; wire real accounts/auth/2FA now. Data API + contribution persistence deferred to a later spec. |
| Auth strategy | **Build fresh, lift patterns** from `bikecoderslife/bundle` | Genericizing the bundle into a `MappedSuperclass` would force a refactor of the live Upstream Platform consumer (its `auth` schema, `app_code`, `User extends BaseUser`). Not worth it. CyclingCommons gets its own minimal auth using the same libraries; the bundle is a **pattern reference only — not a dependency**. |
| Asset pipeline | **AssetMapper** (no Node/Vite build) | Keeps the stack boring and self-hostable; no build step for a future foundation to operate. |
| App location | Keep the Symfony app in **`api/`** for now | Avoids churning the docker/nginx wiring. A rename to `app/`/`web/` is a later, orthogonal cleanup. |
| i18n | **Wire `symfony/translation` from the start** | Pan-European project (Wallonia FR/NL, future regions; wiki already EN+FR). Extract user-facing strings to `\|trans` *during* the Twig port; ship EN first, add FR/NL later. Retrofitting i18n after the port is the expensive path. |

> **Superseded for K (2026-07-08):** The Scope row's "later spec" for contribution persistence now exists on the route side — route proposals persist as `RecommendedRoute` rows in state `submitted`, and votes/rides/suggestions land in the purpose-built `route_vote` / `route_ride` / `route_suggestion` tables.

---

## 2. Licensing posture (important)

- The **CyclingCommons product code stays PolyForm Shield 1.0.0** (source-available, non-compete). This migration does **not** open the product code.
- `bikecoderslife/bundle` stays **proprietary and untouched**. It is used as a *reading reference* for proven patterns; **no code is copied verbatim and it is never added as a dependency**. We re-implement equivalent shapes against the same MIT third-party libraries.
- Third-party auth libraries used directly (all permissive, no copyleft): `symfony/security-bundle`, `scheb/2fa-bundle` + `scheb/2fa-totp` + `scheb/2fa-backup-code`, `symfonycasts/verify-email-bundle`, `symfonycasts/reset-password-bundle`, `easycorp/easyadmin-bundle` (all MIT).

---

## 3. Architecture overview

The existing Symfony project (`api/`) becomes **the web application**: it serves Twig-rendered HTML and reserves `/api/*` for future JSON endpoints.

```
Request → nginx → PHP-FPM → Symfony router → Controller → Twig (base.html.twig) → HTML
Assets  → AssetMapper (importmap + versioned static files)
Auth    → Security firewall + session cookie; scheb 2FA interstitial
Map     → map Twig shell boots MapLibre JS, which fetches the static *-osm.js fixtures
          (those fixtures become API-fed in the later data-API spec)
```

**nginx change** ([developers/docker/nginx/atlas.conf](../../developers/docker/nginx/atlas.conf)): stop serving `atlas/demo/` statically; route all non-asset requests to `public/index.php`; serve AssetMapper output. This is the single infra file that changes.

---

## 4. Proposed Symfony app structure (`api/`)

```
api/
  assets/
    styles/        atlas.css, fonts.css
    fonts/ brand/ media/
    js/            nav.js, a11y.js, quicknav.js, version.js, config (token-free)
    data/          *-osm.js, *-data.js, profiles-data.js, races.js, regions-data.js (static fixtures, served as-is for now)
    map/           extracted modules from map.html's inline JS
    app.js, importmap.php
  config/packages/  twig.yaml, security.yaml, doctrine.yaml (orm), mailer.yaml,
                    scheb_2fa.yaml, asset_mapper.yaml, easy_admin.yaml
  src/
    Controller/     PageController, MapController, SecurityController,
                    RegistrationController, ResetPasswordController, TwoFactorController,
                    ProfileController, SettingsController, ContributeController, ModerateController
    Controller/Admin/  DashboardController + EasyAdmin CRUD controllers
    Entity/         User.php
    Repository/     UserRepository.php
    Security/       EmailVerifier, LoginSuccessHandler (2FA-setup redirect), LoginThrottle
    Form/           RegistrationFormType, ProfileType, SettingsType, TwoFactorSetupType
    Service/        UserDeletionService (+ UserDeletionHookInterface)
  templates/
    base.html.twig
    partials/       _head, _nav, _footer
    pages/          about, join, privacy, terms, licenses, coverage, developers,
                    contributors, regions, region, index
    map/            index.html.twig (shell)
    security/       login, register, reset_password_request, reset_password,
                    2fa_setup, 2fa_login
    profile/ settings/ contribute/ moderate/
    bundles/TwigBundle/Exception/  error404.html.twig
  migrations/
  tests/            smoke (each route 200 + has nav/footer); auth (register→verify→login, 2FA, role gating)
```

---

## 5. Shared chrome (improvement folded in)

Today the nav is built client-side by `nav.js`. Migrating, **render nav + footer as Twig partials** (`_nav`, `_footer`, `_head`) driven by a single nav-items source — removes the flash-of-no-nav, improves SEO/a11y. `a11y.js`/`quicknav.js` remain as progressive-enhancement JS. The `version.js` marker becomes a **Twig global** from one source (a `VERSION` constant / env), so every page shares it. No other refactoring of the prototype's markup beyond what the Twig port requires.

---

## 6. Page mapping (22 pages)

| Bucket | Pages | Target |
|---|---|---|
| Content/marketing (~12) | index, about, join, contributors, developers, coverage, regions, region, privacy, terms, licenses, 404 | `templates/pages/*.html.twig`; zero-logic pages use Symfony's built-in template route (no controller). |
| Map (1, 1,999 lines) | map.html | `templates/map/index.html.twig` (HTML shell + drawer markup) **+** inline JS extracted to `assets/map/*.js`. Behavior identical; migrated in isolation with a visual parity check. Highest-risk single unit. |
| Auth/profile (4) | login, profile, settings (+ register, reset, 2fa pages are new) | Twig + **functionally wired** to the `User` table. |
| Contribution/moderation (~6) | add-climb, improve, vote, contribute, moderate, pages | Templated + **auth-gated**. POST handlers that persist domain data (a climb, a vote) are **stubbed/deferred** — that needs the data API (later spec). Boundary marked explicitly in code. |

> **Superseded for K (2026-07-08):** Vote persistence is no longer stubbed on the route side — typed seasonal route votes persist as `route_vote` rows (one per user/route/season, timestamped, carrying bike type), per the route-domain design.

---

## 7. Auth foundation (fresh build, patterns lifted from `bikecoderslife/bundle`)

### 7.1 `User` entity (single entity, role-based — no separate `AdminUser` table)

The reference bundle splits `User`/`AdminUser` because in the multi-tenant Upstream Platform platform, operators are an organizationally separate population (separate table, firewall, username login, mandatory 2FA). CyclingCommons is the opposite: curators are **trusted riders, not a staff class** (per the commons/Ostrom governance) — a rider *becomes* a curator by earning `ROLE_CURATOR`, keeping one identity, profile, and contribution history (which matters for provenance). So: one `User`, identified by email, with roles `ROLE_USER` → `ROLE_CURATOR` → `ROLE_ADMIN`. Hard operator-isolation (a separate login domain) is **deferred** — added only if a concrete need appears; 2FA-on-elevated-roles + lockout cover the near-term risk.

Fields:
- Identity/auth: `id`, `uuid`, `email` (unique), `password` (hash), `roles` (json), `displayName`, `createdAt`, `updatedAt`
- Email verification: `emailVerified` (bool), `emailVerifiedAt` *(via `symfonycasts/verify-email-bundle` — signed URLs, may not need a stored token column)*
- Password reset: handled by `symfonycasts/reset-password-bundle` (its own `ResetPasswordRequest` entity) — no hand-rolled token columns
- 2FA: `twoFaEnabled` (bool), `totpSecret` (nullable), `backupCodes` (json)
- Brute-force lockout (pattern from `AdminUser`): `failedLoginAttempts` (int), `lockedUntil` (nullable), `isLocked()`
- Governance: `publicProfile` (bool, opt-in — provenance-not-identity)
- Account deletion (pattern from bundle): verification-code + cooldown, via `UserDeletionService`

Implements `UserInterface`, `PasswordAuthenticatedUserInterface`, `TwoFactorInterface`, `BackupCodeInterface`. TOTP config mirrors the reference: `new TotpConfiguration($secret, ALGORITHM_SHA1, 30, 6)`.

### 7.2 Security wiring (`security.yaml`, adapted from the reference shape)

- `password_hashers: App\Entity\User: 'auto'`
- One entity provider on `User.email`.
- A single `main` firewall (no separate `/admin` firewall): `form_login` (CSRF on), `logout`, `remember_me`, and `two_factor` (`auth_form_path: 2fa_login`, `check_path: 2fa_login_check`).
- `access_control`:
  - `^/(login|register|reset-password|verify)` → `PUBLIC_ACCESS`
  - `^/(profile|settings)` → `ROLE_USER`
  - `^/moderate` → `ROLE_CURATOR`
  - `^/admin` → `ROLE_ADMIN`
- EasyAdmin dashboard mounted under `/admin` (gated `ROLE_ADMIN`) for user/account administration; the curator moderation queue is a separate page at `/moderate` (gated `ROLE_CURATOR`).

### 7.3 2FA flow (pattern from `docs/auth/2FA_SETUP.md`)

- TOTP via scheb; **optional for `ROLE_USER`, enforced for `ROLE_CURATOR`/`ROLE_ADMIN`**.
- `LoginSuccessHandler`: if an elevated-role user has no `totpSecret`, redirect to `/2fa/setup` (QR + manual code) before granting access.
- Setup page shows QR + manual entry; verify a 6-digit code to confirm; on confirm, generate **backup/recovery codes** (`scheb/2fa-backup-code`) and show once.
- Future logins prompt for the TOTP code (or a backup code) at the `2fa_login` interstitial.

### 7.4 Roles

`ROLE_USER` (default) / `ROLE_CURATOR` (moderation) / `ROLE_ADMIN` (full admin). **Per-region curator subsidiarity is deferred** — a coarse `ROLE_CURATOR` gate now; a per-region Security Voter in a later spec.

### 7.5 Admin / moderation UI

**EasyAdmin 4** (consistent with the reference bundle) provides the `/admin` backend (gated `ROLE_ADMIN`) for user/account administration. The curator review queue (`moderate.html`) becomes a **custom Twig page at `/moderate`**, built in the site's own branded UI (same `base.html.twig`, with map / photo / item context) rather than a generic admin panel — curators are community riders and review *within the product*, not in a back-office tool. Gated `ROLE_CURATOR`, with approve / reject / needs-info actions. EasyAdmin's generic CRUD is poorly suited to "review this proposed climb with its route on a map", so it's reserved for dry record/user admin. **In this phase the page and queue UI are built; the approve/reject persistence is stubbed until the data API exists.**

> **Superseded for K (2026-07-08):** The stubbed-persistence caveat is past — item moderation landed with real persistence (Phase B), and the `/moderate` shell now gains a dedicated Routes queue whose approve/reject/retire decisions persist real `RecommendedRoute` state transitions.

### 7.6 Mail

`symfony/mailer`: **Mailpit** in the docker dev stack; prod SMTP via env (`MAILER_DSN`). Required by email verification + password reset.

---

## 8. Assets & secrets

- All CSS/fonts/brand/media/JS and `*-osm.js`/`*-data.js` fixtures move into `assets/`, served + versioned by AssetMapper.
- The Mapillary token currently in `config.js` moves to **env-injected Twig** (out of any committed asset). `config.example.js` stays as the documented template. **Real `config.js` / `.env.local` are never read** during the migration.
- Brand/logo assets owned by another contributor are **not** committed as part of this work; they are staged explicitly if at all.

---

## 9. Testing

- **Smoke tests** (`WebTestCase`): every route returns 200 and contains the shared nav + footer.
- **Auth tests**: register → email-verify → login; password-reset request → reset; 2FA setup + login-with-TOTP + login-with-backup-code; lockout after N failed attempts; `/moderate` rejects non-curators; `/profile` redirects anonymous.
- Map shell: a minimal render assertion (the heavy MapLibre behavior is verified visually during its isolated migration step).

---

## 10. Phasing

0. **Bootstrap** — bump PHP 8.4 / Symfony 7.4; add Twig, AssetMapper, Doctrine ORM, Security, scheb/2fa, verify-email, reset-password, EasyAdmin, Mailer, **`symfony/translation`**; set up `.env` placeholders + a documented required-vars list; stand up **CI** (phpunit, phpstan, php-cs-fixer, SPDX-header check) and Dependabot.
1. **Chrome + simplest pages** — `base.html.twig` + nav/footer partials + asset import; convert the simplest static pages (about, privacy, terms, licenses, coverage, 404) to validate the shell. **Extract user-facing strings to `|trans` (EN catalogue) from the first template onward.**
2. **Remaining content pages** — index, join, contributors, developers, regions, region (continuing string extraction).
3. **Map shell** — extract `map.html` → Twig shell + `assets/map/*.js`; visual parity check (isolated).
4. **Auth foundation** — `User` entity + migration; `security.yaml`; login/register/verify/reset; 2FA setup/login + backup codes; profile/settings wired; lockout; deletion service. **Auth + email strings translatable; SPDX headers on all new files.**
5. **Contribution/moderation pages** — templated + auth-gated; EasyAdmin admin + moderation queue; domain persistence stubbed. **DataFixtures + `make create-curator` command + updated README/CONTRIBUTING/Makefile.**
   > **Superseded for K (2026-07-08):** This stubbed phase is complete; the route-domain design's dedicated Routes queue — real `RecommendedRoute` state transitions plus purpose-built route tables — now builds on the shell this phase created.
6. **Cutover** — smoke + auth tests green; **`/security-review` on the auth code**; **`privacy.html` updated for account data**; **font/brand redistribution licences verified**; nginx switched to Symfony; `atlas/demo/` retired (history preserved) once parity verified.

---

## 11. Cross-cutting concerns (public / source-available repo)

CyclingCommons is **source-available (PolyForm Shield) + open data (ODbL), in a public repo** — not OSI open-source. Building in the open imposes obligations that hold regardless of the licence; these shape the implementation plan.

**11.1 Secrets (hard constraint).** The committed `.env` holds **placeholders only**; real values (`APP_SECRET`, DB password, `MAILER_DSN`, OAuth/2FA-related secrets) live in `.env.local` (gitignored) or deployment secrets. The Mapillary token leaves `config.js` for env-injected Twig (§8). A documented required-vars list ships so contributors can run locally without any secret. No secret value is ever read into the working session.

**11.2 SPDX headers on every new file.** Each new PHP/Twig/JS file carries the project header — `LicenseRef-PolyForm-Shield-1.0.0` for code, `ODbL-1.0` for data fixtures — matching the existing convention. A CI check rejects files without one.

**11.3 Privacy / GDPR.** Real accounts now store email + password hash + 2FA secret + IP/login logs — personal data. `privacy.html` is updated to reflect account-data processing (the platform can no longer imply "no personal data"). GDPR coverage: **deletion** (Art. 17) via `UserDeletionService` (in scope); **data export** (Art. 20) noted for a near-term follow-up; email is consent-/transaction-scoped. We do **not** depend on the proprietary `GdprComplianceBundle`.

**11.4 Redistributable assets.** Moving `fonts/`, `brand/`, `media/` into `assets/` publishes them; this is only lawful with redistribution rights. **Web-font licences are verified before bundling**; anything without redistribution rights stays out of the public repo (served from a licensed CDN or replaced).

**11.5 Security is public.** Attackers read the auth code, so it must be correct by construction — argon2id hashing, CSRF, secure session/cookie flags, rate-limiting, plus the 2FA + lockout above; no security-through-obscurity. A `/security-review` pass on the auth code precedes go-live; Dependabot watches the new PHP deps; `SECURITY.md` already defines disclosure.

**11.6 i18n from the start.** `symfony/translation` is wired in phase 0 and user-facing strings are extracted to `|trans` (EN catalogue) **during** the Twig port — far cheaper than a later re-port. FR/NL catalogues are added when ready (wiki is already EN+FR). Auth and email strings are translatable too.

**11.7 Contributor DX + CI.** Contributors must be able to run it: DataFixtures + a `make create-curator` console command + updated `README`/`CONTRIBUTING`/`Makefile` for the new app. CI on every PR runs `phpunit` (smoke + auth), `phpstan`, `php-cs-fixer`, and the SPDX-header check — gating community contributions ahead of the existing staging/prod deploy workflows.

**11.8 Decision transparency (ADRs).** The big calls (MediaWiki-no, Symfony, fresh-auth-not-bundle, single-`User`) are recorded as short ADRs alongside these specs in `docs/specs/`, linked from one "Architecture / decisions" page in the wiki — specs stay in-repo and public, the curated wiki is not bloated with dated implementation detail.

---

## 12. Out of scope / YAGNI (deferred to later specs)

- No JS framework (React/Vue) — AssetMapper + vanilla, as today.
- No query/contribution **data API** or domain entities (climbs, votes, hazards, surfaces).
- No **real contribution persistence** (add-climb/vote/improve POST handlers are stubs).
- The map **view-mode toggle** stays a presentation-only **Best-of / Everything** switch until the data API exists. When votes + verification land, it must be **driven by votability** (verify → votable → best-of), *not* by a static `cur` flag, and utility/coverage types must never be "best-of" — see [edit-items lifecycle & votability](edit-items/README.md#item-lifecycle-and-votability). (Demo labels already corrected — no longer "Curated".)
  > **Superseded for K (2026-07-08):** Route domain v1 supersedes this bullet and the two data-API/persistence bullets above on the route side: route entities now exist (`route_vote` / `route_ride` / `route_suggestion`), proposals persist as `RecommendedRoute` state `submitted` (and `/improve` actively refuses `type=K` rather than stubbing it), and the verify → votable → best-of funnel becomes the `submitted → unverified → verified` (+ `retired`) state machine — voting opens only at `verified`, with best-of computed per (region, season, bike type), so the Best-of view can be data-driven for K.
- No per-region curator **Voter** (coarse `ROLE_CURATOR` now).
- No genericization of `bikecoderslife/bundle`; no shared dependency; Upstream Platform untouched.
- No email-at-rest encryption (the reference bundle's disabled, infra-specific feature).
- Only the **EN** translation catalogue now; FR/NL catalogues added in a later pass (the `|trans` keys are put in place now).
- No **GDPR data-export** (Art. 20) endpoint yet — near-term follow-up, not this phase.

---

## 13. Open risks / watch-items

- **Map extraction** is the largest single unit (1,999 lines); risk of behavioral drift. Mitigation: migrate in isolation (phase 3), visual parity check, keep `atlas/demo/map.html` until verified.
- **AssetMapper vs the existing global-`<script>` data fixtures**: the `*-osm.js` files assign globals; confirm they load correctly under AssetMapper's importmap (may need to keep them as plain `<script>` includes rather than ES modules initially).
- **`api/` naming** becomes slightly misleading once it serves the whole site; flagged for a later rename.
- **2FA enforcement timing** for curators: enforce at login-success redirect vs. at the access-control layer — decide during phase 4 (login-success redirect is the reference pattern).
