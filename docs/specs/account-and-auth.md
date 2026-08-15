<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Account & Authentication

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This document defines the identity and authentication contract of Cycling
Commons: the single-User model and role ladder, registration/verification/reset,
login throttling and lockout, the 2FA policy and its enforcement, the firewall
shape, the admin support desk (audit, guardrails, removal semantics), the public
rider profile, the account shell and settings surfaces, display-name identity,
rider preferences, and GDPR deletion. Where a fact belongs to a sibling domain it
is linked, not restated: moderation/submission machinery lives in
[moderation-and-contribution.md](moderation-and-contribution.md), the route (K)
domain in [route-domain.md](route-domain.md), CSP/CSRF/sanitizer details in
[security-architecture.md](security-architecture.md), and map/search UX in
[map-and-search.md](map-and-search.md).

---

## 1. User model — one entity, a role ladder, no staff table

There is exactly **one account entity**, `App\Entity\User`
(`web/src/Entity/User.php`, table `users`), identified by email, with a JSON
role list:

```
ROLE_USER (implicit default) → ROLE_CURATOR (moderation) → ROLE_ADMIN (admin backend)
```

`role_hierarchy` (`web/config/packages/security.yaml`) makes `ROLE_CURATOR`
imply `ROLE_USER` and `ROLE_ADMIN` imply both. `User::getRoles()` always
appends the implicit `ROLE_USER`, and the admin role-mutation helper
(`UserAdminService::setRole()`) strips it before persisting elevated-role
changes. Registration, however, stores `['ROLE_USER']` verbatim
(`RegistrationController`), so self-registered rows carry it in the `roles`
JSON — the invariant is behavioural (`getRoles()`), not a storage guarantee.

**Why no separate AdminUser/staff table:** curators are trusted riders, not an
organizationally separate staff class. A rider *becomes* a curator by earning
`ROLE_CURATOR` and keeps one identity, profile, and contribution history —
which matters for provenance. Hard operator isolation (a separate login domain)
is deliberately deferred unless a concrete need appears; mandatory 2FA on
elevated roles plus lockout cover the near-term risk. Per-area curator scoping
exists as data on the side (`moderator_area` rows — contract in
[moderation-and-contribution.md](moderation-and-contribution.md)), not as extra
roles.

### Field inventory (contract-level)

| Group | Fields | Notes |
|---|---|---|
| Identity | `id` (int PK), `uuid` (UUIDv7, table-level unique constraint `uniq_users_uuid`), `email` (unique), `displayName` (§9 — deliberately **not** unique), `country` (nullable FK, `SET NULL`), `locale` (nullable; null = follow switcher/browser) | `uuid` is assigned in the `PrePersist` callback and is the only identifier ever exposed publicly (§7) |
| Auth | `password` (hash, `auto` hasher), `roles` (json) | |
| Email verification | `emailVerified`, `emailVerifiedAt` | token flow is signed-URL (§2), no stored token column |
| 2FA | `twoFaEnabled`, `totpSecret` (encrypted at rest, §4), `backupCodes` (json, keyed hashes) | |
| Lockout | `failedLoginAttempts`, `lockedUntil`, `isLocked()` | §3 |
| Deletion | `deletionCode`, `deletionRequestedAt` | §10 |
| Governance | `publicProfile` (bool, opt-in, default false) | §7 |
| Preferences | `bikeTypes` (json), `ridingStyles` (json), `defaultMapMode` (string, default `auto`; `auto \| everything \| confirmed \| curated`), `dateFormat`/`timeFormat` (string, default `auto`), `distanceUnit` (string, default `km`), `elevationUnit` (string, default `m`) | §9 |
| Age (GDPR Art. 8) | `ageConfirmedAt` (nullable datetime — when they declared 16+; NULL = predates the gate, or created by an admin/console path) | §2; deliberately **not** a date of birth |
| Media | `keepMediaCredit` (bool) — the departing rider's credit choice, read at deletion | photo-uploads.md §6 |
| Base location (optional, account-private) | `basePoint` (geometry GeoJSON Point, coords rounded to 2dp at write — ~1 km precision), `basePlace` (varchar(120), town-level label for the scope line), `baseRadiusKm` (smallint, default 40, clamped [10,150]), `baseRegionIds`/`baseCountryCodes` (json, derived — `App\Service\BaseAreaResolver`, cap 8) | map-and-search.md §4.5 Phase 4; **never** exposed on the public profile (§7 below); no GIST index (nothing queries users spatially) |
| Audit | `createdAt`, `updatedAt` (lifecycle callbacks) | there is **no** `lastActiveAt` — required by the unbuilt inactivity lifecycle (§6.6) |

The entity implements `UserInterface`, `PasswordAuthenticatedUserInterface`,
scheb's `TwoFactorInterface` (TOTP) and `BackupCodeInterface`. Validation
(email format/length and uniqueness, display-name format — §9) sits **on the
entity**, not on individual forms, so every write path — registration form,
settings form, console commands, admin CRUD, fixtures — is covered.

Privacy consequence (standing rule): the platform **does** hold personal data —
email, password hash, encrypted 2FA secret, login metadata. Public copy must
never claim otherwise; the *dataset* being non-personal is a separate claim.

That separate claim is kept true deliberately, not by luck: the coverage cache
drops OSM contact email addresses at ingest precisely because a sizeable share
of them are private mailboxes rather than business role addresses
([osm-data-architecture.md §5](osm-data-architecture.md),
[coverage-provider.md §2.1](coverage-provider.md)). If a future change starts
storing personal data in the POI dataset, this standing rule and the public copy
both have to move with it.

## 2. Registration, email verification, password reset

All built on permissive MIT libraries — `symfony/security-bundle`,
`symfonycasts/verify-email-bundle`, `symfonycasts/reset-password-bundle`,
`scheb/2fa-bundle` (+totp, +backup-code), `easycorp/easyadmin-bundle`. A
proprietary in-house auth bundle (private, unnamed here) was a **pattern
reference only**: never a dependency, no code copied.

**Registration** (`App\Controller\RegistrationController`):

- Fields: email, display name (2–100 chars, `RegistrationFormType`), repeated
  password (**min 12 chars**, `Length(min: 12)` in
  `web/src/Form/RegistrationFormType.php`), an **age declaration** and an
  agree-terms checkbox. Preferences are *not* asked at registration —
  friction-free by design (§9).

**The age gate (GDPR Art. 8).** Art. 8 gates consent-based processing of a
child's data at 16, which member states may lower to 13. The Commons uses a
**flat 16 for everyone** (owner decision 2026-08-01): 16 is the Article's
ceiling, so it never sits below any member state's own floor, and one rule
avoids inferring a rider's country in order to decide which rule applies to
them.

It is **self-declared, and deliberately not a date of birth**. Art. 8(2) asks
for *reasonable efforts* given available technology, and for a service like
this one that is a declaration; collecting a birthday to answer a yes/no
question would store more personal data than the question is worth
(Art. 5(1)(c)). The declaration is stored as `users.age_confirmed_at` — a
timestamp, because null/not-null already carries the boolean and the *when* is
the part worth keeping.

