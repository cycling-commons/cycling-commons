<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Account & Authentication

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This document defines the identity and authentication contract of Cycling
Commons: the single-User model and role ladder, registration/verification/reset,
login throttling and lockout, the 2FA policy and its enforcement, the firewall
shape, the admin support desk (audit, guardrails, removal semantics), the public
rider profile, the account shell and settings surfaces, display-name identity,
rider preferences, and GDPR deletion. Where a fact belongs to a sibling domain it
is linked, not restated: moderation/submission machinery lives in
[moderation-and-contribution.md](moderation-and-contribution.md), the route (R)
domain in [route-domain.md](route-domain.md), CSP/CSRF/sanitizer details in
[security-architecture.md](security-architecture.md), and map/search UX in
[map-and-search.md](map-and-search.md).

---

## 1. User model: one entity, a role ladder, no staff table

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
JSON: the invariant is behavioural (`getRoles()`), not a storage guarantee.

**Why no separate AdminUser/staff table:** curators are trusted riders, not an
organizationally separate staff class. A rider *becomes* a curator by earning
`ROLE_CURATOR` and keeps one identity, profile, and contribution history:
which matters for provenance. Hard operator isolation (a separate login domain)
is deliberately deferred unless a concrete need appears; mandatory 2FA on
elevated roles plus lockout cover the near-term risk. Per-area curator scoping
exists as data on the side (`moderator_area` rows, contract in
[moderation-and-contribution.md](moderation-and-contribution.md)), not as extra
roles.

### Field inventory (contract-level)

| Group | Fields | Notes |
|---|---|---|
| Identity | `id` (int PK), `uuid` (UUIDv7, table-level unique constraint `uniq_users_uuid`), `email` (unique), `displayName` (§9, deliberately **not** unique), `pseudonym` (8 characters, drawn once at creation, the `rider#` handle, §9), `country` (nullable FK, `SET NULL`), `locale` (nullable; null = follow switcher/browser) | `uuid` is assigned in the `PrePersist` callback and is the only identifier ever exposed publicly (§7) |
| Auth | `password` (hash, `auto` hasher), `roles` (json) | |
| Email verification | `emailVerified`, `emailVerifiedAt` | token flow is signed-URL (§2), no stored token column |
| 2FA | `twoFaEnabled`, `totpSecret` (encrypted at rest, §4), `backupCodes` (json, keyed hashes) | |
| Lockout | `failedLoginAttempts`, `lockedUntil`, `isLocked()` | §3 |
| Suspension | `suspendedUntil`, `suspendedAt`, `suspensionGround` (a `StatementGround`), `suspensionFacts`, `suspendedBy` (user id, foreign key `ON DELETE SET NULL`), `isSuspendedAt()` | §6.8 |
| Deletion | `deletionCode`, `deletionRequestedAt` | §10 |
| Governance | `publicProfile` (bool, opt-in, default false) | §7 |
| Preferences | `bikeTypes` (json), `ridingStyles` (json), `defaultMapMode` (string, default `auto`; `auto \| everything \| confirmed \| curated`), `mapTheme` (string, default `dark`; map-and-search.md), `dateFormat`/`timeFormat` (string, default `auto`), `timeZone`/`detectedTimeZone` (nullable), `distanceUnit` (string, default `km`), `elevationUnit` (string, default `m`), `rowsPerPage` (string, default `auto`, §9.4) | §9 |
| Updates and notices | `updatesOptIn` (bool, default false), `updatesCadence` (string, default `big`), `privacyVersionSeen` (nullable int) | roadmap-and-changelog.md; privacy-notice.md |
| Age (GDPR Art. 8) | `ageConfirmedAt` (nullable datetime: when they declared 16+; NULL = predates the gate, or created by an admin/console path) | §2; deliberately **not** a date of birth |
| Media | `keepMediaCredit` (bool): the departing rider's credit choice, read at deletion | photo-uploads.md §6 |
| Base location (optional, account-private) | `basePoint` (geometry GeoJSON Point: a new point is shifted to a random spot up to 2.5 km away, uniform over the disc, by `App\Service\BaseLocationJitter`, then rounded to 2dp; a point equal to the stored one is kept, so a radius-only save does not move it), `basePlace` (varchar(120), town-level label for the scope line), `baseRadiusKm` (smallint, default 40, clamped [10,150]), `baseRegionIds`/`baseCountryCodes` (json, derived: `App\Service\BaseAreaResolver`, cap 8) | map-and-search.md §4.5; **never** exposed on the public profile (§7 below); no GIST index (nothing queries users spatially) |
| Activity | `lastLoginAt` (stamped on every sign-in, remember-me included), `inactivity12mAt`/`inactivity22mAt`/`inactivity23mAt` (the three dormancy notices) | §6.5 |
| Audit | `createdAt`, `updatedAt` (lifecycle callbacks) | |

The entity implements `UserInterface`, `PasswordAuthenticatedUserInterface`,
scheb's `TwoFactorInterface` (TOTP) and `BackupCodeInterface`. Validation
(email format/length and uniqueness, display-name format, §9) sits **on the
entity**, not on individual forms, so every write path (registration form,
settings form, console commands, admin CRUD, fixtures) is covered.

Privacy consequence (standing rule): the platform **does** hold personal data:
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

All built on permissive MIT libraries: `symfony/security-bundle`,
`symfonycasts/verify-email-bundle`, `symfonycasts/reset-password-bundle`,
`scheb/2fa-bundle` (+totp, +backup-code), `easycorp/easyadmin-bundle`. A
proprietary in-house auth bundle (private, unnamed here) was a **pattern
reference only**: never a dependency, no code copied.

**Registration** (`App\Controller\RegistrationController`):

- Fields: email, display name (2–100 chars, `RegistrationFormType`), repeated
  password (**min 12 chars**, `Length(min: 12)` in
  `web/src/Form/RegistrationFormType.php`, plus `NotCompromisedPassword`
  on all three password forms (k-anonymity: only
  the first five SHA-1 hex chars reach haveibeenpwned; `skipOnError` so an API
  outage never blocks anyone; disabled in test via `validator.yaml`
  `when@test`)), an **age declaration** and an
  agree-terms checkbox. Preferences are *not* asked at registration:
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
(Art. 5(1)(c)). The declaration is stored as `users.age_confirmed_at`: a
timestamp, because null/not-null already carries the boolean and the *when* is
the part worth keeping.

Enforced by an unmapped `IsTrue` constraint on the form, so an unticked box is
a 422 with the rest of the input preserved. **Existing accounts keep NULL**:
they registered before the gate existed, and back-filling a declaration nobody
made would be a record of something that never happened.
- New accounts get `['ROLE_USER']` and `emailVerified = false`.
- **An address the mailer refuses is a form error, not a 500.**
  `Assert\Email` in its default mode accepts `j..t@gmail.com`; `new Address()`
  refuses it with an exception, which on sign-up would come after the row is
  written and leave the address "taken". `App\Validator\MailableEmail` on
  `User::$email` (and on the reset and resend forms) builds the same `Address`
  the mailer would and reports a refusal as `form.error_email_mailable`
  ("Check it for typos, such as two dots in a row").
- **One spelling per mailbox.** `User::setEmail()` stores the
  address trimmed and in lower case (`User::normalizeEmail()`), and every
  lookup lower-cases what it is given: `UserRepository::findByEmail()`, and the
  login provider through `UserRepository::loadUserByIdentifier()` (the provider
  in `security.yaml` has no `property`, so Symfony asks the repository).
  `Rider@example.com` and `rider@example.com` are one account. Migration
  `Version20260929010000` lower-cased the rows that existed before, except one
  whose lower-case form another account already held: two accounts on one
  mailbox is for a person to merge.
- **A taken address is answered like a new one** (owner, 2026-09-29). The
  form never says which addresses have accounts: sign-up with an address that
  already has one renders the same check-your-email page, and the inbox learns
  the rest (`App\Security\ExistingAccountNotice`):
  - an account that never confirmed gets a fresh confirmation link;
  - a confirmed account gets a note (`emails/account_exists.html.twig`) saying
    somebody tried to sign up with the address, with a sign-in button and the
    password-reset link; nothing about the account changes.

  Both mails spend the resend page's per-address budgets below (one per 15
  minutes, three per day), so repeating the sign-up cannot flood the inbox, and
  over budget the page is still the same. The password is hashed **before** the
  lookup, so a taken address costs the same time as a new one. There is no
  `UniqueEntity` on `User`: the lookup is in `RegistrationController`, and the
  database's unique index is the last guard; losing that race at flush
  (`UniqueConstraintViolationException`) answers like any taken address, never
  a 500. Pinned by `RegistrationTest::testATakenAddressIsAnsweredLikeANewOne`,
  `testATakenConfirmedAddressGetsTheWayToSignIn` and
  `testATakenAddressIsMailedOncePerQuarterHour`.
