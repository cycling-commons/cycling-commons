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
| Identity | `id` (int PK), `uuid` (UUIDv7, table-level unique constraint `uniq_users_uuid`), `email` (unique), `displayName` + `displayNameCanonical` (§9), `country` (nullable FK, `SET NULL`), `locale` (nullable; null = follow switcher/browser) | `uuid` is assigned in the `PrePersist` callback and is the only identifier ever exposed publicly (§7) |
| Auth | `password` (hash, `auto` hasher), `roles` (json) | |
| Email verification | `emailVerified`, `emailVerifiedAt` | token flow is signed-URL (§2), no stored token column |
| 2FA | `twoFaEnabled`, `totpSecret` (encrypted at rest, §4), `backupCodes` (json, keyed hashes) | |
| Lockout | `failedLoginAttempts`, `lockedUntil`, `isLocked()` | §3 |
| Deletion | `deletionCode`, `deletionRequestedAt` | §10 |
| Governance | `publicProfile` (bool, opt-in, default false) | §7 |
| Preferences | `bikeTypes` (json), `ridingStyles` (json) | §9 |
| Base location (optional, account-private) | `basePoint` (geometry GeoJSON Point, coords rounded to 2dp at write — ~1 km precision), `basePlace` (varchar(120), town-level label for the scope line), `baseRadiusKm` (smallint, default 40, clamped [10,150]), `baseRegionIds`/`baseCountryCodes` (json, derived — `App\Service\BaseAreaResolver`, cap 8) | region-scoping-design.md §7 Phase 4; **never** exposed on the public profile (§7 below); no GIST index (nothing queries users spatially) |
| Audit | `createdAt`, `updatedAt` (lifecycle callbacks) | there is **no** `lastActiveAt` — required by the unbuilt inactivity lifecycle (§6.6) |

The entity implements `UserInterface`, `PasswordAuthenticatedUserInterface`,
scheb's `TwoFactorInterface` (TOTP) and `BackupCodeInterface`. Validation
(email format/length, display-name uniqueness) sits **on the entity**, not on
individual forms, so every write path — registration form, settings form,
console commands, admin CRUD, fixtures — is covered.

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
`scheb/2fa-bundle` (+totp, +backup-code), `easycorp/easyadmin-bundle`. The
proprietary `bikecoderslife/bundle` was a **pattern reference only**: never a
dependency, no code copied.

**Registration** (`App\Controller\RegistrationController`):

- Fields: email, display name (2–100 chars, `RegistrationFormType`), repeated
  password (**min 12 chars**, `Length(min: 12)` in
  `web/src/Form/RegistrationFormType.php`), agree-terms checkbox. Preferences
  are *not* asked at registration — friction-free by design (§9).
- New accounts get `['ROLE_USER']` and `emailVerified = false`.
- Duplicate email is caught twice: `UniqueEntity` on the entity, and a
  TOCTOU catch of `UniqueConstraintViolationException` at flush that re-renders
  the same duplicate-email form error as a 422 instead of a 500.
- A verification mail is sent (verify-email bundle, signed URLs — no stored
  token). `/verify/email` validates the signature and flags
  `emailVerified`/`emailVerifiedAt`, then redirects to login.

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
  (added 2026-07-22, closing the former known gap) — purges the user's
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

Confirmed design (2026-07-01), not built; requires a `lastActiveAt` column
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
- **"View as others see it" is the real page**: settings links the rider's own
  public URL (`target="_blank"`) with **zero owner special-casing** — what the
  owner sees is byte-for-byte what others get. When the toggle is OFF the link
  is replaced by a hint; there is no preview of a non-existent page. Settings
  is the link's only home (deliberately removed from the profile dashboard).
- **Shown** (allow-list): display name, country flag (if set), member-since
  (month + year from `createdAt`), riding-preference chips, count of
  **Approved** submissions, and up to **10** most recent **Verified** routes
  by name, each deep-linked to the map via `?route=` (limit is the `findBy`
  third argument in `RiderProfileController::show()`).
- **Pending-route count spans both pre-Verified states** — `Submitted` +
  `Unverified` — but Submitted route **names never render** (un-vetted);
  rejected submissions never appear (not Approved). State machine:
  [route-domain.md](route-domain.md).
- **Never shown:** email, IPs, locale, roles, 2FA state, base location (point,
  place, radius, derived region/country sets), or anything account-internal.
  Pinned by `RiderProfileTest::testBaseLocationNeverExposedOnPublicProfile`
  (region-scoping-design.md §4 "frozen exposure list").
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
  state is the `account.contributions_empty` key. The **Votes** and
  **Saved-regions** panes deliberately keep preview sample data (marked as
  such in the template) until a real data source exists.

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

### Case-insensitive unique display names — shadow canonical column

- Uniqueness is **case-insensitive** (`Xander`/`xander` collide); leading and
  trailing whitespace is trimmed; inner-whitespace variants stay distinct.
- Implementation: shadow column `users.display_name_canonical`
  (`mb_strtolower(trim(...))`, unique constraint
  `uniq_users_display_name_canonical`), maintained **inside
  `User::setDisplayName()`** so every write path is covered automatically —
  registration, settings, console commands, admin CRUD, fixtures.
- **Empty display names canonicalize to `NULL`**, which both the Postgres
  unique index and `UniqueEntity` ignore — unnamed rows (tests, partial flows)
  never collide. Load-bearing; do not "fix" to empty string.
- Shadow column chosen over a `LOWER()` functional index: Doctrine-native (no
  DBAL schema-diff drift) and stock `UniqueEntity` works with zero custom
  validator. Validation is on the **entity**
  (`fields: ['displayNameCanonical'], errorPath: 'displayName'`) so all write
  paths validate, not just one form.

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

**Data export (Art. 20) is an acknowledged open follow-up** — no export
endpoint exists (see Open questions).

---

## Open questions

- **GDPR Art. 20 data export** — decided as a near-term follow-up in the
  migration design; still unbuilt and unscheduled. No endpoint exists.
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