Enforced by an unmapped `IsTrue` constraint on the form, so an unticked box is
a 422 with the rest of the input preserved. **Existing accounts keep NULL**:
they registered before the gate existed, and back-filling a declaration nobody
made would be a record of something that never happened.
- New accounts get `['ROLE_USER']` and `emailVerified = false`.
- Duplicate email is caught twice: `UniqueEntity` on the entity, and a
  TOCTOU catch of `UniqueConstraintViolationException` at flush that re-renders
  the same duplicate-email form error as a 422 instead of a 500.
- A verification mail is sent (verify-email bundle, signed URLs — no stored
  token). `/verify/email` validates the signature and flags
  `emailVerified`/`emailVerifiedAt`, then redirects to login.
- **Journey continuity (verified end-to-end):** the firewall's
  saved target path survives the whole register → verify → login detour in
  one session, because registration and verification never touch it and
  `LoginSuccessHandler` honours it after authentication. An anonymous visit
  to a gated page (e.g. `/join/BE`,
  [moderation-and-contribution.md](moderation-and-contribution.md) §11)
  therefore lands back on that page after account creation — no re-navigation
  needed.

**Password reset** (`App\Controller\ResetPasswordController`, reset-password
bundle with its own `ResetPasswordRequest` entity):

- No account enumeration: an unknown email redirects to the same check-email
  page, and a direct visit to check-email renders a **fake token object** so
  timing/content cannot reveal existence.
- The token is moved from the URL into the session on arrival (prevents
  Referer leakage), consumed before the new password is persisted.
- **Completing a reset clears any brute-force lock** (`lockedUntil = null`,
  counter reset) — the reset flow is the owner's recovery path out of a
  lockout DoS (§3).
- Repository throttling in the bundle prevents reset-request floods; stuck
  rows are visible/purgeable via the admin diagnostics CRUD (§6.5).

**Mail:** `symfony/mailer`; Mailpit in the dev docker stack, prod SMTP via the
`MAILER_DSN` env var. Sender identity is `noreply@cyclingcommons.org`.
All transactional emails (verification, password reset, account-deletion
code) extend one branded shell, `templates/emails/_base.html.twig`
Email templates use email-client-safe markup only — presentation tables + inline
styles, paper backdrop, ink header band carrying the wide wordmark
(`assets/brand/logo-email.png`, a PNG render of the nav SVG since mail
clients strip SVG), orange action button. Copy lives in the extending
templates; the shell owns layout and the footer.

## 3. Login throttling and account lockout

Two complementary layers:

1. **Per-IP throttle** — Symfony's built-in `login_throttling`,
   `max_attempts: 5` (`web/config/packages/security.yaml`). Covers
   many-accounts-from-one-address attacks.
2. **Per-account hard lock** — `App\Security\LoginThrottleListener`:
   - `LOCKOUT_THRESHOLD = 5` failed attempts →
   - `LOCKOUT_MINUTES = 15` lock (`lockedUntil = now + 15min`).

Lock semantics (all in `LoginThrottleListener`):

- A locked account is rejected at `CheckPassportEvent` **before password
  verification** — the correct password does not bypass the lock.
- Failures **while locked never count and never re-arm the window** — the
  advertised cooldown genuinely elapses (otherwise repeated attempts would be a
  permanent DoS).
- A failure after an *expired* lock starts a fresh window (stale counter
  reset first).
- Successful login resets both fields (flush skipped when already clean).
- No user-enumeration leak: unresolvable identifiers are silently ignored.

Accepted residual: an attacker who knows a victim's email can trip a 15-minute
lock with 5 bad passwords. This is inherent to account-scoped locking; it is
bounded (window elapses, password reset clears it, admin Unlock exists). A
CAPTCHA/IP-diversity step-up before hard-locking is a recorded product decision
for a dedicated change, not built.

## 4. Two-factor authentication

### Policy

2FA is **optional for `ROLE_USER`, mandatory for `ROLE_CURATOR` and above**
(admins included via the role hierarchy). The rule lives in exactly one place:
`App\Security\TwoFactorPolicy`:

- `isMandatoryFor(User)` — `ROLE_CURATOR` reachable in the user's role set
  (role-hierarchy aware, so `ROLE_ADMIN` qualifies).
- `requiresSetup(User)` — mandatory **and** not *fully enrolled*.

**Fully enrolled** is defined by `User::isTotpAuthenticationEnabled()`:
`twoFaEnabled && totpSecret !== null` — a secret alone is not enrolment.
Invariant for fixtures and seeded elevated accounts: set **both**
`totpSecret` and `twoFaEnabled(true)`, or the account loops to `/2fa/setup`
(dev seed secret `JBSWY3DPEHPK3PXP`).

### Enforcement — two cooperating layers

1. `App\Security\LoginSuccessHandler` (the `main` firewall's
   `success_handler`): after full authentication, an elevated user for whom
   `requiresSetup()` is true is redirected to `/2fa/setup` (localized). Users
   mid-2FA (`TwoFactorTokenInterface`) are sent to the scheb interstitial.
   Also applies the user's saved locale to the session and localizes the
   default target so the path-prefix router cannot clobber it.
2. `App\Security\TwoFactorSetupEnforcer` (`kernel.request` listener,
   priority 7): closes the remember-me / direct-navigation gap — a
   not-fully-enrolled elevated user is redirected to `/2fa/setup` on **every**
   main request. Path bypasses run **before any token read** (an eager
   `getToken()` would boot the lazy firewall + session and destroy the
   cacheability of public endpoints): `/2fa*`, `/_wdt`, `/_profiler`,
   `/assets`, `/map`, `/routes/`, `/items/` (`BYPASS_PREFIXES` in the class),
   plus the setup route itself and `/logout` (always reachable).
   2FA-in-progress tokens are never touched — scheb owns the interstitial.

### Enrolment flow (`App\Controller\TwoFactorController`, `/2fa/setup`)

- The freshly generated secret lives **in the session**
  (key `2fa_pending_secret`), *not* on the entity, until the user proves the
  scan by entering a valid code — only then are secret + `twoFaEnabled`
  persisted. The pending secret is reused across GET/POST so the scanned QR
  stays valid; QR rendered as inline SVG data-URI (no GD/Imagick dependency).
- On confirmation, **backup codes** are issued:
  `TwoFactorController::BACKUP_CODE_COUNT = 8`, each 80 bits of entropy
  (10 random bytes as grouped hex). Shown exactly once; stored only as keyed
  hashes.
- Interstitial login: scheb's `two_factor` firewall entry
  (`auth_form_path: 2fa_login`, `check_path: 2fa_login_check`); TOTP or a
  single-use backup code.

### Secret material at rest

- `totpSecret` uses the custom Doctrine type `encrypted_string`
  (`App\Doctrine\EncryptedStringType`, registered in
  `web/config/packages/doctrine.yaml`): AES-256-GCM, key derived from
  `APP_SECRET` via HKDF-SHA256, stored as `base64(iv || tag || ciphertext)`.
  A database-only leak does not expose authenticator seeds.
