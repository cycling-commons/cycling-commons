# HTML → Symfony migration (presentation + auth foundation) — design

**Date:** 2026-06-26
**Status:** Approved design, pending spec review → implementation plan
**Scope of this spec:** Move the static `atlas/demo/` prototype into the Symfony app as server-rendered pages, and stand up a real authentication foundation (accounts, login, registration, email verification, password reset, 2FA, roles). The query/contribution data API and domain entities (climbs, votes, hazards…) are **explicitly out of scope** here.

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

0. **Bootstrap** — bump PHP 8.4 / Symfony 7.4; add Twig, AssetMapper, Doctrine ORM, Security, scheb/2fa, verify-email, reset-password, EasyAdmin, Mailer.
1. **Chrome + simplest pages** — `base.html.twig` + nav/footer partials + asset import; convert the simplest static pages (about, privacy, terms, licenses, coverage, 404) to validate the shell.
2. **Remaining content pages** — index, join, contributors, developers, regions, region.
3. **Map shell** — extract `map.html` → Twig shell + `assets/map/*.js`; visual parity check (isolated).
4. **Auth foundation** — `User` entity + migration; `security.yaml`; login/register/verify/reset; 2FA setup/login + backup codes; profile/settings wired; lockout; deletion service.
5. **Contribution/moderation pages** — templated + auth-gated; EasyAdmin admin + moderation queue; domain persistence stubbed.
6. **Cutover** — smoke + auth tests green; nginx switched to Symfony; `atlas/demo/` retired (history preserved) once parity verified.

---

## 11. Out of scope / YAGNI (deferred to later specs)

- No JS framework (React/Vue) — AssetMapper + vanilla, as today.
- No query/contribution **data API** or domain entities (climbs, votes, hazards, surfaces).
- No **real contribution persistence** (add-climb/vote/improve POST handlers are stubs).
- No per-region curator **Voter** (coarse `ROLE_CURATOR` now).
- No genericization of `bikecoderslife/bundle`; no shared dependency; Upstream Platform untouched.
- No email-at-rest encryption (the reference bundle's disabled, infra-specific feature).

---

## 12. Open risks / watch-items

- **Map extraction** is the largest single unit (1,999 lines); risk of behavioral drift. Mitigation: migrate in isolation (phase 3), visual parity check, keep `atlas/demo/map.html` until verified.
- **AssetMapper vs the existing global-`<script>` data fixtures**: the `*-osm.js` files assign globals; confirm they load correctly under AssetMapper's importmap (may need to keep them as plain `<script>` includes rather than ES modules initially).
- **`api/` naming** becomes slightly misleading once it serves the whole site; flagged for a later rename.
- **2FA enforcement timing** for curators: enforce at login-success redirect vs. at the access-control layer — decide during phase 4 (login-success redirect is the reference pattern).