- A verification mail is sent (verify-email bundle, signed URLs: no stored
  token). **It greets nobody by name and is addressed to the bare address**
  (`EmailVerifier::sendConfirmation()`): the address may belong to a stranger
  a bot signed up, and the display name is whatever the bot typed, so putting
  it in the mail would let a stranger's text go out from our domain.
  **The link works for 24 hours** (`config/packages/verify_email.yaml`,
  `lifetime: 86400`; the bundle's default is one hour): a rider who signs up
  at night confirms in the morning, and a new link is one form away. The mail
  and the check-your-email page say "24 hours" in their own translated words,
  not the bundle's untranslated duration. **The link is localized** (owner, 2026-08-27): signing up on
  `/nl/register` sends a `/nl/verify/email` link, not a bare `/verify/email`.
  This is the one page a rider arrives at from an email, with no referring page
  to inherit a language from, so the language is written into the link at signup
  time rather than guessed on arrival. It falls out of Symfony's localized
  routing on its own: `LocaleListener` puts the request locale on the routing
  context, so `generateSignature('verify_email', ...)` picks the matching
  variant, and the locale is inside the signed URI rather than beside it.
  English keeps the bare `/verify/email`, so links already in inboxes stay
  valid. Guarded by `LocalizedRoutingTest::testTheEmailVerifyLinkIsLocalized`.
  `/verify/email` validates the signature and flags
  `emailVerified`/`emailVerifiedAt`, then redirects to login. A link that is
  expired or broken redirects to `/verify/resend` (below) with a notice.
- **Journey continuity (verified end-to-end):** the firewall's
  saved target path survives the whole register → verify → login detour in
  one session, because registration and verification never touch it and
  `LoginSuccessHandler` honours it after authentication. An anonymous visit
  to a gated page (e.g. `/join/BE`,
  [moderation-and-contribution.md](moderation-and-contribution.md) §11)
  therefore lands back on that page after account creation: no re-navigation
  needed.

**Bot layers on sign-up.** Bots sign strangers' addresses up with random names
(`TQzXxAvQrqrRKJjPh`), so our confirmation mail would go to people who never
visited. The per-connection limit below cannot stop that alone: a bot that
uses many connections is under every one of them. Sign-up carries the contact
form's four local layers
([contact-and-support.md](contact-and-support.md) §3), through
`App\Security\SignupGuard`, and still no third-party CAPTCHA:

1. **Honeypots and the signed timer** (`FormGuard::reject()`), before the form
   is validated: free, and they catch the bulk.
2. **Validation** (the form type and entity constraints above).
3. **Proof of work** (`ProofOfWork`), after validation so a typo does not
   spend the rider's solved challenge. `support/form-challenge.js` fetches and
   solves it in the browser; with JavaScript off, sign-up does not work, the
   same trade the contact form makes.
4. **The per-connection limit** (below).
5. **The address's domain must resolve** (`FormGuard::domainResolves()`), last,
   because it is the one check that leaves the process.

Refusals are form-level errors under `security.guard.*`, a 422, with the
input kept (passwords excepted, as always). The page runs
`register-validate.js` before `form-challenge.js`, and the challenge script
leaves a submit alone once validation has prevented it
(`tests/js/form-challenge.test.cjs`). Pinned by `RegistrationTest`, which sends
every case through the real guards (`GuardedSignupTrait`) so a validation
test cannot pass only because a bot guard refused it first.

**A new confirmation link** (`App\Controller\VerificationResendController`,
`/verify/resend`, localized like `/verify/email`): the way back for a rider
whose link expired. The page asks for the address and **always answers the same "check your email" card**,
so it says nothing about which addresses have accounts. A mail goes out only
for an existing, unconfirmed account, and only within its budgets:

| limiter | key | budget | over budget |
|---|---|---|---|
| `verify_resend` | the connection | 5 per hour | a visible 429: it is about the sender |
| `verify_resend_address` | the address | 1 per 15 minutes | the same card, no mail |
| `verify_resend_address_daily` | the address | 3 per day | the same card, no mail |

The two address budgets are shared with a sign-up on a taken address (above):
both mail the same inbox, so they draw on one allowance.

The address budgets are keyed on the lower-cased address (salted hash, like
every limiter key) and spent only when a mail would go out. The page has the
same bot layers as sign-up. Linked from the check-your-email page, the login
error below, and the expired-link redirect. Pinned by
`VerificationResendTest`.

**No sign-in before confirmation** (`App\Security\VerifiedEmailChecker`, the
firewall's `user_checker`). An unconfirmed account is refused with "Confirm
your email address first" and a link to `/verify/resend`. The check is
**post-auth**, after the password: refused earlier, it would tell anybody who
types an address, with any password, that it is waiting for confirmation. A
right password on an unconfirmed account is not a failed attempt and does not
count toward the lockout (`LoginThrottleListener`, §3). Pinned by
`UnverifiedLoginTest`.

**Password reset** (`App\Controller\ResetPasswordController`, reset-password
bundle with its own `ResetPasswordRequest` entity):

- No account enumeration: an unknown email redirects to the same check-email
  page, and a direct visit to check-email renders a **fake token object** so
  timing/content cannot reveal existence.
- The check-email copy states the token lifetime in whole hours, read from the
  token's own generated-at/expires-at interval (bundle default 3600 s = 1 hour),
  never from the wall clock at render time: the token is minted on the POST and
  the page renders on the following GET, so a wall-clock remainder floors to 0.
- The token is moved from the URL into the session on arrival (prevents
  Referer leakage), consumed before the new password is persisted.
- **Completing a reset clears any brute-force lock** (`lockedUntil = null`,
  counter reset): the reset flow is the owner's recovery path out of a
  lockout DoS (§3).
- **Completing a reset confirms the address.** The reset link was opened from
  the inbox, which proves the address as well as a confirmation link does.
  Without this, an unconfirmed rider who reset their password still could not
  sign in. Pinned by `ResetPasswordTest::testPasswordResetConfirmsTheAddress`.
- Repository throttling in the bundle prevents reset-request floods; stuck
  rows are visible/purgeable via the admin diagnostics CRUD (§6.6).

**Both of these endpoints are budgeted per address** (`password_reset` and
`registration`, 5 per hour each,
[security-architecture.md](security-architecture.md) §7). One unauthenticated
POST to either persists
a row and sends a message to an address the sender chose, so a loop is two
attacks at once: an inbox flood aimed at somebody else and a table flood aimed
at us. The bundle's own repository throttle does not cover it, because that one
is per user and the flood picks a new target address every time.

The two differ in what over-budget looks like, and the difference is the point:

- **Password reset** redirects to `/reset-password/check-email`, exactly the
  answer a real request gets. A `429` here would be the account-enumeration
  oracle this page is otherwise careful to avoid.
- **Registration** answers `429` with a visible form error. The limit is keyed
  on the sender's connection, not the address, so it says nothing about which
  addresses have accounts, and a silent no-op would just look broken to an
  honest visitor.

The limiter is consumed **before** anything is written or sent, and the keys are
salted hashes of the address rather than the address itself.

**Mail:** `symfony/mailer`; Mailpit in the dev docker stack, prod SMTP via the
`MAILER_DSN` env var. Sender identity is `noreply@cyclingcommons.org`.
All transactional emails (verification, password reset, account-deletion
code) extend one branded shell, `templates/emails/_base.html.twig`
Email templates use email-client-safe markup only: presentation tables + inline
styles, paper backdrop, ink header band carrying the wide wordmark
(`assets/brand/logo-email.png`, a PNG render of the nav SVG since mail
clients strip SVG), orange action button. Copy lives in the extending
templates; the shell owns layout and the footer.

Under the action button, the verification and password reset emails print the
same link as text ("Button not working? Paste this link into your
browser:"), for mail clients that drop or block the button. Both are
translated in all five catalogues (`registration.email.*`,
`security.reset_email.*`, subject included) and go out in the language of the
page the rider asked from: the routes are locale-prefixed and the mail is sent
inside that request. The reset email's expiry is the token's own lifetime,
worded by the reset bundle's translations ("1 hour", "1 uur").

## 3. Login throttling and account lockout

Two complementary layers:

1. **Per-IP throttle**: Symfony's built-in `login_throttling`,
   `max_attempts: 5` (`web/config/packages/security.yaml`). Covers
   many-accounts-from-one-address attacks.
2. **Per-account hard lock**: `App\Security\LoginThrottleListener`:
   - `LOCKOUT_THRESHOLD = 5` failed attempts →
   - `LOCKOUT_MINUTES = 15` lock (`lockedUntil = now + 15min`).

Lock semantics (all in `LoginThrottleListener`):

- A locked account is rejected at `CheckPassportEvent` **before password
  verification**: the correct password does not bypass the lock.
- Failures **while locked never count and never re-arm the window**: the
  advertised cooldown genuinely elapses (otherwise repeated attempts would be a
  permanent DoS).
- A failure after an *expired* lock starts a fresh window (stale counter
  reset first).
- Successful login resets both fields (flush skipped when already clean).
- No user-enumeration leak: unresolvable identifiers are silently ignored.

### The 2FA interstitial rides the same budget

`LoginThrottleListener` listens on `CheckPassportEvent` and
`LoginFailureEvent`, and Symfony's authenticator manager dispatches both for
**every** authenticator on the firewall, scheb's `TwoFactorAuthenticator`
included. So a wrong TOTP code at `/2fa_login_check` counts against the same
per-account budget as a wrong password, the fifth one locks the account, and
from then on even a **correct** code is refused at `CheckPassportEvent`.

`/2fa_login_check` therefore has **no rate limiter of its own, deliberately**.
A missing limiter there looks like unlimited six-digit guessing on exactly the
elevated accounts 2FA is mandatory for (a security scan of 2026-08-25 filed it
so), and it is not, but only because of the wiring above: narrowing
`LoginThrottleListener` to the password step would make it true.
`App\Tests\Auth\TwoFactorBruteForceTest` pins the
behaviour, including that the lock is per account so one attacked rider cannot
lock out another.

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

- `isMandatoryFor(User)`: `ROLE_CURATOR` reachable in the user's role set
  (role-hierarchy aware, so `ROLE_ADMIN` qualifies).
- `requiresSetup(User)`: mandatory **and** not *fully enrolled*.

**Fully enrolled** is defined by `User::isTotpAuthenticationEnabled()`:
`twoFaEnabled && totpSecret !== null`: a secret alone is not enrolment.
Invariant for fixtures and seeded elevated accounts: set **both**
`totpSecret` and `twoFaEnabled(true)`, or the account loops to `/2fa/setup`
(dev seed secret `JBSWY3DPEHPK3PXP`).

### Enforcement: three cooperating layers

1. `App\Security\LoginSuccessHandler` (the `main` firewall's
   `success_handler`): after full authentication, an elevated user for whom
   `requiresSetup()` is true is redirected to `/2fa/setup` (localized). Users
   mid-2FA (`TwoFactorTokenInterface`) are sent to the scheb interstitial.
   Also applies the user's saved locale to the session and localizes the
   default target so the path-prefix router cannot clobber it. The default
   target is `/account/settings` on the first sign-in since the address
   was confirmed (`lastLoginAt` null or older than `emailVerifiedAt`, read
   before the handler stamps it) and `/account` on every sign-in after that
   (§8). A saved target path wins over both.
2. `App\Security\TwoFactorSetupEnforcer` (`kernel.request` listener,
   priority 7): closes the remember-me / direct-navigation gap, so a
   not-fully-enrolled elevated user is redirected to `/2fa/setup` on **every**
   main request. A request with neither a session cookie nor a `REMEMBERME`
   cookie is skipped before any token read, since nobody is signed in on it.
   A request carrying only `REMEMBERME` (the first request of a new browser
   session, or a stolen cookie replayed with curl) is checked like any other:
   the token read signs it in through the remember-me authenticator first.
   Path bypasses run **before any token read** (an eager
   `getToken()` would boot the lazy firewall + session and destroy the
   cacheability of public endpoints): `/2fa*`, `/_wdt`, `/_profiler`,
   `/assets`, `/map`, `/routes/`, `/items/` (`BYPASS_PREFIXES` in the class),
   plus the setup route itself and `/logout` (always reachable).
   2FA-in-progress tokens are never touched: scheb owns the interstitial.
3. `App\Security\NoRememberMeBeforeTwoFactor` (`LoginSuccessEvent`,
   priority -48): no `REMEMBERME` cookie is minted for a user for whom
   `requiresSetup()` is true, even with "Stay signed in" ticked. scheb
   withholds the cookie only while its own 2FA token is in play, which an
   account with no secret never gets. The rider ticks the box again at the
   first login after enrolling.

### Enrolment flow (`App\Controller\TwoFactorController`, `/2fa/setup`)

- The freshly generated secret lives **in the session**
  (key `2fa_pending_secret`), *not* on the entity, until the user proves the
  scan by entering a valid code: only then are secret + `twoFaEnabled`
  persisted. The pending secret is reused across GET/POST so the scanned QR
  stays valid; QR rendered as inline SVG data-URI (no GD/Imagick dependency).
- On confirmation, **backup codes** are issued:
  `TwoFactorController::BACKUP_CODE_COUNT = 8`, each 80 bits of entropy
  (10 random bytes as grouped hex). Shown exactly once; stored only as keyed
  hashes.
- **Enrolling replaces the second factor, so it is guarded like one**
  (security audit 2026-10-04). An account that already has two-factor
  (`isTotpAuthenticationEnabled()`) must enter a current code from its app or
  one of its backup codes before the new secret is saved; a remembered session
  (not `IS_AUTHENTICATED_FULLY`) must also enter its password. Both checks run
  on the confirming POST, spend one try from `password_reauth` (§5), and
  put a wrong answer on its own field with nothing saved. A backup code used
  as that proof is not spent separately: enrolling replaces every backup code.
  The pending secret sits on a detached copy of the user, so the stored secret
  answers the old-code check and no flush can persist an unconfirmed one.
- **Every enrolment mails the account's own address**
  (`App\Security\TwoFactorChangeNotice`, `emails/two_factor_changed.html.twig`,
  in the account's saved language): "Two-factor sign-in is now on for your
  account" the first time, "Your two-factor sign-in moved to a new app" when it
  replaced one. It says when (UTC), and what to do if it was not them: change
  the password, then write to the public support address so an admin can turn
  the new factor off (Disarm 2FA, §6). It carries no
  display name and nothing a form posted. A refused attempt sends nothing.
- Interstitial login: scheb's `two_factor` firewall entry
  (`auth_form_path: 2fa_login`, `check_path: 2fa_login_check`); TOTP or a
  single-use backup code.

### Secret material at rest

- `totpSecret` uses the custom Doctrine type `encrypted_string`
  (`App\Doctrine\EncryptedStringType`, registered in
  `web/config/packages/doctrine.yaml`): AES-256-GCM, key derived by HKDF-SHA256
  from **`ENCRYPTION_SECRET`, falling back to `APP_SECRET`**, stored as
  `base64(iv || tag || ciphertext)`. A database-only leak does not expose
  authenticator seeds.
- Backup codes are stored as `HMAC-SHA256(code, HKDF(APP_SECRET))`
  (`User::hashBackupCode()`): a DB-only leak cannot even compute candidate
  hashes.
- Shared trade-off: rotating the key invalidates stored TOTP secrets and backup
  codes (affected users re-enrol).

**Why `ENCRYPTION_SECRET` exists** (security scan 2026-08-25). `APP_SECRET` is
a signing key that an incident runbook may quite reasonably tell you to
rotate; this is a data-encryption key that can only be rotated by re-encrypting
every row. Rotating one shared value would produce a **completely silent** 2FA
outage: `convertToPHPValue()` hydrates unreadable ciphertext as null on purpose
so that a mis-set variable cannot 500 every login, so the symptom would be
elevated users being told their code was wrong, one at a time, with nothing in
the logs.

Unset, the fallback to `APP_SECRET` keeps a deployment working with no change.
To separate them: set `ENCRYPTION_SECRET` to the **current** value of
`APP_SECRET`, deploy, confirm, and only then is `APP_SECRET` free to rotate.

`bin/console app:security:encryption-audit` (`App\Command\EncryptionAuditCommand`)
reports how many stored secrets the running key can actually read and lists the
accounts it cannot, exiting non-zero so a deploy script can gate on it. Run it
before and after touching either variable. Backup codes are a keyed hash rather
than ciphertext, so they cannot be audited this way: nothing can tell a wrong
key from a wrong code. That asymmetry is why the audit names TOTP only.
- TOTP parameters: SHA1, 30 s period, 6 digits
  (`User::getTotpAuthenticationConfiguration()`); issuer from `TOTP_ISSUER`
  (`Cycling Commons` in the committed `.env`; `web/config/packages/scheb_2fa.yaml`).
- **`leeway: 1`** (owner 2026-08-30): one window either side is
  accepted, so a code stays valid for about 90 seconds rather than 30. At the
  bundle default of 0, a phone clock a few seconds out, or a code typed at
  second 29 of its own window, is refused as wrong, and the person is told they
  entered the wrong code when they did not. The cost is a shoulder-surfing
  window three times as long; the GUESS space is unchanged at one in a million
  per attempt, and attempts are bounded by the login limiter that a wrong TOTP
  code already counts against (§3). Every authenticator app makes the same
  trade.
- The 2FA setup page carries a "Settings · Security" breadcrumb back-link and
  its post-enrolment Done button targets `/account/settings?tab=security` (§8).

### The interstitial is a hard stop, not just a page

Between the password and the second factor the session holds a
`TwoFactorToken` whose `getUser()` returns the real `User`. Controllers that
gate on `getUser() instanceof User` therefore look, from the inside, exactly as
they do after a completed login, which reads like state-changing POSTs being
reachable mid-interstitial (a security scan of 2026-08-25 filed it so).

They are not. scheb's `TwoFactorAccessListener` refuses every path that is not
the interstitial itself or explicitly `PUBLIC_ACCESS`, and redirects it to
`/2fa` before any controller runs. Verified end to end against `/map/theme` and
`/map/view-mode`: both bounce, and the profile column is unchanged.

Worth knowing because of what it implies for `access_control`. That list gains
`PUBLIC_ACCESS` entries regularly, several of them purely for cacheability
(§5). Every one of them also opens that path to half-authenticated sessions.
That is harmless for the cacheable GET endpoints there today, and would not be
for a state-changing route. `App\Tests\Auth\TwoFactorInterstitialLockdownTest`
pins the behaviour.

Admin recovery: the support desk's **Disarm 2FA** action (§6) clears secret +
codes and disables the flag: audited, confirm-gated.

## 5. Firewall and access-control shape

`web/config/packages/security.yaml`: a **single `main` firewall** (lazy):

| Element | Value |
|---|---|
| Provider | entity provider on `User.email` |
| Hasher | `auto` (argon2id-class) |
| `form_login` | CSRF on, `success_handler: App\Security\LoginSuccessHandler` |
| `logout` | CSRF on, target `home` |
| `remember_me` | lifetime `604800` (7 days), `samesite: lax`, `secure: auto`. The sign-in form's "Stay signed in" box carries a line under it (`security.login.remember_hint`): signed in for 7 days on this browser, not for a public or shared device |
| `/login` shortcut | fires on **`IS_AUTHENTICATED_FULLY`**, never on `getUser()` (below) |
| `/join/{cc}` | `ROLE_USER`; password re-confirmed **on submit** when the session is only remembered (below) |
| `login_throttling` | `max_attempts: 5` (§3) |
| `two_factor` | scheb interstitial (`2fa_login` / `2fa_login_check`) |

`access_control` (ordered; localized routes carry an optional two-letter
locale prefix matched by the WILDCARD group `(/[a-z]{2})?`: a literal locale
list would silently exclude the next locale added (owner, 2026-08-17); English
paths are clean; `logout`, `/2fa` interstitial, `/api`, `/admin`
stay unprefixed):

| Path pattern | Access |
|---|---|
| `(/[a-z]{2})?/(login\|register\|reset-password)` | `PUBLIC_ACCESS` |
| `^(/[a-z]{2})?/verify` | `PUBLIC_ACCESS`: the verify link is localized (§2) |
| `(/[a-z]{2})?/riders/` | `PUBLIC_ACCESS` (public rider profiles, §7) |
| `^/(robots\.txt\|sitemap\.xml)$`, the social short links `^/(m\|bs\|li\|ig\|yt\|r\|fb\|gh\|st)$`, `(/[a-z]{2})?/changelog\.atom$`, `^/unsubscribe`, `^/report/`, `^/map/catalog/stamps\.json$`, `^/map/catalog/region/\d+\.json$`, `^/map/best-of$`, `^/map/item/\d+/history$`, `^/map/region/<slug>/boundary$`, `^/map/scope/boundary$`, `^/routes/\d+\.gpx$`, `^/items/\d+/confirmations$`, `^/photo/<uuid>$` and `/report`, `^/map/coverage/(search\|nearby\|counts)$`, `^/map/coverage/poi/(node\|way)/\d+$`, `^/v1/` | `PUBLIC_ACCESS`: **exact-path, explicitly public** so scheb's lazy-firewall `TwoFactorAccessListener` skips the session read that would downgrade `Cache-Control` to private (data contracts: [catalog-data-model.md](catalog-data-model.md), [route-domain.md](route-domain.md), [moderation-and-contribution.md](moderation-and-contribution.md), [content-reports.md](content-reports.md), [public-api.md](public-api.md)) |
| `(/[a-z]{2})?/2fa/setup` | `ROLE_USER`: must precede the interstitial rule, since setup is reached signed in, not mid-interstitial. A remembered session may enrol but confirms its password on the POST (§4) |
| `^/2fa` | `IS_AUTHENTICATED_2FA_IN_PROGRESS` (scheb interstitial) |
| `(/[a-z]{2})?/account(/\|$)` | `ROLE_USER`: the whole rider area (§8) |
| `(/[a-z]{2})?/translate`, `^/scout/tags` | `ROLE_USER`: backstops mirroring the controllers' `IsGranted` attributes. `/media/*` and `/contribute/elevation` are deliberately absent: THE stateless-JSON pattern ([security-architecture.md](security-architecture.md) §5.1) owns their clean 401s, and an `access_control` rule would turn those into login redirects |
| `(/[a-z]{2})?/moderate` | `ROLE_CURATOR` |
| `^/admin` | `ROLE_ADMIN` |

Rule of thumb encoded above: any *cacheable public* endpoint needs an explicit
exact-path `PUBLIC_ACCESS` entry **and** (if it is not already under a bypassed
prefix) a `TwoFactorSetupEnforcer` bypass: "no rule matches" is not enough
under the lazy firewall.

**A second factor that cannot be enrolled is never demanded.**
`TwoFactorPolicy::requiresSetup()` is false when the TOTP provider is absent.
`when@dev` switches TOTP and backup codes off for local convenience
(`config/packages/scheb_2fa.yaml`), which also removes
`TotpAuthenticatorInterface`. Otherwise `TwoFactorSetupEnforcer` would send
every not-yet-enrolled curator to a setup page that cannot work, on every
request. `TwoFactorController::setup()` takes the service as nullable and, when
it is absent, renders `security/2fa_unavailable.html.twig`, which states the
live rule ("curators and admins must set up two-factor before they can reach
the moderation desks"). Nothing changes where the provider is on, and the
mandate itself is untouched: `isMandatoryFor()` answers true for every curator.

**Re-authentication is asked for at the moment of commitment, never at the
door.** With remember-me on a 7-day lifetime, a returning rider holds a real
user object while being only `IS_AUTHENTICATED_REMEMBERED`. Sending that rider
to `/login` merely to *look at* the curator application would make no sense
(owner: "why am I going to login when I want to apply for curation of a region
when I am already logged in"), and the same remembered session may change
settings, set a base location, propose places and upload photos, all
`ROLE_USER`. So `/join/{cc}` is `ROLE_USER` too, and the password is confirmed
**on submit**, only when the session is remembered:

- The field renders only for a remembered session; a fully-authenticated rider
  never sees it.
- A wrong answer writes nothing and re-renders the form with the rider's typing
  and their region selection intact: a typo costs the retry and nothing else.
- It is checked **before** the application limiter is consumed and guarded by
  `password_reauth` (5 per 15 min) rather than `curator_application` (3 per
  **day**), because charging failed passwords against the latter would cost a
  rider their ability to apply at all.

**Every password or second-factor check outside the login form shares one
budget, `password_reauth`** (5 per 15 minutes per user, security audit
2026-10-04): the curator application above, the password change and the
deletion request in settings (§8, §10), and replacing two-factor (§4). None of
these runs an authenticator, so login throttling and the account lockout (§3)
never see
them, and a remembered session would otherwise be an unmetered
password oracle. One budget for all four, so four doors are not four times the
guesses. A try is spent before the password is compared; past the budget the
answer is "Too many tries. Wait 15 minutes, then try again." and nothing is
compared or changed. The data export keeps its own daily allowance
(§11), which
is spent the same way and is already tighter.

**The login page's "already signed in" shortcut tests
`IS_AUTHENTICATED_FULLY`, not `getUser()`.** Tested on `getUser()`, a
merely-remembered visitor sent to `/login` by a page that demands a full
sign-in would be told *"you are already signed in"* and redirected home, with
no way through. A remembered rider gets the form, and the firewall's stored
target path carries them onward. This is what any future FULLY page will need.
`/register` keeps the plain `getUser()` test: somebody who is remembered does
not need an account.

**Boundary rule:** EasyAdmin `/admin` (`ROLE_ADMIN`) is dry record/user
administration only. Curator content review is the branded in-product
`/moderate` shell (`ROLE_CURATOR`): review happens within the product, never
in a back-office tool. See
[moderation-and-contribution.md](moderation-and-contribution.md).
Machine translation (DeepL) is a developer tool on the dev environment only,
never on `/admin`, staging or production ([translations.md](translations.md)
§7).

The desk is reached from the account chip's moderation group only
(owner 2026-09-06). The `/pages` directory is the public face of the site
and lists no moderator surface for anyone, curator included. Pinned by
`ContentPagesTest::testTheDirectoryNeverListsTheModerationDesk`.

## 6. Admin support desk (`/admin`)

EasyAdmin 5; every surface is double-gated (`^/admin` firewall rule **and**
`#[IsGranted('ROLE_ADMIN')]` on each controller).

**Every list shows the row's key (owner 2026-10-04).** Each admin list has an
"ID" column with the row's primary key, so a row on screen can be found in the
database: the five EasyAdmin lists (users, activity, reset requests, releases,
blog) start with `IdField`, the dashboard's recent accounts, the country
requests, moderator areas, moderation activity (the region's key) and coverage
runs have an ID column, a curator application shows `#<id>` on its row, a
withheld photo shows its `media_upload` UUID, and the moderator-areas and
coverage-run pages name the key in their title. An escalated submission
already reads "SUB-<id>". The country requests' area table has no key: an
area there is free text, not a row.

### 6.1 Support actions

All account state changes go through `App\Service\UserAdminService`: the
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
| Grant curator | lacks role | area picker: an explicit "All areas" tick, or chosen countries and regions (one of the two, never both or neither), then `ROLE_CURATOR` and the `moderator_area` rows in one transaction (`UserAdminService::grantCuratorWithAreas()`) | form page |
| Revoke curator | has role | remove it | yes |
| Grant admin | lacks role | add `ROLE_ADMIN` | yes |
| Revoke admin | has role | remove it (guardrails) | yes |
| Execute account removal | `deletionRequestedAt != null` | §6.3, the rider's own request: no statement of reasons, they asked | yes |
| Cancel pending removal | `deletionRequestedAt != null` | clear code + timestamp | no |
| Suspend account | not suspended now | form page: days (1 to 365), ground, facts, "this followed reports"; suspends and emails the statement of reasons (§6.8) | form page |
| Lift suspension | suspended now | ends the suspension now; nothing is sent | no |
| Remove account for a breach | always | form page: ground, facts, "this followed reports"; removes as §6.3 and emails the statement of reasons (§6.8) | form page |
| Moderator areas | has `ROLE_CURATOR` | replace `moderator_area` rows (contract in [moderation-and-contribution.md](moderation-and-contribution.md) §9) | form page |

**Transport convention (standing rule):** every state-changing support action
is a **POST-only `#[AdminRoute]` with a CSRF token** (shared token id
`UserCrudController::CSRF_TOKEN_ID = 'ea-user-support'`; the action renders
through a custom form template instead of EA's GET link, and the handler
re-validates the token, defence in depth). Enforced by
`testEverySupportActionRouteIsPostOnly` in
`web/tests/Admin/UserAdminActionsTest.php`. The four sanctioned exceptions are
`moderator_areas` and `grant_curator` (the area picker, see
[moderation-and-contribution.md](moderation-and-contribution.md) §9.4), and
`suspend` and `remove_for_breach` (the ground-and-facts form, §6.8): each is a
genuine intermediate form page, GET (render) + POST (submit, same CSRF
token).

**A second door onto the same grant path.** Curator applications
([moderation-and-contribution.md](moderation-and-contribution.md) §11) let a
rider apply to curate a country instead of an admin picking one from the User
CRUD. Approving one calls the same `UserAdminService::grantCurator()` (same
`grant_curator` audit row) and, in the same transaction, also creates the
requested `ModeratorArea`. No new authority model: granting
`ROLE_CURATOR` stays admin-only either way, and the application flow is scope
selection glued onto the existing grant, not a parallel one.

### 6.2 Audit trail: `AdminActionLog`

`App\Entity\AdminActionLog` (table `admin_action_log`): `actor` (nullable
User; `null` means *system*, reserved for future automated actions),
`action` (string), `targetUser` (nullable, FK `ON DELETE SET NULL` so rows
outlive removed accounts), `note`, `createdAt`. Contract points:

- Every support action writes a row via `AdminActionLogger`.
- **Mutation and audit row are one transaction** (`wrapInTransaction` in
  `UserAdminService::commit()` / `removeAccount()`): they can never diverge:
  the trail can neither miss a change nor claim a removal that rolled back.
- On removal the row is written first (target still resolvable) and its
  `note` reads `Removed account #<id>`: the trail names the removed account by
  id, never by email address, because an erased address kept in the trail
  would be personal data coming back. `Version20261009040000` stripped the
  address from the notes written before (`Removed account: <email>` became
  `Removed account`).
- Surfaced as a read-only EA CRUD ("Activity" menu;
  `AdminActionLogCrudController` disables NEW/EDIT/DELETE/BATCH_DELETE).

### 6.3 Account removal: anonymize, not delete

Commons rule: contributed data is community-owned and **never cascade-deletes
with an account**. Removal targets personal data only. Owner 2026-10-09:
deleted personal data does not come back, and contributions stay without
naming the person. After a deletion no row anywhere holds the account's id:
what is personal and worth nothing without the person is deleted, and what
stays as a contribution or as a record loses the id (NULL).

- Admin removal (`UserAdminService::removeAccount()`), self-service
  deletion (§10), the dormancy and unconfirmed sweeps (§6.5, §6.7) and the
  operator's console command `app:user:purge <email>...` (dry run by default,
  `--force` to act, refuses the last `ROLE_ADMIN`; for accounts that never
  asked, such as test riders left on a deployed database) all route through
  the **shared seam** `UserDeletionService::purge()`: run every
  `UserDeletionHookInterface` pre-delete hook, then remove the `User` row.
  An account is erased whole or not at all: the hooks' statements, the
  removal and the flush run in one transaction (`UserDeletionService::erase()`
  for self-service deletion, `wrapInTransaction` on the admin paths). The
  sweeps and `app:user:purge` erase each account in its own transaction
  (`UserDeletionService::eraseInBatch()`): one that fails is rolled back, logged
  by account id with the exception class and SQLSTATE (never the address or the
  driver message, which can quote it), and the run goes on with the next
  account, then reports the failures and exits with a failure. Pinned by
  `AccountErasureTest::testAFailedDeletionLeavesTheAccountAndItsRowsUntouched`
  and a failure test in each sweep's and the command's test.
  `tests/Account/PurgeUserCommandTest.php` pins the command. Removal is a hard
  delete of the row and the public profile URL 404s naturally. Contributed
  content is written as hooks on this seam: dissociated and retained, never
  deleted with the account. The hooks:
- **`App\Service\ResetPasswordCleanupHook`** purges the user's
  `reset_password_request` rows via the bundle's `removeRequests()` before
  the row delete. `reset_password_request.user_id` is a plain restrictive FK
  (no `ON DELETE` action, `Version20260628225933`), so without the hook every
  deletion path would throw an FK violation for a user with a live reset
  request (pinned:
  `AccountDeletionTest::testConfirmDeletionSucceedsWithPendingResetRequest`).
- **`App\Media\MediaDeletionHook`** drops the rider's unmoderated photos and
  anonymises the credit on approved ones, by the rider's `keepMediaCredit`
  choice (photo-uploads.md §6).
- **`App\Translation\TranslationDeletionHook`** clears the rider's identity on
  licensed translation rows and keeps the strings (translations.md §3.2).
- **`App\Moderation\ContributionDeletionHook`** (owner 2026-10-01: messages
  about contributions live as long as the account): deletes the rider's
  rejected and withdrawn submissions and dismissed route corrections (also
  while in the curators' Trash) with their threads, then every message
  addressed to the rider and every reply they wrote to a curator, on any
  channel. Approved and pending contributions, applied corrections and
  rejected route proposals stay. A submission under legal hold stays with its
  whole thread. Contract and table in moderation-and-contribution.md §8;
  pinned by `tests/Moderation/ContributionRetentionTest.php` for the rider's
  own deletion and the dormancy sweep.
  The same hook clears `user_message.sender_id` on what is left: a message
  the account wrote as a curator to another rider stays in that rider's
  inbox, from nobody.
- **`App\Vote\SeasonVoteDeletionHook`**: stores the result of every closed
  season list the rider voted in, then deletes all the rider's `season_vote`
  rows. A closed season keeps its totals, which name nobody; the open round
  loses the vote (route-domain.md §8d). Pinned by
  `tests/Vote/SeasonVotePrivacyTest.php`.
- **`App\Community\CommunityDeletionHook`** deletes the rider's curator
  applications, whatever their status (about text, OSM username, social link),
  and keeps their country and area requests as a count of one rider: the row
  loses the account, the note and the offer to curate.
- **`App\Catalog\CatalogDeletionHook`** (runs last, priority -100, because
  the hooks above find rows by the ids it clears) deletes the rider's rides on
  routes they proposed themselves, which never counted, and unlinks every other
  catalog reference (table below), including the curator ids inside a text
  proposal's payload (`_credit.by`, `_corrected.by`) and inside a region lead
  (`region.context_curated`, `userId` and `approvedBy` per language).
- **`App\Support\SupportDeletionHook`** keeps the rider's bug reports and
  clears their account, reply address and address hash; unlinks contact
  messages, which keep the address the sender typed until their own 24-month
  clock deletes the whole row (contact-and-support.md §4); and unlinks what the
  account handled or decided as a curator.
- **`App\Blog\BlogDeletionHook`**: a post outlives its author and names
  nobody.

What happens to each table holding a user id.

Deleted with the account:

- `curator_application`, the rider's own (CommunityDeletionHook).
- `route_ride` on a route the rider proposed (CatalogDeletionHook).
- Rejected and withdrawn `submission` rows, dismissed `route_suggestion` rows,
  and `user_message` rows to or from the rider (ContributionDeletionHook).
- `season_vote` (SeasonVoteDeletionHook), unmoderated `media_upload` rows
  (MediaDeletionHook), `reset_password_request` (ResetPasswordCleanupHook).
- By database cascade: `moderator_area`, `moderation_seen`,
  `curator_post_read`, `legal_notice_sent`, `unsent_statement` (a suspension's
  statement of reasons still waiting to be sent, §6.8), and `curator_post`
  addressed to the account.

Kept, with the account cleared (NULL):

- Evidence: `item_confirmation.user_id` (still counts toward verification) and
  `route_ride.user_id` (still counts as a ride).
- History: `change_history.changed_by` and `route_change_history.changed_by`;
  the edit stays and names nobody, and `0` stays the system actor.
- Contributions: `submission.user_id` (approved, pending),
  `route_suggestion.user_id` (applied, pending), `recommended_route.proposed_by`,
  `town_summary.edited_by`, a region lead's `userId`, `media_upload.user_id` on
  approved photos (MediaDeletionHook), `blog_post.author_id`.
- Administrator work: `users.suspended_by`, by its foreign key (a suspension
  the account decided stays on the other account, decided by nobody).
- Curator work: `submission.decided_by` / `escalated_by_id` /
  `authority_notified_by_id` / `trashed_by`,
  `route_suggestion.resolved_by` / `trashed_by`, `recommended_route.trashed_by`,
  `catalog_finding.decided_by`, `curator_application.decided_by`,
  `town_summary.approved_by`, a region lead's `approvedBy`, a text proposal's
  `_credit.by` and `_corrected.by`, `content_report.decided_by_id`,
  `media_upload.escalated_by_id` / `authority_notified_by_id` /
  `location_confirmed_by`,
  `media_moderation_event.actor_id`, `bug_report.handled_by_user_id`,
  `contact_message.handled_by_user_id`, `user_message.sender_id`.
- Requests and records: `country_interest.user_id` (with `note` cleared and
  `willing_to_curate` false), `bug_report.user_id` (with `reporter_email` and
  `ip_hash` cleared), `contact_message.user_id`, `consent_record.user_id` (the
  licence grant stays).
- By `ON DELETE SET NULL`: `user_message.user_id` (a held thread only),
  `admin_action_log` actor and target, `curator_post.author_id`,
  `curator_post_image.uploader_id`, `data_provider_change.changed_by`,
  `system_setting.updated_by_id`, and the translation rows' FKs.

Readers count an unlinked row: a route's independent rides are counted as
rows (one per rider is a constraint), with a NULL rider counted;
`ItemEvidenceResolver` treats `changed_by` and `submission.user_id` NULL as a
person's edit (`IS DISTINCT FROM 0`); the history drawer names nobody for NULL
and keeps the system label for `0`. A pending submission or correction whose
rider is gone can still be decided: no message is sent, no confirmation is
recorded from its answer, and the history credits nobody, never the curator.

`Version20261009040000` made the NOT NULL user columns nullable (schema only,
so its exclusive locks are brief). `Version20261009040100` applied the same
rule to every id left behind by deletions before it (an id with no `users`
row counts as a deleted account; `0` stays the system) and built
`idx_change_history_changed_by`, outside a transaction: each statement
commits on its own, the index is built `CONCURRENTLY`, and every statement
touches only rows still naming a missing account, so running it again
finishes a run that stopped halfway.

Pinned by `tests/Account/AccountErasureTest.php`: one account with a row in
every such table, as rider and as curator, deleted through
`UserDeletionService`; afterwards no column holds its id, the personal rows are
gone and the contributions are still there. The same test fails when a new
integer user-id column (`*user_id`, `*_by`, `*_by_id`, `author_id`,
`actor_id`, ...) has neither a foreign key to `users` nor a place in its list
of hook-cleared columns.
- Executing a removal the rider asked for is offered only when the user has a
  pending self-requested deletion (`deletionRequestedAt` set). Removing an
  account for a breach of the terms is offered on every account and is §6.8.

### 6.4 Guardrails (baked into `UserAdminService`, not optional)

- No self-targeting for revoke-admin / remove-account (`assertNotSelf`): an
  admin cannot lock themselves out of the panel.
- The last remaining `ROLE_ADMIN` can never be removed or demoted
  (`assertNotLastAdmin`): the system always has ≥ 1 admin.
- Violations throw `GuardrailViolationException`, surfaced as a danger flash:
  never a 500.
- Destructive actions carry a confirmation step (table above).

### 6.5 Dormant accounts

Owner's schedule, 2026-08-28, ported from a sibling BikeCoders product rather
than designed here.

| months with no sign-in | what happens |
|---|---|
| 12 | first notice |
| 22 | second notice |
| 23 | final notice |
| 24 | the account is deleted |

`App\Account\DormancyLadder` holds the schedule, `DormancySweep` applies it,
`app:accounts:dormancy` runs it. `/privacy` and `/terms` say only that we
email first, more than once, each time with the date, so the schedule can
change without a new version of either page (owner 2026-10-10). Meant for a daily timer on the worker host.

**Each notice fires inside a one-month window**, not "at or past". That is the
property worth protecting: an account already dormant for years when this ships
matches no window, earns no notice, and so can never satisfy the deletion
condition. Turning the sweep on cannot clear a backlog of old accounts. If the
windows are ever widened to "at or past", that safety goes with them.

**Deletion needs all three notices**, held as three timestamps rather than one
"current stage", because the question is "were they told, three times?" and a
single stage column cannot answer it. `/privacy` says in five languages that we
write first and give time; one missing notice means that was not done.

**Signing in clears all three** (`User::recordLogin()`), so a rider who returns
after the final notice starts again from zero.

**The remember-me cookie is a sign-in.** `LoginSuccessHandler` stamps the
clock after the login form, because it runs after 2FA. A rider who ticked
"remember me" never sees the form again: the seven-day cookie signs them in on
the first request of every browser session and is renewed on use, so counting
only the form would leave somebody who visits weekly with the `lastLoginAt` of
the last password they typed, and the sweep would warn and close an account in
daily use. `App\Security\RememberedLoginListener`
listens to `LoginSuccessEvent` for the remember-me authenticator alone and
calls the same `recordLogin()`. It is safe to count: scheb withholds the cookie
until 2FA has passed. Once per browser session, not per request, because the
authenticator only runs while there is no session token yet. Pinned by
`SecurityTest::testComingBackOnTheRememberMeCookieCountsAsASignIn`.

**A suspended account is not swept while it cannot sign in.** Its idle time
counts from the later of the last sign-in and the end of the suspension
(`DormancySweep::idleSince()`), so a suspension still running never makes an
account a candidate, and suspending clears the three notices as a sign-in does
(`User::suspend()`): notices sent before it named a closing date the
suspension moved.

**A deletion code that still works pauses the sweep, an abandoned one does
not.** A rider who asked to close their account is left alone for the code's
lifetime (`UserDeletionService::CODE_MINUTES`); after that the request is
abandoned and the account is a candidate like any other. Pinned by
`DormancySweepTest::testAnAbandonedDeletionCodeDoesNotExemptAnAccount`.

**Administrators are never swept.** `DormancySweep::candidates()` drops
`ROLE_ADMIN` accounts before any notice or deletion. There may be exactly one,
and a sweep that closes the last operator of the site is a lockout, not
housekeeping. Filtered in PHP rather than DQL because roles live in a JSON
column. Pinned by `DormancySweepTest::testAnAdministratorIsNeverWarnedNorDeleted`.

**Deletion is the ordinary deletion.** It calls
`UserDeletionService::purge()`, the same path a rider's own request takes, so
contributions are anonymised rather than cascaded and a photo licence consent
survives exactly as on a rider's own deletion. A second deletion path would
drift from the first. Each account goes in its own transaction (§6.3): one
whose deletion fails stays whole and is counted as failed, and the command
exits with a failure. A notice is recorded right after its email goes, so a
later failure in the same run cannot lose the record and send it again.

**Dry by default, `--force` to act.** For a command whose failure mode is
deleting somebody's account, the cron entry should have to opt in.

Guarded by `DormancyLadderTest` (the arithmetic, including that an unwarned
account is never deletable however old) and `DormancySweepTest` (the
consequences, including that a dry run changes nothing).

### 6.6 Secret fields and diagnostics

**Hard rule, never rendered and never editable in any admin surface:**
`password`, `totpSecret`, `backupCodes`, and
`ResetPasswordRequest.hashedToken`/`selector`. The User CRUD exposes only:
email, displayName, roles, emailVerified, twoFaEnabled (read-only),
lockedUntil, suspendedUntil (read-only, §6.8), publicProfile, createdAt, plus a read-only moderator-areas
descriptor on detail. Roles/emailVerified/lockedUntil display but are not
form-editable: changes go through the audited actions only.

`ResetPasswordRequestCrudController` is a read-only diagnostics list (target
user, requestedAt, expiresAt) whose only permitted mutation is a single-row
Delete to purge a stuck request; token/selector are never exposed.

The dashboard (`DashboardController` + `AdminDashboardStats`) shows only real
counts, all derived live from the `users` table (members / curators / admins /
unverified / locked / pending-removal + 10 recent signups). Honesty rule: no
fabricated or stale stats on the dashboard: a metric joins only when its
source is real.

It also lists the daily jobs with their last good run (`App\Ops\JobHealth`),
and shows a red warning at the top while one is late (operations.md §1).

### 6.7 Unconfirmed accounts

**An account not confirmed within 7 days is deleted** (owner, 2026-09-29).
`App\Account\UnverifiedSweep` finds them, `app:accounts:purge-unverified`
runs it, daily on the worker host (timer `purge-unverified`, defined in the
private infrastructure repository). Dry by default, `--force` to act, like
the dormancy sweep.

An unconfirmed account is an address somebody typed, not yet a person who
joined. Bots sign up strangers, and without the sweep every such row would keep
a stranger's address on file for good. That is also why, unlike §6.5, **no
warning mail goes out**: the only address to write to is the stranger's.
`/privacy` states the rule (`privacy.retention_unconfirmed`), and the
check-your-email page and the resend page both say it at the moment it
applies.

Deleted when all of these hold:

- `emailVerified = false`;
- `lastLoginAt` is null. An account that signed in did so before sign-in
  needed a confirmed address (§2); it is a person, with a history, and is
  left alone. It can still confirm through `/verify/resend`;
- `createdAt` is at least 7 days old (`UnverifiedSweep::DAYS`);
- it holds no role beyond `ROLE_USER`. Operators make elevated accounts by
  hand (`app:user:create`), never through the sign-up form.

Deletion is `UserDeletionService::purge()`, the same path as §6.5 and a
rider's own request, one account per transaction (§6.3). Pinned by
`UnverifiedSweepTest`.

### 6.8 Suspension and removal for a breach: notice and reasons (DSA Article 17(1)(c))

An administrator may suspend an account for a number of days, or remove it,
when it is used for abuse, to break the terms, or to harm the Commons or the
people who use it. Either decision owes the holder a statement of reasons
([content-reports.md](content-reports.md) §7), so each opens a form on the
User CRUD (`admin/user_account_decision.html.twig`) that asks for what the
statement states: the **ground** (`StatementGround::forAccounts()`: abuse,
spam, unlawful, misuse of the service, false account details, untrue
contributions, advertising, personal data about others; terms §11,
`terms.suspension_admin`, names three as examples and says the ground is
always named, so this list is the full one), the **facts** in the
administrator's own words (required, at most 2000 characters, read by the
holder as written), and whether **reports** led to it.

**Suspend** (`UserAdminService::suspend()`): 1 to 365 days
(`SUSPENSION_MAX_DAYS`). Writes `suspended_until` (now plus the days),
`suspended_at`, `suspension_ground`, `suspension_facts` and `suspended_by` on
the account, and the audit row `suspend` with the end and the ground but not
the facts (`until <date> UTC · ground=<ground>`), in one transaction. Then,
after the commit, the statement of reasons goes to the account's address
(`StatementOfReasonsMailer`): "Your account is suspended", the end date in the
holder's language and time zone on the 24-hour clock, the ground, the facts,
"An administrator made this decision", and "reply to this email". Guardrails
as §6.4: never one's own account, never the last administrator.

**While it runs**, signing in is refused, by password and by the remember-me
cookie: `App\Security\SuspensionChecker`, a post-auth user checker chained
after `VerifiedEmailChecker` (`security.user_checker.chain.main`), so only
somebody who typed the right password learns that the account is suspended
and until when ("This account is suspended until ...; we sent the reasons to
its email address"). The right password on a suspended account is not counted
as a failed attempt (`LoginThrottleListener`). A session already signed in
ends on its next request that reads the user:
`App\Security\SuspendedSessionProvider` decorates the user provider and
answers "no such user" when the session's user is reloaded, which drops the
session without making the firewall read it on pages that never ask who is
signed in, and leaves the reason for the sign-in page. Contributions stay
where they are.

**It ends by itself** at `suspended_until`: nothing runs, the check compares
with the clock. **Lift suspension** (`liftSuspension()`, POST) ends it now by
setting `suspended_until` to the moment of lifting, audited
`lift_suspension`; nothing is sent. The five columns stay as the record of the
last suspension until the next suspension replaces them or the account is
deleted; `suspended_by` is cleared by its foreign key when that
administrator's account is deleted (§6.3, pinned by `AccountErasureTest`).
They are in the rider's data export (`account.json`: `suspended_until`,
`suspended_at`, `suspension_ground`, `suspension_facts`; not who decided,
§11). The dormancy sweep counts idle time from the end of a suspension (§6.5).
`Version20261009050000` adds the columns.

**Remove for a breach** (`removeAccountForBreach()`): reads the address,
display name, language and time zone, then removes the account through
`UserDeletionService::purge()` exactly as §6.3, with the audit row
`remove_for_breach` (`Removed account #<id> · ground=<ground>`, no address).
After the removal commits, the statement of reasons goes to the address read
before it: "We removed your account", what the removal deletes and keeps, the
ground, the facts and how to contest it. A failed purge sends nothing. The
reference in both statements is `ACCOUNT-<id>`.

**When the email does not go out.** Both decisions stand, and the statement is
not lost: after a removal the address and the facts exist nowhere else.
`suspend()` and `removeAccountForBreach()` return whether the mail went out
(`App\Moderation\UnsentStatements::send()`); when the transport refuses it,
the whole statement is kept in `unsent_statement` (address, name, language,
time zone, and the statement as stored on a message: decision, ground,
facts, reference, end of a suspension). The administrator who decided is
told at once, with a warning instead of the success message ("The account is
removed, but the email with the reasons did not go out. The statement waits
under Unsent statements, with the address and the facts: send it again from
there."). **Unsent statements** (`/admin/unsent-statements`, a red count in
the admin menu while any wait) lists each one whole, oldest first, with
**Send again** (delivered, it leaves the list, audited
`statement_resent <reference>`; refused again, it stays with one more try
counted) and **Discard** (when the address cannot be reached any more; the
address goes, the audit row `statement_discarded <reference>` stays). The
audit names the reference, never the address. `user_id` is the account while
it exists, with a foreign key that deletes the row with the account; NULL
after a removal. Ruling (2026-10-09): an outbox, not only a warning, because
a warning alone loses the statement the moment the administrator navigates
away; a removed account's address is then kept only until the statement is
sent or discarded, which is what Article 17 asks of us. Migration
`Version20261009050100`. Pinned by `tests/Admin/UnsentStatementTest.php`.

Pinned by `tests/Account/AccountSuspensionTest.php` (signing in by password
and by the remember-me cookie alone is refused while it runs) and the
suspension tests in `tests/Admin/UserAdminActionsTest.php`.

## 7. Public rider profile

`App\Controller\RiderProfileController`, route `rider_profile` =
`/{locale?}/riders/{uuid}` (UUID regex requirement, `PUBLIC_ACCESS`).

- **UUID-only in URLs**: never the integer id (non-enumerable; UUIDv7,
  unique constraint `uniq_users_uuid`).
- **Exists only while `publicProfile` is ON.** Toggle off, unknown uuid, and
  hard-deleted accounts all 404 identically. Opt-in public posture: profiles
  are public contributors only: no private/anonymous profile pages;
  provenance is kept, identity is opt-in.
- **The /contributors wall rides on the same toggle.**
  `App\Catalog\ContributorWallProvider` lists riders with `publicProfile`
  ON **and** ≥1 public contribution (approved submissions + served route
  proposals), alphabetical/non-ranked, each row linking `rider_profile`;
  per-row counts are the same figures the profile page already exposes
  (facts, climbs, photos, checks, routes).
  Riders without the toggle never appear regardless of volume. The page's
  six stat cards are aggregate site totals over ALL contributors
  (opt-in or not): aggregates credit the crowd without identifying anyone.
  They count facts, contributors, routes, climbs (approved `type=new`,
  letter N), photos shared and on-the-spot checks, each under the same
  boundary as the profile tiles below.
- **A curator's wall row carries a "Curator" chip** (owner 2026-09-06):
  `ROLE_CURATOR` or `ROLE_ADMIN` in the stored roles, read in
  `ContributorWallProvider::wall()`. It is an office, not a score: no
  count, no effect on the alphabetical order, and only on riders who are on
  the wall anyway (opt-in plus a public contribution). The public profile
  page still shows no role (the frozen exposure list below is unchanged).
  Pinned by `ContributorsPageTest::testACuratorRowCarriesTheCuratorChipAndARiderRowDoesNot`.
- **"View as others see it" is the real page**: settings links the rider's own
  public URL (`target="_blank"`) with **zero owner special-casing**: what the
  owner sees is byte-for-byte what others get. When the toggle is OFF the link
  is replaced by a hint; there is no preview of a non-existent page. Settings
  is the link's only home (deliberately removed from the profile dashboard).
- **Shown** (allow-list): display name, country flag (if set), member-since
  (month + year from `createdAt`), riding-preference chips, the contribution
  counters (below), and up to **10** most recent **Verified** routes
  by name, each deep-linked to the map via `?route=` (limit is the `findBy`
  third argument in `RiderProfileController::show()`).
- **Contribution counters (owner boundaries 2026-08-13).**
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
- **Pending-route count spans both pre-Verified states** (`Submitted` +
  `Unverified`), but Submitted route **names never render** (un-vetted);
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

One continuous logged-in environment (dark identity bar + section tabs),
included by `/account/contributions`, `/account/settings`, `/2fa/setup`, and the moderation pages.
The same account chip (`partials/_account_chip.html.twig`) is used everywhere,
including the public nav; the language switcher (`partials/_lang_menu.html.twig`)
lives in the shell header. Its menu has up to three groups, each a solid header
over a light tint of the same hue: Personal (spruce), Moderation (clay) and
Admin (ochre, one "Admin panel" link). `atlas.css` and the map's own copy in
`map.css` draw them alike.

- **Personal mode** tabs: Dashboard · Contributions · Votes · Confirmations ·
  Scout · Translate · Messages · My bugs · Settings, under a "Personal" label (Saved
  stays hidden until it has a backend). The account chip lists Dashboard first
  too. A rider's proposed routes have no tab of their own: they are
  contributions, so the Contributions tab lists them, and its kind filter
  carries a **Routes** chip (`?letter=R`, first after All) that shows only
  them. Example: a rider with two climb edits and one proposed route sees
  All · Routes · Climbs; Routes lists the route, Climbs hides it.
- **Moderator mode** (every `/moderate` page): the bar carries **only** the
  moderation tabs (Dashboard, Submissions, Routes, Takedowns, Reports, Bugs,
  Translations, the curator room, each with its open count) on its own darker
  colour under a **MODERATION** label: no user items. Curators cross between
  the two modes via the account-chip dropdown in both directions. The label
  block has two lines: MODERATION, then the curator's areas (assigned area
  names, or "All areas"), read by `moderation_scope_names()`. The line shows
  on the desks that filter by area (Dashboard, Submissions, Routes, Data,
  Regions); on every other desk it is invisible (`visibility:hidden`) but keeps
  its room, so the block is the same width on every desk and the tabs never
  shift. A long list is cut with an ellipsis and reads in full on hover.
  The less-used desks (Data findings, with its count, then Regions,
  Providers, Rulebook, Marker grammar, Measured traffic) sit under a **More** dropdown at the
  end of the strip, so the bar
  fits a laptop screen; More is lit while one of them is on screen. Example:
  a curator for Wallonia sees "MODERATION / Wallonia" on
  `/moderate/submissions`, and only "MODERATION" with a lit More on
  `/moderate/providers`.
- Dashboard **Contributions pane** renders the user's real submissions
  (every status but `trashed`: a rejected or withdrawn one stays as long as
  the account, and one in the curators' Trash never renders, see
  [moderation-and-contribution.md](moderation-and-contribution.md) §6, §8)
  and route proposals as ONE list, newest first, under one `?page=` pager (20
  per page, `ProfileController::contributionsPage()` pages the SQL union of
  both, then loads that page's rows). Owner-reported 2026-09-30: with the
  routes in a second list under the places' pager, a rider with a page of
  place submissions never saw the route they had just proposed. Every row is
  the shared record card (`.q-item`: type/route tag, title, region · country,
  date, Map/Edit links, status pill, then the diff, the decision note and the
  conversation under it), the same card the curator's desk renders, from the same
  stylesheet (moderation-and-contribution.md §5.2, owner 2026-08-25). Every
  pane opens with the shell's page head (eyebrow "Personal · <name>", a real
  title) and offers the cards/list density switch; empty
  state is the `account.contributions_empty` key, shown only when the rider
  has neither submissions nor route proposals in the current filter. The kind
  chips render only for kinds the rider has, and only when there are two or
  more. Routes (letter R) is the rider's route proposals plus any route
  submission; another kind's chip and the Withdrawn view hide route proposals,
  which have no withdrawn state. A route card is the same card with the R
  type icon, the tag "Route", the route's name, its region, the proposal date
  and the route state pill. It carries the anchor `#route-<id>`, which the
  propose-route receipt links to ([route-domain.md](route-domain.md)).
  **Map ↗** opens `/map?route=<id>` in a new tab for a submitted, unverified
  or verified route: the map shows a submitted route to its proposer and to a
  curator who may moderate it, never to anyone else (map-and-search.md §8).
  **Edit** opens `/propose-route/<id>/edit` while the route is `submitted`,
  and is gone once a curator has decided (route-domain.md §4.6). A route card
  has no **Withdraw**: a route proposal has no withdrawn state
  (catalog-data-model.md §4). The pane closes with a
  **Curator applications** section: the user's own
  `curator_application` rows with status pills (pending/approved/declined/
  withdrawn), or, when none exist, a door to the regions directory, so
  "where is my request?" always has an answer on the post-login landing.
  The **Votes** pane opens with a door to the season ballot (`/vote`, "Vote
  for the best of your region"), because the pane lists votes and the
  ballot is where they are cast. It renders the user's own season votes (`season_vote`,
  every category, with the round, the choice number, "Not submitted, does not
  count" on a draft, and the bike; the only surface that shows *what* was
  voted for, voter-only, route-domain.md §8d). Empty, it says "No votes yet.
  Pick a region on the ballot and vote for its best." The **Confirmations**
  pane (`?tab=confirmations`, its own tab: a confirmation
  says a place is real, not that it is the best, owner) lists "Worth a look
  near you" and the user's `item_confirmation` place confirmations with
  stance pills (potable / not potable / still there,
  [moderation-and-contribution.md](moderation-and-contribution.md) §1.6);
  empty, it says how to confirm a place on the map. The **Saved-regions** pane says plainly that saving is not built yet
  and links the regions directory.
  Every dashboard pane renders real rows only. Empty panes use
  the shared `.empty-state` block (`account/_shell_styles.html.twig`):
  left-aligned message plus a bordered door link (the same block every desk
  uses), with `.dbody`/`.dmin` holding a 46vh minimum so sparse account pages
  keep their vertical shape. `/account/messages` and `/account/settings` open with the same
  head and container; `/account/messages` rows are the same card with `msg-*` state
  hooks (new, mine) layered on.

### Rider dashboard (`App\Controller\DashboardController`, `/account`)

One read-only page with the rider's own open counts, route `dashboard`,
`ROLE_USER`. It has the curator dashboard's look
(moderation-and-contribution.md §5.0): the tile partial
`account/_dashboard_tile.html.twig` and the styles
`account/_dashboard_styles.html.twig` are shared by both pages.

**The rider area lives under `/account`**, the way the curator area lives
under `/moderate`. Both start with their dashboard at the root. One access
rule (`^(/[a-z]{2})?/account(/|$)`, `ROLE_USER`) covers the whole area. The
tools (`/translate`, `/scout`) stay where they are: they are workspaces, not
pages about the account.

| Page | Path | Route |
|---|---|---|
| Dashboard | `/account` | `dashboard` |
| Contributions | `/account/contributions` | `profile` |
| Bug reports | `/account/reports` | `my_reports` |
| Messages | `/account/messages` | `messages` |
| Settings | `/account/settings` | `settings` |

The older paths (`/profile`, `/profile/reports`, `/messages`, `/settings`)
redirect (301) to these with their query string
(`App\Controller\MovedAccountPathsController`), so a link in a sent email or
a bookmark still lands. Example: `/fr/messages` goes to
`/fr/account/messages`.

| Block | Content | Links to |
|---|---|---|
| Waiting for you | Questions on your contributions (`submission` rows at `needs_info`), questions on your translations (latest proposal per key at `needs_info`), unread messages. A tile with a count has a red edge. | `/account/contributions`, `/translate/mine?status=needs_info`, `/account/messages` |
| Your work | Contributions at `pending`, route proposals at `submitted`, translations at `pending` (latest per key), own bug reports still open (`BugStatus::open()`) | `/account/contributions`, `/account/contributions`, `/translate/mine?status=pending`, `/account/reports` |
| Your last contributions | The 5 newest submissions with their status pill, leaving out one in the curators' Trash as `/account/contributions` does | `/account/contributions` |
| Worth a look near you | The 5 stale places inside the base area (`App\Account\StaleNearby`, the same query `/account/contributions` uses; moderation-and-contribution.md §10.1a). Without a base location: a line and a link to `/account/settings`. | the map, per place |

A zero shows the drawn check mark, named "Clear" for a screen reader. The
"Waiting for you" lead shows only when one of its tiles has a count.

Example: a rider with 2 pending submissions and 1 at `needs_info` sees a red
"1" on the first Questions tile and "2" on the Contributions tile.

The page marks nothing read: the Messages badge keeps counting until the
rider opens each message (moderation-and-contribution.md §7.5a).

**Where a sign-in lands.** The first sign-in after the address is confirmed
lands on `/account/settings`, the rider's profile ("Your profile"): a new
rider has nothing to count yet, and the first things worth doing are here (the
display name, a public profile or not, a base location). The dashboard's tiles
mean little on day one (owner 2026-09-30: "maybe a bit much info"). Every later sign-in
lands on `/account`. A saved target path (a gated page the rider was sent away
from) wins over both (§4, `LoginSuccessHandler`).

"First" is `lastLoginAt` null **or older than `emailVerifiedAt`**, not
`lastLoginAt` null alone. `lastLoginAt` is the dormancy clock (§6.5), and on
some accounts it holds a time from before the confirmation: migration
`Version20260828130000` starts it at `created_at` for every account that
existed when it ran, and accounts created before §2's confirmation rule could
sign in unconfirmed. With the null test alone those riders would confirm, sign
in and land on the dashboard (owner report 2026-09-30). `FirstSignInLandingTest`
pins both paths, through the real sign-up form and the real confirmation mail.

Example: a rider signs up, verifies, and signs in: `/account/settings`. They sign out
and sign in the next day: `/account`.

### Settings (`App\Controller\SettingsController`, `/account/settings`)

Two tabs:

- **Profile** (default): account status; Identity section (display name,
  with under it the name others see (owner 2026-10-03): "Others see:" and,
  while Public profile is off, the anonymous name `rider#xxxxxxxx` with the
  display name dimmed after it, the other way round while it is on;
  `settings/public-profile.js` flips it with the switch and follows the
  field as it is typed. It says "Anonymous name", because "private name"
  reads as the rider's real name;
  country, language (the languages this deployment serves, from
  `App\Routing\Languages`, dev-environment.md §7 i18n), base location); Riding preferences (preference chips,
  §9); Public profile section (toggle, view-as link/hint; the link follows the SAVED switch, and while
  the switch differs from it the line says what Save profile will do,
  `settings/public-profile.js`); the notice that says what the switch does:
  on, the display name, contribution counts and verified routes are public;
  off, others see only the anonymous name. There is no per-contribution
  choice, so the notice never offers one.
- **Security**: password change, 2FA block, danger zone (account deletion §10).

Contract points:

- **Server picks the initial tab**: `?tab=security`, or a failed password-form
  submission (the 422 re-render must show the tab holding the errors);
  everything else defaults to Profile. Pane switching itself is client-side.
- Password-change and both deletion-flow redirects target
  `settings?tab=security` so flashes land on the visible tab.
- Password change requires the **current password** (checked against the
  hasher) before the new one is accepted. The check spends a try from
  `password_reauth`, shared with the deletion request below
  (§5).
- **Email is read-only** in settings with a contact-support note: an email
  change would require re-verification (EmailVerifier token flow); the
  simple, honest option is documented in the controller docblock.
- Saving settings applies the (possibly changed) locale to the session
  immediately; clearing it falls back to browser/site default.
- Preference checkbox groups use the **chip-check pill pattern** (label wraps
  the input; `:has(input:checked)` drives the active look): the established
  styling shared with the propose-route form.

### Support playbook: manual email-change requests

This playbook is mirrored as an admin page: **/admin → Playbooks → Email
change** (`DashboardController::emailChangePlaybook`,
`admin/playbook_email_change.html.twig`) so the script sits in front of the
operator executing the change: this section stays the canonical text; keep
the two in sync. The menu section is deliberately plural: future operator
playbooks slot in beside it.

A manual flow is only safer than self-serve if support actually verifies:
otherwise it is the same account-takeover vector with a human rubber stamp.
"Legit" means proving control of the account's **existing anchors**; this
platform has exactly three: the old mailbox, the password, and (when
enrolled) the TOTP factor. Whoever handles the `info@` mailbox follows this
script, in order:

1. **Never trust the request mail itself.** From-headers are spoofable, and
   "writing from my new address because I lost the old one" is the standard
   opening of an attack. Request content proves nothing: display name,
   contributions and join date are all public on rider profiles.
2. **Anchor 1, the old mailbox:** reply to the address **on file** (typed
   from the admin panel, never reply-to) with a one-time confirmation code
   and require it back. If they can receive there, the change is low-risk.
3. **Anchor 2, a logged-in session:** if the old mailbox is claimed dead,
   dictate an in-account action ("set your riding radius to 120 km", "paste
   this code into your display name for an hour") and verify it happened.
   That proves password possession: and for 2FA-enrolled accounts it
   implicitly proves the TOTP factor too, since login required it.
4. **No anchor left** (can't receive at the old address AND can't log in) =
   account recovery, not an email change, and there is no honest way to
   distinguish owner from attacker. The safe answer is "create a new
   account". Refusing here is the point of the manual flow, not a support
   failure.
5. **After verifying, still hedge:** notify the old address with a
   "this wasn't me" contest window and delay execution 48–72 h; record the
   change through the audited admin path (the `UserAdminService` audit-note
   pattern of the account-support desk, §6) so there is
   a trail.

Rule of thumb: **urgency is a red flag, never a reason to skip a step**:
the legitimate owner survives a 48-hour delay; the attacker's window
usually doesn't.

## 9. Display-name identity and rider preferences

### Display names are labels, not identifiers

**Display names are NOT unique.** Two riders may both be called John Doe,
because two riders genuinely are. Refusing the second amounts to telling
somebody their own name is a stranger's property, and a name is exactly the
field a rider is most likely to want their real one in. The `uuid` is the
identity, and always was.

- No uniqueness constraint, no `UniqueEntity`, no canonical shadow column:
  a canonicalized copy would imply that names identify accounts.
- Nothing looks a rider up by name. Display names are read for display and
  never used as a key, so non-uniqueness costs no lookup anywhere.
- **Disambiguation is the `uuid`'s job, and every surface already uses it.**
  The public profile is `/riders/{uuid}`; the attribution link embedded in
  contributed photos points at a uuid-keyed page
  ([photo-uploads.md](photo-uploads.md) §1.3c, §5d); a curator desk names a
  rider by display name only when that rider's profile is public, and
  otherwise by their stored pseudonym (`DeskRider::of()`, "The rider
  pseudonym" below). Curators are named to
  each other by display name whatever their profile setting (History, content
  reports, the takedown desk, the curator room: `DeskRider::colleague()`,
  [moderation-and-contribution.md](moderation-and-contribution.md)
  "Curator-facing naming"); the name links to the uuid-keyed profile only
  when that profile is public. Admin
  lists that show a name show the email beside it.
- A name is **stored exactly as typed**: `setDisplayName()` does no
  normalization of any kind.

### The rider pseudonym (owner, 2026-09-30)

A rider who has not made their profile public is shown as `rider#` and eight
characters, such as `rider#k7m2x9qp`: on the curator desks, in the public
change history, in translate mode and in the rider's own data export
(`account.json`, `pseudonym`).

- **Random, stored once.** `users.pseudonym` holds the eight characters:
  Crockford base32 in lower case without `i`, `l`, `o` and `u`
  (`0123456789abcdefghjkmnpqrstvwxyz`), so 32^8, about 10^12, names. The
  `User` constructor draws one (`RiderPseudonym::random()`, `random_int`), so
  every way an account is created (registration, `app:user:create`, fixtures)
  gets one, and the `PrePersist` callback draws again while the value is
  already stored by another account. The column is `NOT NULL`, unique
  (`uniq_users_pseudonym`) and held to the format by `chk_users_pseudonym`.
  Nothing computes it from the id or anything else.
- **It never changes.** Not when the profile goes public or private, not when
  the display name changes. There is no setter.
- **One class writes the handle**: `RiderPseudonym::handle($stored)`
  returns `rider#` and the stored value, and null when there is none. It takes
  no user id, and no code computes a handle from one.
- **A removed account** has no row, so no stored pseudonym and no handle. Its
  work that stays (submissions, change history, route proposals and
  suggestions keep the plain user id, §6.3) shows one fixed, translated label,
  never linked: "a removed rider" (`moderate.rider_removed` on the curator
  desks through `moderate/_rider_name.html.twig`; `map.d_rider_removed` in the
  map drawer's change history and pending card). The data layer carries the
  name as null (`DeskRider::of()`, `SubmissionQueue` `who`,
  `ChangeHistoryView` `who`) and the view writes the label, so the publicly
  cached history body does not vary by locale.
- `Version20260930163712` added the column and gave every account that
  existed then a random value.

### The display-name hint (owner, 2026-09-30)

Sharing stays allowed, and a rider who wants a name of their own can see
whether it is free. Under the display-name field on the sign-up form and in
settings, a moment after the rider stops typing (500 ms), one line says
**"Another rider already uses this name. You can still use it."** when the
name is in use; a free name gets no line at all (owner 2026-10-03: the
"No other rider shows this name publicly yet." line was noise). It is
information, never a block: nothing
reserves a name, and the next rider may still choose it. A shared name reads
as a notice, in `--clay` and semibold (`[data-name-hint][data-state=shared]`
in `atlas.css`), never in the error colour.

- **Comparison:** `App\Account\DisplayNameCheck::inUse()`, trimmed and case
  insensitive (`LOWER(BTRIM(display_name)) = LOWER(:name)`), over every
  public profile (`public_profile`) except the asking rider's own. Names shorter than 2 or longer than
  100 characters (the forms' limits) get no answer. The query is a `COUNT(*)`
  over the table, so it costs the same whichever way the answer goes.
- **Endpoint:** `POST /register/name-check` (`display_name_check`,
  `DisplayNameCheckController`). Every answer has one shape,
  `{"inUse": true|false|null}`, with `Cache-Control: private, no-store`. No
  name, id or count ever comes back. `null` means no answer: 403 for an
  anonymous caller without a live sign-up stamp, 429 over the limit, 200 for a
  name outside the length limits.
- **Who may ask:** a signed-in rider, or an anonymous visitor on the sign-up
  page, proved by the form's signed timer (`FormGuard::STAMP`, checked by
  `FormGuard::stampIsLive()`: our signature, at most `MAX_SECONDS` old, no
  minimum dwell because the hint runs while the visitor types). The path sits
  under `/register` so the access rule that opens the sign-up pages opens it
  too.
- **Limit:** `display_name_check`, 30 per hour per connection
  (`anon-<hmac(secret|display_name_check|ip)>`), for everybody. A rider typing
  a name spends a few; a script asking about names in bulk runs out.
- **Without JavaScript:** the "check your email" page after sign-up shows the
  hint for the name just submitted when it is in use, and the settings page renders it
  server-side for the saved name on every visit, so it shows after a save.
  The sign-up form re-rendered with errors shows none: it runs before the
  proof of work and the registration limit, and would be a free way to ask.
- **Script:** `assets/js/name-hint.js`, markup `partials/_name_hint.html.twig`
  (`aria-live="polite"`, tied to the input by `aria-describedby`). A slower
  answer for an older spelling never overwrites a newer one; no answer hides
  the line.

**Why a name check is safe where an address check is not.** The sign-up form
never says whether an address has an account (§2, `ExistingAccountNotice`).
The hint keeps that: it reads only `display_name`, so typing an address into
the name field finds only a display name spelled like one, never the
`email` column. On the "check your email" page the hint is worked out from
the submitted name **before** the address is looked up, and the same value
goes to both answers, so a taken address and a new one still get the same
page. Only **public** profiles count (owner 2026-09-30): their names are
already printed on the map, on photo credits and on the contributors wall, so
the hint tells a visitor nothing the site does not show. A rider with a
private profile is never counted, so nobody can learn that their spelling is
in use. The stamp and the per-connection limit stop the endpoint being used as
a bulk list of public names.

### What a display name may look like

Not being an identifier does not make the field a free-for-all: it renders in
photo credits, on public profiles and in admin lists. Four rules, and **all of
them live on the `User` entity**, not on the two form types, so admin CRUD,
console commands and fixtures are held to them too: a rule only a form
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

- `App\Catalog\RidingStyle`, a 7-case, **style-only** string enum: Road,
  Gravel, Touring, Bikepacking, Trail, Urban, Leisure. **This enum is the
  contract for the map's Discipline chips** (consumed by
  [map-and-search.md](map-and-search.md)); hardware is deliberately excluded.
- `App\Catalog\BikeType`: the shared 8-case hardware enum owned by the route
  domain ([route-domain.md](route-domain.md) §8.3, including the
  General/Specialty split), reused here for the rider's own declaration.
  Specialty/accessibility semantics (`BikeType::isSpecialty()`) belong to
  route ranking, not accounts.
- Storage: two JSON columns on `users` (`bike_types`, `riding_styles`),
  default `[]`. Entity accessors are enum-typed and **silently drop unknown
  stored strings on read** (`tryFrom` + filter): a future enum rename can
  never fatal a page render; setters dedupe.
- Preferences live on the **Settings page only**; registration and the public
  profile form are untouched (the public *page* renders the chips, §7).
  Map prefiltering reads these values: contract in
  [map-and-search.md](map-and-search.md).

### Date notation

`App\Account\DateFormat`: `auto | ymd | dmy | mdy | long`, stored on
`users.date_format`, default `auto`.

**Three halves of one answer, and nothing else formats a date.**
Twig uses `cc_date`, `cc_datetime` and `cc_month`
(`App\Twig\DateDisplayExtension`), the browser uses `window.ccDate`,
`ccMonth`, `ccTime` and `ccDateTime` (`assets/js/cc-dates.js`, fed by
`window.CC_DATE`), and PHP outside Twig asks `App\Account\DatePreference`
for the ICU pattern (the admin screens' `DateTimeField`s, through
`Controller\Admin\RiderDatedFields`). Anything that writes a date itself
writes it in a format the rider did not choose. `/map`, which does not extend
`base.html.twig`, emits `window.CC_DATE` itself, beside the units bridge. ISO
stays
where a machine reads it: `datetime="…"` attributes, the Atom feed, the data
export's own header. Pinned by `tests/js/rider-date-format.test.cjs`, which
also fails on a template printing a date-looking property with no filter.

**A separate preference from language, deliberately.** The two are genuinely
independent: plenty of people read a site in English and still expect
`01-08-2026`, and `2026-08-01` reads as a filename to most of Europe. Deriving
the format from the interface language would give those riders no way to say
so. `auto` is the default and means "whatever suits the language I am reading";
the other four are explicit and mean the same thing in every locale: the
pattern is fixed, only month **names** localise.

`App\Account\TimeFormat`: `auto | h24 | h12`, on `users.time_format`, is a
**second, independent** preference, never derived from the date order
(month-first does not imply twelve-hour): someone can want `01-08-2026` and
`2:30 PM`, or `08/01/2026` and `14:30`, and a clock convention guessed from a
date order is a guess about somebody's habits made from the wrong evidence.

`cc_datetime` uses one ICU formatter when **both** halves follow the locale, so
the language supplies its own connector; otherwise it formats each half and
joins them with a space, because ICU cannot mix an explicit pattern with a
style and either preference may be explicit while the other is not.

**Time zone.** `users.time_zone` is the zone the rider chose
(null is **Automatic**), `users.detected_time_zone` the zone their browser last
reported. A moment in time (`cc_datetime`, and `cc-dates.js` in the browser) is
written in the chosen zone, else the detected one, else UTC. Example: a rider in
Amsterdam on Automatic reads 14:30 UTC as 16:30 in summer. A zone that does not
exist is never stored (`User::setTimeZone()` stores it as automatic). The
settings field names the zone Automatic currently follows once the browser has
reported one ("Automatic (from your browser: Europe/Amsterdam)"). While the
choice is automatic, a signed-in page hands `cc-dates.js` a report address and
token (`data-cc-tz-*` on `<body>`, `CC_DATE.report` on the map), and the script
posts the browser's zone (`Intl…resolvedOptions().timeZone`) to
`POST /account/time-zone` (`TimeZoneController`, stateless `time-zone` token,
same-origin) only when it differs from the stored one, so a rider who travels
reads the zone they are in. No cookie carries it. A visitor who is not signed in
reads UTC: their pages may sit in a shared cache, which must never hold one
reader's zone. `cc_date` and `cc_month` stay zone-free: they print calendar
dates (a vote's closing day), which must not move a day for a reader west of
UTC. Both zones are account data: the privacy notice names them and the data
export carries them.

**Every human-readable date goes through one filter.** `App\Twig\
DateDisplayExtension` provides `cc_date`, `cc_datetime` and `cc_month`, and no
template calls `|date()` for display. A preference is only worth having if it
is honoured everywhere: a dropdown that fixes eight dates and
misses the ninth is worse than no dropdown, because the rider now believes the
site listens. `|date('c')` **stays** wherever it feeds a `<time datetime="">`
attribute: HTML defines that as ISO 8601, and it has nothing to do with what a
person reads.

Formatting goes through ICU rather than PHP's `date()`, because month names
have to come out in the page's language: the *page's*, not the rider's stored
locale, since a Dutch rider following a German link is reading a German page.

The client half is `assets/js/cc-dates.js` (`window.ccDate`, `window.ccMonth`),
driven by the same value handed over as `window.CC_DATE` in `base.html.twig`.
Without it, JS-rendered dates (the photo drawer's capture month, the consent
notice's agreement date) would disagree with server-rendered ones on the same
page, which is exactly the failure the preference exists to prevent.

### Distance and elevation units

`App\Account\DistanceUnit`: `km | mi`, stored on `users.distance_unit`,
default `km`. `App\Account\ElevationUnit`: `m | ft`, on
`users.elevation_unit`, default `m`. Migration `Version20260808220000`.

**Two preferences, not one imperial switch.** Miles with metres of climbing is
what most of Britain rides, and a single toggle would make those riders accept
a unit they never use to get the one they do. Same argument as date vs time
above: two habits, two columns.

**Display only. Nothing stored ever leaves metric.** The database, the API, the
GPX pipeline and every measurement in the Commons stay in kilometres and metres:
a dataset whose units depend on who is reading it is a dataset nobody can
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
| `cc_distance_suffix()` / `cc_elevation_suffix()` / `cc_area_suffix()` | nothing | the bare unit word, for axis captions and input labels |
| `cc_km_value()` / `cc_elev_value()` | metric | the converted **number**, for form fields and example placeholders |

A short horizontal distance follows the **distance** preference and lands in
feet, not fractions of a mile: "820 ft off the track" is a distance somebody can
picture, "0.16 mi" is not.

**Speed follows the distance preference, and has no control of its own.** A
rider who reads miles reads mph; a second setting could only let
the two disagree. `ccSpeed()` / `uSpeed()` take km/h and write `32 km/h` or
`20 mph`, whole numbers: a radar reading 31.6 km/h is not that precise. The
first consumer is Scout's overtake markers
([moderation-and-contribution.md](moderation-and-contribution.md), "Scout
intake").

Areas and densities move in opposite directions: a square mile is bigger, so a
country covers fewer of them and each holds more places.

**Units stay out of the translated strings.** Messages place an
already-formatted value (`'{n} climbing'`, `'%v% ascent'`, `'Length (%u%)'`,
`'{a} along · {b} off'`, `'between %min% and %max%'`) and never spell a unit
themselves. A string that spells its own unit cannot follow a preference.

The client half is `assets/js/cc-units.js` (`window.ccKm`, `window.ccM`,
`window.ccElev`, plus `ccKmValue`/`ccElevValue` and the reverse
`ccKmFromValue`/`ccElevFromValue`), driven by `window.CC_UNITS`. That bridge is
emitted **twice**: in `base.html.twig` for every ordinary page, and again in
`templates/map/index.html.twig`, which does not extend it. Without the second
copy the map would draw every distance in kilometres while the same rider's
moderation tables read miles. Map ES modules reach it through
`assets/map/units.js` (`uKm`/`uM`/`uElev`), which falls back to metric so
`node --test` can still import the leaf modules.

**The places a rider types or drags a distance** convert on the way in as
well as out:

- a climb's length, gain and gradients are **never typed**: they are measured
  server-side from the drawn line and the DEM (climb-elevation.md §4) and only
  DISPLAYED in the rider's unit, through `ccKm`/`ccElev` in `improve.js`
  (the measured block under the map and the review line), so no transformer
  is needed;
- the base-location radius slider: the **input stays kilometres** (that is what
  is stored and what the controller reads) and only the read-out follows the
  preference;
- the ride-check radius select: option **values** stay metres, because the
  server accepts only its own fixed set (`RideCheckService::ALLOWED_RADII`);
  the labels convert.

**No baked display strings.** A stored string such as `"2.2 km"` cannot follow
a preference (no formatter runs late enough), so lengths and gains are stored
as numbers in metres in `item.attributes`, never as pre-formatted `record` or
`headline` text. `app:climbs:recompute --write` re-measures climbs that have a
drawn line; `app:catalog:retire-baked-length --write`
({@see App\Catalog\Command\RetireBakedLengthCommand}) parses a baked `record`
"Length" row into the discrete `length` attribute for a point-only seed with no
line to measure. A **measured** length always wins; an unparseable value is
reported and left alone. Both are dry runs by default and safe to re-run.

**The steepest-ramp label carries no width.** The climb field is
`Steepest sustained (%)`: it says WHICH measurement it is ("max gradient"
invites comparison with a point maximum; Mur de Huy's famous ~26% is its
steepest hairpin, not its steepest sustained stretch), and a width in the label
could neither be interpolated into a msgid nor follow a rider reading in feet.
Every climb stores the window it was measured at (`steepWindowM`), and the
width travels with the value:
the drawer writes **"13% over 820 ft"** from that climb's own `steepWindowM`,
in the reader's unit, falling back to 100 m for rows that predate the
attribute. `BackfillAttributesCommand::LEGACY_LABELS` keeps the two retired
labels resolving to `maxGradient` so an older harvest re-import still lands.
A baked `Max gradient` stays deliberately unmatched: it is a point maximum
from somebody else's compilation, a different measurement.

One thing stays metric on purpose: an imported Wikidata *description* ("is a
2,200 m climb") is quoted source prose, not a field we render. Rewriting
somebody else's sentence is not unit conversion.

### The curating invitation on the landing pane

With a curator application in flight, this block answers "where is my request?".
**Without one, the reader is a rider, not a curator**: telling them they have
no application in progress states the obvious in somebody else's vocabulary,
under a heading ("Curator applications") that is not about them. So the empty
case is an invitation, and it answers the question a rider might actually have:
*does my own patch have anyone looking after it?*

Best evidence first: base region, else declared country, else nothing:

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
provinces, so those regions are not "uncovered": but they are not done either:
a region can have its own curators alongside the country's, and somebody who
actually rides there sees what a country-wide view never will. Collapsing the
two would either nag people whose area is handled or ignore people whose area
needs them.

Every state carries a way in, including the covered ones. There is more to do
than curating, and "we have someone" is not a reason to close the door.

### 9.4 How long a page is

`users.rows_per_page` (`App\Account\RowsPerPage`, migration
`Version20260809120000`) sits beside the unit and format preferences and is
display-only in exactly the same way.

**`auto` is the default, and it is not a number.** Each list keeps the size it
was designed around: 20 for messages because a message is a card, 25 for a
moderation desk because a queue item is a row of work, 60 for the contributors
wall because a wall row is one line and somebody scanning for a name would
rather scroll than click. Any single global default would have to be wrong for
two of those three. `25`/`50`/`100` override all of them at once, which is the
point: a rider who picks 100 is telling us about their screen and their
patience, not about our page design.

`App\Pagination\PageSize::resolve(int $surfaceDefault)` is the only reader.
Each list passes the size it was built for and gets back either that number or
the rider's choice: so the surface default stays in the CALL, the resolver
holds no opinion about how long a message list should be, and adding a paged
list never means editing it. A signed-out reader always gets the default;
there is nowhere to store a choice for them.

**Two doors, one setting.** It is on the Profile settings tab with the other
display preferences, AND in every pager. The second door exists because the
moment anyone *wants* a different page length is the moment they are looking at
a pager, and sending them off to find a settings tab is the kind of
correct-but-useless routing that means the setting never gets changed. The
pager's control POSTs to `settings_rows_per_page`, writes the same column, and
returns to the list: via a submitted `back` field, not `Referer`, and only
relative paths are honoured (the strict allowlist regex shared with
`LocaleController::isSafeInternalPath`: no `//`, no backslash, no control
characters anywhere, `\A…\z` anchored), or a
logged-in POST becomes an open redirect.

**Referer, where it is used at all, is same-ORIGIN and never same-prefix.**
`LocaleController::switch` falls back to `Referer` when no `to` is given. A
prefix match on `https://cyclingcommons.org` would also accept
`https://cyclingcommons.org.evil.example/`, which is a different site, and make
the switcher an open redirect reachable by an ordinary GET link (security scan
2026-08-25). `LocaleController::isSameOrigin` compares the parsed host, scheme
and port,
which additionally refuses `https://user@evil.example/` (userinfo dressed up to
look like the real host) and any downgrade from https to http. Nothing else in
the app trusts `Referer` for a destination; the pagers use a submitted `back`
field precisely so they do not have to.
The pager shows one count line: page-of-pages on multi-page lists, the row
range on single-page ones.

## 10. Self-service account deletion (GDPR Art. 17)

Two-step flow in `SettingsController` (danger zone, Security tab), both steps
POST + CSRF:

1. `settings_delete_request`: the account password is re-checked first,
   spending a try from `password_reauth` (§5). Then
   `UserDeletionService::requestDeletion()`
   generates an 8-hex-char one-time code (`bin2hex(random_bytes(4))`,
   uppercased), stores it with `deletionRequestedAt`, and emails it.
2. `settings_delete_confirm`: code validated with `hash_equals`
   (case-insensitive via uppercasing), **expires 1 hour** after the request
   (`+1 hour` in `UserDeletionService::confirmDeletion()`). On success:
   `erase()`: `purge()` (the shared hook seam, §6.3) and the flush in one
   transaction, then session invalidated, redirect home.

A pending self-request is what arms the admin **Execute account removal** /
**Cancel pending removal** actions (§6.1): an admin path through the same
`purge()` seam, with audit.

What survives deletion, and why, is stated on the privacy notice rather than
left implicit (`privacy.retention_messages` says what goes: the messages about
the rider's contributions and the ones turned down or withdrawn): the
contributions given to the open map (with provenance), and
the consent ledger, the evidence that a licence was granted at all (CC BY-SA
4.0 for a photo; for a translation, whichever consent version the translator
ticked, translations.md §6), kept under Art. 17(3)(e) and disclosed under
Art. 13(2)(a). Neither identifies the person once the `users` row is gone:
every reference to the account is cleared (§6.3), so a grant stays linked to
the photo or translation it licenses and to nobody.

---

## 11. Data export (GDPR Art. 15 + Art. 20)

`POST /account/settings/export` → one ZIP, built by `App\Account\DataExportService`.

**One export, not two.** Art. 20 portability strictly covers only what the
subject *provided*, while Art. 15 access is broader. Making a rider choose
between two downloads would be a worse answer to both, so this is the superset
and the README inside says which part is which.

**What it holds.** `account.json` (with the last suspension, if any: until, since, ground and facts, §6.8), `contributions.json` (submissions plus the
`change_history` rows they produced), `community.json` (confirmations, season
votes with the name of what each was for, rides, correction suggestions,
country requests, curator applications, moderator areas), `messages.json`, `consent.json`, `translations.json`, and
`photos/`: the stored originals as files, plus an `index.json` describing each
one.

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
listed, with `"file": null`, because hiding the row would hide a fact about them.

---

## Open questions

- **CAPTCHA / IP-diversity step-up before hard lockout** (§3): recorded as a
  deliberate future product decision; the bounded lockout DoS stands until
  then.
- **User-index role/locked filters** in the admin desk: deferred (needs
  custom EA filter classes for jsonb containment and `lockedUntil > now`);
  the dashboard count cards partially cover the visibility need.
- **Deferred-until-needed hard operator isolation** (separate admin login
  domain): explicitly not planned; revisit only on a concrete threat.