- Backup codes are stored as `HMAC-SHA256(code, HKDF(APP_SECRET))`
  (`User::hashBackupCode()`) — a DB-only leak cannot even compute candidate
  hashes.
- Shared trade-off: rotating `APP_SECRET` invalidates stored TOTP secrets and
  backup codes (affected users re-enrol).
- TOTP parameters: SHA1, 30 s period, 6 digits
  (`User::getTotpAuthenticationConfiguration()`); issuer `Cycling Commons`
  (`web/config/packages/scheb_2fa.yaml`).
- The 2FA setup page carries a "Settings · Security" breadcrumb back-link and
  its post-enrolment Done button targets `/settings?tab=security` (§8).

Admin recovery: the support desk's **Disarm 2FA** action (§6) clears secret +
codes and disables the flag — audited, confirm-gated.

## 5. Firewall and access-control shape

`web/config/packages/security.yaml` — a **single `main` firewall** (lazy):

| Element | Value |
|---|---|
| Provider | entity provider on `User.email` |
| Hasher | `auto` (argon2id-class) |
| `form_login` | CSRF on, `success_handler: App\Security\LoginSuccessHandler` |
| `logout` | CSRF on, target `home` |
| `remember_me` | lifetime `604800` (7 days), `samesite: lax`, `secure: auto` |
| `/login` shortcut | fires on **`IS_AUTHENTICATED_FULLY`**, never on `getUser()` (below) |
| `/join/{cc}` | `ROLE_USER`; password re-confirmed **on submit** when the session is only remembered (below) |
| `login_throttling` | `max_attempts: 5` (§3) |
| `two_factor` | scheb interstitial (`2fa_login` / `2fa_login_check`) |

`access_control` (ordered; localized routes carry an optional `/fr|/nl|/de`
prefix — English paths are clean; `logout`, `verify`, `/2fa` interstitial,
`/api`, `/admin` stay unprefixed):

| Path pattern | Access |
|---|---|
| `(/fr\|/nl\|/de)?/(login\|register\|reset-password)` | `PUBLIC_ACCESS` |
| `^/verify` | `PUBLIC_ACCESS` |
| `(/fr\|/nl\|/de)?/riders/` | `PUBLIC_ACCESS` (public rider profiles, §7) |
| `^/map/catalog\.json$`, `^/map/best-of$`, `^/map/item/\d+/history$`, `^/routes/\d+\.gpx$`, `^/items/\d+/confirmations$` | `PUBLIC_ACCESS` — **exact-path, explicitly public** so scheb's lazy-firewall `TwoFactorAccessListener` skips the session read that would downgrade `Cache-Control` to private (data contracts: [catalog-data-model.md](catalog-data-model.md), [route-domain.md](route-domain.md), [moderation-and-contribution.md](moderation-and-contribution.md)) |
| `(/fr\|/nl\|/de)?/2fa/setup` | `ROLE_USER` — must precede the interstitial rule: setup is reached *fully* authenticated |
| `^/2fa` | `IS_AUTHENTICATED_2FA_IN_PROGRESS` (scheb interstitial) |
| `(/fr\|/nl\|/de)?/(profile\|settings)` | `ROLE_USER` |
| `(/fr\|/nl\|/de)?/moderate` | `ROLE_CURATOR` |
| `^/admin` | `ROLE_ADMIN` |

Rule of thumb encoded above: any *cacheable public* endpoint needs an explicit
exact-path `PUBLIC_ACCESS` entry **and** (if it is not already under a bypassed
prefix) a `TwoFactorSetupEnforcer` bypass — "no rule matches" is not enough
under the lazy firewall.

**A second factor that cannot be enrolled is never demanded.**
`TwoFactorPolicy::requiresSetup()` is false when the TOTP provider is absent.
`when@dev` switches it off for local convenience
(`config/packages/scheb_2fa.yaml`), which also removes
`TotpAuthenticatorInterface` — and two things then went wrong together, locking
a newly approved curator out of the entire dev stack (owner-reported
2026-08-14): `TwoFactorController::setup()` REQUIRED that service and so
answered 500, while `TwoFactorSetupEnforcer` sent every not-yet-enrolled curator
to exactly that page on every request. The setup action now takes the service as
nullable and renders `security/2fa_unavailable.html.twig` instead, which also
states the live rule ("curators and admins must set up two-factor before they
can reach the moderation desks"). Nothing changes where the provider is on:
`when@dev` cannot reach prod or staging, and the mandate itself is untouched —
`isMandatoryFor()` still answers true for every curator.

**Re-authentication is asked for at the moment of commitment, never at the
door.** With remember-me on a 7-day lifetime, a returning rider holds a real
user object while being only `IS_AUTHENTICATED_REMEMBERED`. `/join/{cc}` (the
curator application) used to demand `IS_AUTHENTICATED_FULLY` — the only place
in the codebase that did — which sent that rider to `/login` merely to *look at*
a form. The owner's objection was the right one: "why am I going to login when I
want to apply for curation of a region when I am already logged in — this does
not make sense to a normal user." It did not, and the demand was inconsistent:
the same remembered session may change settings, set a base location, propose
places and upload photos, all `ROLE_USER`. So the page is `ROLE_USER` too, and
the password is confirmed **on submit**, only when the session is remembered:

- The field renders only for a remembered session; a fully-authenticated rider
  never sees it.
- A wrong answer writes nothing and re-renders the form with the rider's typing
  and their region selection intact — a typo costs the retry and nothing else.
- It is checked **before** the application limiter is consumed and guarded by
  `curator_reauth` (5 per 15 min) rather than `curator_application` (3 per
  **day**), because charging failed passwords against the latter would cost a
  rider their ability to apply at all.

**The login page's "already signed in" shortcut still tests
`IS_AUTHENTICATED_FULLY`, not `getUser()`.** Nothing routinely triggers it now,
but it was a genuine dead end: a merely-remembered visitor sent to `/login` was
told *"you are already signed in"* and redirected home — told they were done
while the page they asked for went on refusing them, with no way through
(reproduced by dropping the session cookie and keeping `REMEMBERME`). A
remembered rider gets the form, and the firewall's stored target path carries
them onward. This is what any future FULLY page will need. `/register` keeps the
plain `getUser()` test — somebody who is remembered does not need an account.

**Boundary rule:** EasyAdmin `/admin` (`ROLE_ADMIN`) is dry record/user
administration only. Curator content review is the branded in-product
`/moderate` shell (`ROLE_CURATOR`) — review happens within the product, never
in a back-office tool. See
[moderation-and-contribution.md](moderation-and-contribution.md).

## 6. Admin support desk (`/admin`)

EasyAdmin 5; every surface is double-gated (`^/admin` firewall rule **and**
`#[IsGranted('ROLE_ADMIN')]` on each controller).

### 6.1 Support actions

All account state changes go through `App\Service\UserAdminService` — the
generic EasyAdmin NEW/EDIT/DELETE/BATCH_DELETE actions are **disabled** on the
User CRUD (`App\Controller\Admin\UserCrudController`) precisely so no mutation
can bypass the guardrails and audit log. Actions are conditionally displayed
(`displayIf`) on the detail page:

| Action | Shown when | Effect | Confirm |
|---|---|---|---|
| Unlock | `isLocked()` | clear lock + counter | no |
| Disarm 2FA | `twoFaEnabled` | clear secret + backup codes, disable flag | yes |
| Mark email verified | `!emailVerified` | set verified + timestamp | no |
| Unverify email | `emailVerified` | clear both | yes |
| Grant curator | lacks role | add `ROLE_CURATOR` | no |
| Revoke curator | has role | remove it | yes |
| Grant admin | lacks role | add `ROLE_ADMIN` | yes |
| Revoke admin | has role | remove it (guardrails) | yes |
| Execute account removal | `deletionRequestedAt != null` | §6.3 | yes |
| Cancel pending removal | `deletionRequestedAt != null` | clear code + timestamp | no |
| Moderator areas | has `ROLE_CURATOR` | replace `moderator_area` rows (contract in [moderation-and-contribution.md](moderation-and-contribution.md)) | form page |

**Transport convention (standing rule):** every state-changing support action
is a **POST-only `#[AdminRoute]` with a CSRF token** (shared token id
`UserCrudController::CSRF_TOKEN_ID = 'ea-user-support'`; the action renders
through a custom form template instead of EA's GET link, and the handler
re-validates the token — defence in depth). Enforced by
`testEverySupportActionRouteIsPostOnly` in
`web/tests/Admin/UserAdminActionsTest.php`. The single sanctioned exception is
`moderator_areas`: a genuine intermediate form page, GET (render) + POST
(submit, same CSRF token).

**A second door onto the same grant path.** Curator applications
([moderation-and-contribution.md](moderation-and-contribution.md) §11) let a
rider apply to curate a country instead of an admin picking one from the User
CRUD. Approving one calls this exact `UserAdminService::grantCurator()` — same
method, same `grant_curator` audit row — and, in the same transaction, also
creates the requested `ModeratorArea`. No new authority model: granting
`ROLE_CURATOR` stays admin-only either way, and the application flow is scope
selection glued onto the existing grant, not a parallel one.

### 6.2 Audit trail — `AdminActionLog`

`App\Entity\AdminActionLog` (table `admin_action_log`): `actor` (nullable
User — `null` means *system*, reserved for future automated actions),
`action` (string), `targetUser` (nullable, FK `ON DELETE SET NULL` so rows
outlive removed accounts), `note`, `createdAt`. Contract points:

- Every support action writes a row via `AdminActionLogger`.
- **Mutation and audit row are one transaction** (`wrapInTransaction` in
  `UserAdminService::commit()` / `removeAccount()`): they can never diverge —
  the trail can neither miss a change nor claim a removal that rolled back.
- On removal the row is written first (target still resolvable) and the
  target's email is snapshotted into `note` for traceability after the FK
  nulls out.
- Surfaced as a read-only EA CRUD ("Activity" menu;
  `AdminActionLogCrudController` disables NEW/EDIT/DELETE/BATCH_DELETE).

### 6.3 Account removal — anonymize, not delete

Commons rule: contributed data is community-owned and **never cascade-deletes
with an account**. Removal targets personal data only.

- Admin removal (`UserAdminService::removeAccount()`) and self-service
  deletion (§10) both route through the **shared seam**
  `UserDeletionService::purge()`: run every `UserDeletionHookInterface`
  pre-delete hook, then remove the `User` row.
- **One hook implementation exists**: `App\Service\ResetPasswordCleanupHook`
  — purges the user's
  `reset_password_request` rows via the bundle's `removeRequests()` before
  the row delete. `reset_password_request.user_id` is a plain restrictive FK
  (no `ON DELETE` action — `Version20260628225933`), so without the hook
  BOTH deletion paths threw an FK violation for any user with a live reset
  request (regression pinned:
  `AccountDeletionTest::testConfirmDeletionSucceedsWithPendingResetRequest`).
  Beyond that, removal is a hard delete of the row and the public profile
  URL 404s naturally. **Specified, pending implementation:**
  as contributed-content anonymization is needed, it is written as hooks on
  this seam — contributions are dissociated and retained under an anonymous
  "former contributor" identity, never deleted.
- **Two intended `user_id` DB cascades:** `user_message` (curator/system
  correspondence *to* the recipient, not contributed content — contract in
  [moderation-and-contribution.md](moderation-and-contribution.md)) and
  `moderator_area` (scoping assignments, meaningless without the curator —
  same doc). `admin_action_log` FKs are `ON DELETE SET NULL`;
  `reset_password_request.user_id` is a restrictive FK (see above); everything
  else holds plain ids.
- Admin removal is only offered when the user has a pending self-requested
  deletion (`deletionRequestedAt` set).

### 6.4 Guardrails (baked into `UserAdminService`, not optional)

- No self-targeting for revoke-admin / remove-account (`assertNotSelf`) — an
  admin cannot lock themselves out of the panel.
- The last remaining `ROLE_ADMIN` can never be removed or demoted
  (`assertNotLastAdmin`) — the system always has ≥ 1 admin.
- Violations throw `GuardrailViolationException`, surfaced as a danger flash —
  never a 500.
- Destructive actions carry a confirmation step (table above).

### 6.5 Secret fields and diagnostics

**Hard rule — never rendered, never editable in any admin surface:**
`password`, `totpSecret`, `backupCodes`, and
`ResetPasswordRequest.hashedToken`/`selector`. The User CRUD exposes only:
email, displayName, roles, emailVerified, twoFaEnabled (read-only),
lockedUntil, publicProfile, createdAt, plus a read-only moderator-areas
descriptor on detail. Roles/emailVerified/lockedUntil display but are not
form-editable — changes go through the audited actions only.

`ResetPasswordRequestCrudController` is a read-only diagnostics list (target
user, requestedAt, expiresAt) whose only permitted mutation is a single-row
Delete to purge a stuck request; token/selector are never exposed.

The dashboard (`DashboardController` + `AdminDashboardStats`) shows only real
counts, all derived live from the `users` table (members / curators / admins /
unverified / locked / pending-removal + 10 recent signups). Honesty rule: no
fabricated or stale stats on the dashboard — a metric joins only when its
source is real.

### 6.6 Inactivity lifecycle — **Specified, pending implementation**

Designed, not built; requires a `lastActiveAt` column
that does not exist yet:

| Inactivity | Event |
|---|---|
| 12 months | notice email #1 ("log in to reset the clock") |
| 22 months | notice #2 + deletion warning |
| ~24 months − 7 days | final notice |
| 24 months | removal via the §6.3 path — personal data scrubbed, contributions anonymized & retained |

Mechanism when built: idempotent scheduled command; localized notices via
`User.locale`; automated removals write `AdminActionLog` rows with
`actor = null`; admins can reset the clock or exempt an account.

## 7. Public rider profile

`App\Controller\RiderProfileController`, route `rider_profile` =
`/{locale?}/riders/{uuid}` (UUID regex requirement, `PUBLIC_ACCESS`).

- **UUID-only in URLs** — never the integer id (non-enumerable; UUIDv7,
  unique constraint `uniq_users_uuid`).
- **Exists only while `publicProfile` is ON.** Toggle off, unknown uuid, and
  hard-deleted accounts all 404 identically. Opt-in public posture: profiles
  are public contributors only — no private/anonymous profile pages;
  provenance is kept, identity is opt-in.
- **The /contributors wall rides on the same toggle.**
  `App\Catalog\ContributorWallProvider` lists riders with `publicProfile`
  ON **and** ≥1 public contribution (approved submissions + served route
  proposals), alphabetical/non-ranked, each row linking `rider_profile`;
  per-row counts are the same figures the profile page already exposes.
  Riders without the toggle never appear regardless of volume. The page's
  three stat cards are aggregate site totals over ALL contributors
  (opt-in or not) — aggregates credit the crowd without identifying anyone.
  This replaced the demo's fake sample-handle wall.
- **"View as others see it" is the real page**: settings links the rider's own
  public URL (`target="_blank"`) with **zero owner special-casing** — what the
  owner sees is byte-for-byte what others get. When the toggle is OFF the link
  is replaced by a hint; there is no preview of a non-existent page. Settings
  is the link's only home (deliberately removed from the profile dashboard).
- **Shown** (allow-list): display name, country flag (if set), member-since
  (month + year from `createdAt`), riding-preference chips, the contribution
  counters (below), and up to **10** most recent **Verified** routes
  by name, each deep-linked to the map via `?route=` (limit is the `findBy`
  third argument in `RiderProfileController::show()`).
- **Contribution counters (built 2026-08-16, owner boundaries 2026-08-13).**
  Four tiles: *Places added* (approved `type=new` submissions), *Edits
  accepted* (approved `type=edit`), *Photos shared* (approved media whose
  objects still exist - a granted takedown deletes them and a deleted photo
  stops scoring), *On-the-spot checks* (all `item_confirmation` rows, every
  stance merged into ONE counter because three counters invite gaming the
  easiest; the seasonal waterpoint round joins this counter when it is
  built). The boundaries are editorial: **approved work only** (a pending
  counter is a spam incentive with a scoreboard), **votes stay private** (an
  opinion is not a contribution), **moderation counts stay admin-only** - per
  month x per REGION, never per moderator, on `/admin/moderation-activity`
  (`DashboardController::moderationActivity()`), and the moderator rulebook
  names that view because an openly-stated workload view is management and a
  quiet one is surveillance. All four tiles render at zero: a visible zero
  says what CAN be contributed. The headline "N accepted contributions" is
  places + edits. Pinned by `RiderProfileTest::testCountersCountApprovedWorkOnly`
  and `ModerationActivityPageTest`.
- **Pending-route count spans both pre-Verified states** — `Submitted` +
  `Unverified` — but Submitted route **names never render** (un-vetted);
  rejected submissions never appear (not Approved). State machine:
  [route-domain.md](route-domain.md).
- **Never shown:** email, IPs, locale, roles, 2FA state, base location (point,
  place, radius, derived region/country sets), or anything account-internal.
  Pinned by `RiderProfileTest::testBaseLocationNeverExposedOnPublicProfile`
  (map-and-search.md §4.5 "frozen exposure list").
- No caching contract in v1: the page is `PUBLIC_ACCESS` (lazy-firewall
  cacheability rationale, §5) but renders per-request.
- Renders in the public site chrome (`profile/public.html.twig`), not the
  logged-in account shell.

## 8. Account shell and settings

### Shell (`web/templates/account/_shell_chrome.html.twig`)

One continuous logged-in environment — dark identity bar + section tabs —
included by `/profile`, `/settings`, `/2fa/setup`, and the moderation pages.
The same account chip (`partials/_account_chip.html.twig`) is used everywhere,
including the public nav; the language switcher (`partials/_lang_menu.html.twig`)
lives in the shell header.

- **Personal mode** tabs: Contributions · Votes · Saved · Messages · Settings,
  under a "Personal" label.
- **Moderator mode** (`/moderate`, `/moderate/routes`): the bar carries **only**
  the moderation tabs (Submissions, Routes, with open counts) on its own darker
  colour under a **MODERATION** label — no user items. Curators cross between
  the two modes via the account-chip dropdown in both directions. The label
  also shows the curator's moderation scope (assigned area names, or "all").
- Dashboard **Contributions pane** renders the user's real submissions
  (retention-filtered — a rejected submission past the retention cutoff never
  renders, see [moderation-and-contribution.md](moderation-and-contribution.md))
  and route proposals, 50 each (`ProfileController`), in the shared `.item`
  row style (type/route tag, date, status pill, decision-note sub-line); empty
  state is the `account.contributions_empty` key. The pane closes with a
  **Curator applications** section: the user's own
  `curator_application` rows with status pills (pending/approved/declined/
  withdrawn), or — when none exist — a door to the regions directory, so
  "where is my request?" always has an answer on the post-login landing.
  The **Votes** pane renders both kinds of backing act the platform actually
  persists — the user's own `route_vote` ballots (the only surface that shows
  *what* was voted for, voter-only — [route-domain.md](route-domain.md) §6)
  and their `item_confirmation` place confirmations with stance pills
  (potable / not potable / still there —
  [moderation-and-contribution.md](moderation-and-contribution.md) §1.6);
  the `/vote` category ballots are receipt-only by design and so never appear
  here. The **Saved-regions** pane says plainly that saving is not built yet
  and links the regions directory. The former preview sample data is gone
  Every dashboard pane renders real rows only. Empty panes use
  the shared `.empty-state` block (`account/_shell_styles.html.twig`):
  centred message plus a bordered door link, with `.dbody`/`.dmin` holding a
  46vh minimum so sparse account pages keep their vertical shape.

### Settings (`App\Controller\SettingsController`, `/settings`)

Two tabs:

- **Profile** (default): account status; Identity section (display name,
  country, language, base location); Riding preferences (preference chips,
  account-and-auth.md §9); Public profile section (toggle + view-as
  link/hint — split out of the former "Identity & privacy" heading
  2026-07-21); the profiles-opt-in notice.
- **Security**: password change, 2FA block, danger zone (account deletion §10).

Contract points:

- **Server picks the initial tab**: `?tab=security`, or a failed password-form
  submission (the 422 re-render must show the tab holding the errors);
  everything else defaults to Profile. Pane switching itself is client-side.
- Password-change and both deletion-flow redirects target
  `settings?tab=security` so flashes land on the visible tab.
- Password change requires the **current password** (checked against the
  hasher) before the new one is accepted.
- **Email is read-only** in settings with a contact-support note — an email
  change would require re-verification (EmailVerifier token flow); the
  simple, honest option is documented in the controller docblock.
- Saving settings applies the (possibly changed) locale to the session
  immediately; clearing it falls back to browser/site default.
- Preference checkbox groups use the **chip-check pill pattern** (label wraps
  the input; `:has(input:checked)` drives the active look) — the established
  styling shared with the propose-route form.

### Support playbook: manual email-change requests

This playbook is mirrored as an admin page: **/admin → Playbooks → Email
change** (`DashboardController::emailChangePlaybook`,
`admin/playbook_email_change.html.twig`) so the script sits in front of the
operator executing the change — this section stays the canonical text; keep
the two in sync. The menu section is deliberately plural: future operator
playbooks slot in beside it.

A manual flow is only safer than self-serve if support actually verifies —
otherwise it is the same account-takeover vector with a human rubber stamp.
"Legit" means proving control of the account's **existing anchors**; this
platform has exactly three: the old mailbox, the password, and (when
enrolled) the TOTP factor. Whoever handles the `info@` mailbox follows this
script, in order:

1. **Never trust the request mail itself.** From-headers are spoofable, and
   "writing from my new address because I lost the old one" is the standard
   opening of an attack. Request content proves nothing — display name,
   contributions and join date are all public on rider profiles.
2. **Anchor 1 — the old mailbox:** reply to the address **on file** (typed
   from the admin panel, never reply-to) with a one-time confirmation code
   and require it back. If they can receive there, the change is low-risk.
3. **Anchor 2 — a logged-in session:** if the old mailbox is claimed dead,
   dictate an in-account action ("set your riding radius to 120 km", "paste
   this code into your display name for an hour") and verify it happened.
   That proves password possession — and for 2FA-enrolled accounts it
   implicitly proves the TOTP factor too, since login required it.
4. **No anchor left** (can't receive at the old address AND can't log in) =
   account recovery, not an email change, and there is no honest way to
   distinguish owner from attacker. The safe answer is "create a new
   account". Refusing here is the point of the manual flow, not a support
   failure.
5. **After verifying, still hedge:** notify the old address with a
   "this wasn't me" contest window and delay execution 48–72 h; record the
   change through the audited admin path (the `UserAdminService` audit-note
   pattern of the account-support desk, account-and-auth.md §6) so there is
   a trail.

Rule of thumb: **urgency is a red flag, never a reason to skip a step** —
the legitimate owner survives a 48-hour delay; the attacker's window
usually doesn't.

## 9. Display-name identity and rider preferences

### Display names are labels, not identifiers

**Display names are NOT unique.** Two riders may both be called John Doe,
because two riders genuinely are. Refusing the second amounts to telling
somebody their own name is a stranger's property, and a name is exactly the
field a rider is most likely to want their real one in. The `uuid` is the
identity, and always was.

- No uniqueness constraint, no `UniqueEntity`, no canonical shadow column.
  They were removed together: keeping a canonicalized copy around would keep
  implying that names identify accounts.
- Nothing looks a rider up by name. Display names are read for display and
  never used as a key, so non-uniqueness costs no lookup anywhere.
- **Disambiguation is the `uuid`'s job, and every surface already uses it.**
  The public profile is `/riders/{uuid}`; the attribution link embedded in
  contributed photos points at a uuid-keyed page
  ([photo-uploads.md](photo-uploads.md) §1.3c, §5d); moderation is pseudonymous
  and never shows a curator's name at all. Admin lists that show a name show
  the email beside it.
- A name is **stored exactly as typed** — `setDisplayName()` does no
  normalization of any kind.

### What a display name may look like

Not being an identifier does not make the field a free-for-all: it renders in
photo credits, on public profiles and in admin lists. Four rules, and **all of
them live on the `User` entity**, not on the two form types, so admin CRUD,
console commands and fixtures are held to them too — a rule only a form
enforces is a rule an administrator walks straight past.

| rule | constraint | rejects |
|---|---|---|
| length ceiling | `Assert\Length(max: 100)` | over-long names |
| no confusables | `Assert\NoSuspiciousCharacters` (en/fr/nl/de/es) | mixed-script lookalikes (`Jоhn` with a Cyrillic о) |
| no invisibles | `Assert\Regex('/\p{Cf}/u', match: false)` | zero-width and other format characters |
| plain spelling | `App\Validator\PlainDisplayName` | non-U+0020 whitespace (non-breaking, ideographic), doubled or edge spaces, and Unicode compatibility forms (fullwidth) |

`PlainDisplayName` is a constraint class rather than another regex because its
compatibility check is NFKC idempotence, which no pattern can express.

**`NotBlank` and the two-character floor stay on the forms**, deliberately.
Requiring a name is a rule about humans filling in a form; unnamed rows are a
supported state that fixtures and partial flows rely on. Symfony's
`LengthValidator` skips `null` but not `''`, which is why the minimum cannot
live on the entity beside the maximum.

### Preference vocabularies

- `App\Catalog\RidingStyle` — 7-case, **style-only** string enum: Road,
  Gravel, Touring, Bikepacking, Trail, Urban, Leisure. **This enum is the
  contract for the map's Discipline chips** (consumed by
  [map-and-search.md](map-and-search.md)); hardware is deliberately excluded.
- `App\Catalog\BikeType` — the shared 8-case hardware enum owned by the route
  domain ([route-domain.md](route-domain.md) §8.3, including the
  General/Specialty split), reused here for the rider's own declaration.
  Specialty/accessibility semantics (`BikeType::isSpecialty()`) belong to
  route ranking, not accounts.
- Storage: two JSON columns on `users` (`bike_types`, `riding_styles`),
  default `[]`. Entity accessors are enum-typed and **silently drop unknown
  stored strings on read** (`tryFrom` + filter) — a future enum rename can
  never fatal a page render; setters dedupe.
- Preferences live on the **Settings page only**; registration and the public
  profile form are untouched (the public *page* renders the chips, §7).
  Map prefiltering reads these values — contract in
  [map-and-search.md](map-and-search.md).

### Date notation

`App\Account\DateFormat` — `auto | ymd | dmy | mdy | long`, stored on
`users.date_format`, default `auto`.

**A separate preference from language, deliberately.** The two are genuinely
independent: plenty of people read a site in English and still expect
`01-08-2026`, and `2026-08-01` reads as a filename to most of Europe. Deriving
the format from the interface language would give those riders no way to say
so. `auto` is the default and means "whatever suits the language I am reading";
the other four are explicit and mean the same thing in every locale — the
pattern is fixed, only month **names** localise.

`App\Account\TimeFormat` — `auto | h24 | h12`, on `users.time_format`, is a
**second, independent** preference. An earlier version derived it from the date
order (month-first implies twelve-hour), which was tidy reasoning and wrong for
real people: someone can want `01-08-2026` and `2:30 PM`, or `08/01/2026` and
`14:30`, and a clock convention guessed from a date order is a guess about
somebody's habits made from the wrong evidence.

`cc_datetime` uses one ICU formatter when **both** halves follow the locale, so
the language supplies its own connector; otherwise it formats each half and
joins them with a space, because ICU cannot mix an explicit pattern with a
style and either preference may be explicit while the other is not.

**Every human-readable date goes through one filter.** `App\Twig\
DateDisplayExtension` provides `cc_date`, `cc_datetime` and `cc_month`, and no
template calls `|date()` for display any more. A preference is only worth
having if it is honoured everywhere — a dropdown that fixes eight dates and
misses the ninth is worse than no dropdown, because the rider now believes the
site listens. `|date('c')` **stays** wherever it feeds a `<time datetime="">`
attribute: HTML defines that as ISO 8601, and it has nothing to do with what a
person reads.

Formatting goes through ICU rather than PHP's `date()`, because month names
have to come out in the page's language — the *page's*, not the rider's stored
locale, since a Dutch rider following a German link is reading a German page.

The client half is `assets/js/cc-dates.js` (`window.ccDate`, `window.ccMonth`),
driven by the same value handed over as `window.CC_DATE` in `base.html.twig`.
Without it, JS-rendered dates (the photo drawer's capture month, the consent
notice's agreement date) would disagree with server-rendered ones on the same
page, which is exactly the failure the preference exists to prevent.

### Distance and elevation units

`App\Account\DistanceUnit` — `km | mi`, stored on `users.distance_unit`,
default `km`. `App\Account\ElevationUnit` — `m | ft`, on
`users.elevation_unit`, default `m`. Migration `Version20260808220000`.

**Two preferences, not one imperial switch.** Miles with metres of climbing is
what most of Britain rides, and a single toggle would make those riders accept
a unit they never use to get the one they do. Same argument as date vs time
above: two habits, two columns.

**Display only. Nothing stored ever leaves metric.** The database, the API, the
GPX pipeline and every measurement in the Commons stay in kilometres and metres
— a dataset whose units depend on who is reading it is a dataset nobody can
join. Conversion happens at the last step before a number becomes text, and
nowhere else. Anonymous visitors get metric, which is what the app has always
shown.

`App\Account\UnitFormatter` is that last step, and `App\Twig\
UnitDisplayExtension` exposes it to templates:

| filter/function | takes | writes |
|---|---|---|
| `\|cc_km` | kilometres | `84.2 km` / `52.3 mi` |
| `\|cc_m` | metres, short range | `250 m` / `820 ft`; promotes to `cc_km` past a kilometre (a quarter mile) |
| `\|cc_elev` | metres of height | `1,240 m` / `4,068 ft` |
| `\|cc_km2` | square kilometres | `16,089 km²` / `6,212 sq mi` |
| `\|cc_per_km2` | count per km² | the density number alone, fixed decimals |
| `cc_distance_suffix()` / `cc_elevation_suffix()` / `cc_area_suffix()` | — | the bare unit word, for axis captions and input labels |
| `cc_km_value()` / `cc_elev_value()` | metric | the converted **number**, for form fields and example placeholders |

A short horizontal distance follows the **distance** preference and lands in
feet, not fractions of a mile: "820 ft off the track" is a distance somebody can
picture, "0.16 mi" is not.

**Speed follows the distance preference, and has no control of its own**
(2026-08-12). A rider who reads miles reads mph; a second setting could only let
the two disagree. `ccSpeed()` / `uSpeed()` take km/h and write `32 km/h` or
`20 mph`, whole numbers — a radar reading 31.6 km/h is not that precise. The
first consumer is Scout's overtake markers
([moderation-and-contribution.md](moderation-and-contribution.md) — "Scout
intake").

Areas and densities move in opposite directions — a square mile is bigger, so a
country covers fewer of them and each holds more places.

**Units left the translated strings.** Messages that used to write their own
unit (`'{n} m climbing'`, `'%m% m ascent'`, `'Length (km)'`, `'km {a} · {b} m
off'`, the route/ride length bounds) now place an already-formatted value:
`'{n} climbing'`, `'%v% ascent'`, `'Length (%u%)'`, `'{a} along · {b} off'`,
`'between %min% and %max%'`. A string that spells its own unit cannot follow a
preference.

The client half is `assets/js/cc-units.js` (`window.ccKm`, `window.ccM`,
`window.ccElev`, plus `ccKmValue`/`ccElevValue` and the reverse
`ccKmFromValue`/`ccElevFromValue`), driven by `window.CC_UNITS`. That bridge is
emitted **twice**: in `base.html.twig` for every ordinary page, and again in
`templates/map/index.html.twig`, which does not extend it. Without the second
copy the map would draw every distance in kilometres while the same rider's
moderation tables read miles. Map ES modules reach it through
`assets/map/units.js` (`uKm`/`uM`/`uElev`), which falls back to metric so
`node --test` can still import the leaf modules.

**The three places a rider types or drags a distance** convert on the way in as
well as out:

- the climb wizard's length and gain fields — shown and autofilled in the
  rider's unit, converted back by model transformers on `AddClimbType` so the
  submitted payload is metric. Server-side rather than in the browser: a
  submission that arrived in miles because JavaScript was supposed to convert it
  and did not is a wrong number nobody can spot afterwards;
- the base-location radius slider — the **input stays kilometres** (that is what
  is stored and what the controller reads) and only the read-out follows the
  preference;
- the ride-check radius select — option **values** stay metres, because the
  server accepts only its own fixed set (`RideCheckService::ALLOWED_RADII`);
  the labels convert.

**The baked display strings are gone** (2026-08-09). Ten seeded climbs carried
pre-formatted `record` / `headline` values in `item.attributes` (`"2.2 km"`),
written before either was derived at render time. A stored string cannot follow
a preference — no formatter runs late enough — so the number went back to being
a number:

- `app:climbs:recompute --write` re-measured the 13 climbs that have a drawn
  line, storing `length`/`gain` in metres and dropping `headline`;
- `app:catalog:retire-baked-length --write`
  ({@see App\Catalog\Command\RetireBakedLengthCommand}) parsed the remaining
  baked `record` "Length" rows into the discrete `length` attribute for the
  eight point-only Wikidata seeds, which have no line to measure. A **measured**
  length always wins; an unparseable value is reported and left alone; the
  consumed row leaves `record`, and `record` goes with it when it empties. Dry
  run by default, safe to re-run.

**The steepest-ramp label gave its width back to the value** (2026-08-09). The
climb field used to be called `Steepest 250m (%)`, which failed three ways at
once: a msgid cannot be interpolated, so the number had to be retyped in five
catalogues whenever `ClimbProfiler::MAX_WINDOW_M` moved — and it went stale
immediately, reading "Steepest 100m" under a caption saying "steepest 250m";
it could not follow a rider reading in feet; and the width is not even a
per-TYPE fact, since every climb stores the window it was actually measured at
(`steepWindowM`) and rows measured before 2026-08-07 really are 100 m ones.

The label is now `Steepest sustained (%)` — it still says WHICH measurement it
is, which was the point of the 2026-08-05 rename ("max gradient" invites
comparison with a point maximum; Mur de Huy's famous ~26% is its steepest
hairpin, not its steepest sustained stretch). The width travels with the value:
the drawer writes **"13% over 820 ft"** from that climb's own `steepWindowM`,
in the reader's unit, falling back to 100 m for rows that predate the
attribute. `BackfillAttributesCommand::LEGACY_LABELS` keeps the two retired
labels resolving to `maxGradient` so an older harvest re-import still lands.
A baked `Max gradient` stays deliberately unmatched — it is a point maximum
from somebody else's compilation, a different measurement.

One thing stays metric on purpose: an imported Wikidata *description* ("is a
2,200 m climb") is quoted source prose, not a field we render. Rewriting
somebody else's sentence is not unit conversion.

### The curating invitation on the landing pane

With a curator application in flight, this block answers "where is my request?".
**Without one, the reader is a rider, not a curator** — telling them they have
no application in progress states the obvious in somebody else's vocabulary,
under a heading ("Curator applications") that is not about them. So the empty
case is an invitation, and it answers the question a rider might actually have:
*does my own patch have anyone looking after it?*

Best evidence first — base region, else declared country, else nothing:

| state | when | says |
|---|---|---|
| `region_local` | their region has its own curator | it does, and a region can have more than one |
| `region_national` | only a country-wide moderator covers it | covered nationally, nobody local |
| `region_none` | nobody at all | nobody yet |
| `country_some` | no base location, their country has someone | it does, regions could still use somebody closer |
| `country_none` | no base location, their country has nobody | not a single region |
| `unknown` | neither known | what a curator is, and a link to set their area |

Region-level and country-level cover are reported **separately** rather than
folded into one boolean. A moderator scoped to NL is real cover for all twelve
provinces, so those regions are not "uncovered" — but they are not done either:
a region can have its own curators alongside the country's, and somebody who
actually rides there sees what a country-wide view never will. Collapsing the
two would either nag people whose area is handled or ignore people whose area
needs them.

Every state carries a way in, including the covered ones. There is more to do
than curating, and "we have someone" is not a reason to close the door.

### 9.4 How long a page is (2026-08-09)

`users.rows_per_page` (`App\Account\RowsPerPage`, migration
`Version20260809120000`) sits beside the unit and format preferences and is
display-only in exactly the same way.

**`auto` is the default, and it is not a number.** Each list keeps the size it
was designed around — 20 for messages because a message is a card, 25 for a
moderation desk because a queue item is a row of work, 60 for the contributors
wall because a wall row is one line and somebody scanning for a name would
rather scroll than click. Any single global default would have to be wrong for
two of those three. `25`/`50`/`100` override all of them at once, which is the
point: a rider who picks 100 is telling us about their screen and their
patience, not about our page design.

`App\Pagination\PageSize::resolve(int $surfaceDefault)` is the only reader.
Each list passes the size it was built for and gets back either that number or
the rider's choice — so the surface default stays in the CALL, the resolver
holds no opinion about how long a message list should be, and adding a paged
list never means editing it. A signed-out reader always gets the default;
there is nowhere to store a choice for them.

**Two doors, one setting.** It is on the Profile settings tab with the other
display preferences, AND in every pager. The second door exists because the
moment anyone *wants* a different page length is the moment they are looking at
a pager, and sending them off to find a settings tab is the kind of
correct-but-useless routing that means the setting never gets changed. The
pager's control POSTs to `settings_rows_per_page`, writes the same column, and
returns to the list — via a submitted `back` field, not `Referer`, and only
relative paths are honoured, or a logged-in POST becomes an open redirect.

## 10. Self-service account deletion (GDPR Art. 17)

Two-step flow in `SettingsController` (danger zone, Security tab), both steps
POST + CSRF:

1. `settings_delete_request` — `UserDeletionService::requestDeletion()`
   generates an 8-hex-char one-time code (`bin2hex(random_bytes(4))`,
   uppercased), stores it with `deletionRequestedAt`, and emails it.
2. `settings_delete_confirm` — code validated with `hash_equals`
   (case-insensitive via uppercasing), **expires 1 hour** after the request
   (`+1 hour` in `UserDeletionService::confirmDeletion()`). On success:
   `purge()` (the shared hook seam, §6.3), flush, session invalidated,
   redirect home.

A pending self-request is what arms the admin **Execute account removal** /
**Cancel pending removal** actions (§6.1) — an admin path through the same
`purge()` seam, with audit.

What survives deletion, and why, is stated on the privacy notice rather than
left implicit: the contributions given to the open map (with provenance), and
the consent ledger — the evidence that a CC BY-SA licence was granted, kept
under Art. 17(3)(e) and disclosed under Art. 13(2)(a). Neither identifies the
person once the `users` row is gone.

---

## 11. Data export (GDPR Art. 15 + Art. 20)

`POST /settings/export` → one ZIP, built by `App\Account\DataExportService`.

**One export, not two.** Art. 20 portability strictly covers only what the
subject *provided*, while Art. 15 access is broader. Making a rider choose
between two downloads would be a worse answer to both, so this is the superset
and the README inside says which part is which.

**What it holds.** `account.json`, `contributions.json` (submissions plus the
`change_history` rows they produced), `community.json` (confirmations, route
votes, rides, correction suggestions, country requests, curator applications,
moderator areas), `messages.json`, `consent.json`, and `photos/` — the stored
originals as files, plus an `index.json` describing each one.

**What it does not, by construction.** Every query names its columns; none is
`SELECT *`. That is the mechanism, not a filter someone has to remember to
maintain: the password hash, the TOTP secret and the backup-code hashes cannot
appear, and a future column cannot silently end up in riders' downloads. On the
other side, curators' identities are absent for the same reason: what was
decided about a rider's submission is theirs, notes included, but *who* decided
it is the curator's.

**Access.** POST, CSRF, and the **current password** re-checked. This one
request assembles everything the app knows about a rider, which makes it worth
more to somebody on a borrowed session than any page it draws from, because it
removes the work of collecting them. The rate limiter is consumed *before* the
password check, so the endpoint is not an unmetered password oracle. Three a
day (`data_export`): Art. 12(5) allows refusing repetitive requests, and this
is the heaviest read the app offers.

**Delivery.** Built to a temp file and returned as a `BinaryFileResponse` with
`deleteFileAfterSend()`, `Cache-Control: no-store, private`. Photo binaries are
staged as temp files that `ZipArchive` reads at `close()`, so a rider with a
hundred photos costs disk rather than memory. A tombstoned upload is still
listed, with `"file": null` — hiding the row would hide a fact about them.

---

## Open questions

- **Inactivity lifecycle** (§6.6) — design confirmed 2026-07-01 but pending
  implementation; blocked on a `lastActiveAt` schema addition and a scheduler.
- **Anonymize-in-place hooks** — the `UserDeletionHookInterface` seam exists
  and both deletion paths route through it, but no hook implementations exist
  yet; until they do, account removal hard-deletes the User row while
  contributed rows keep plain user ids. The anonymous "former contributor"
  presentation is unbuilt.
- **CAPTCHA / IP-diversity step-up before hard lockout** (§3) — recorded as a
  deliberate future product decision; the bounded lockout DoS stands until
  then.
- **User-index role/locked filters** in the admin desk — deferred (needs
  custom EA filter classes for jsonb containment and `lockedUntil > now`);
  the dashboard count cards partially cover the visibility need.
- **Deferred-until-needed hard operator isolation** (separate admin login
  domain) — explicitly not planned; revisit only on a concrete threat.
