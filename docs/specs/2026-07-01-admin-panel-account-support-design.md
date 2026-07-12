# Admin panel — account support desk (starter) — design

**Date:** 2026-07-01
**Status:** Draft — awaiting review before writing the implementation plan
**Scope of this spec:** Flesh out the existing (scaffold-only) EasyAdmin backend at `/admin` into a usable **account support desk** for administrators: one-click support actions on `User`, a lightweight audit trail, safety guardrails, and a non-placeholder dashboard landing. Content review (`/moderate`), domain-record CRUD (climbs/votes/hazards — not entities yet), and per-region curator scoping remain **out of scope**. An **account-inactivity lifecycle** (dormancy → deletion) is documented here as **future work only** (§9), not built in this starter.

---

## 1. Current state (ground truth)

EasyAdmin is already installed and wired — this spec fleshes out a scaffold, it does not stand one up.

| Fact | Detail |
|---|---|
| Bundle | `easycorp/easyadmin-bundle` **v5.1** (`web/composer.json`), registered in `web/config/bundles.php`. |
| Route | `/admin` via `#[AdminDashboard(routePath: '/admin', routeName: 'admin')]`. No `/admin/dashboard` route exists — that URL 404s; the dashboard is `/admin`. |
| Gate | Double-gated: firewall `access_control ^/admin → ROLE_ADMIN` **and** controller `#[IsGranted('ROLE_ADMIN')]`. |
| Dashboard | [`DashboardController`](../../web/src/Controller/Admin/DashboardController.php) — `index()` just calls `parent::index()`, so `/admin` renders EA's stock "Welcome to EasyAdmin 5" placeholder. Menu = Dashboard + Users. |
| User CRUD | [`UserCrudController`](../../web/src/Controller/Admin/UserCrudController.php) — lists/edits email, displayName, roles (Curator/Admin toggles), emailVerified, twoFaEnabled (read-only), lockedUntil, publicProfile, createdAt. **Never** renders `password`, `totpSecret`, `backupCodes`. |
| Entities | Only 5 exist: `User`, `ResetPasswordRequest`, and World `Continent`/`Country`/`Subdivision`. No climb/vote/hazard/contribution entities yet (form + stub only). |
| Boundary | Content review lives in the branded `/moderate` shell tab (`ROLE_CURATOR`), **not** EasyAdmin — per migration spec §7.5. Admin stays "dry" account/record administration. |

## 2. Roles (unchanged, honored)

`ROLE_USER` (default, implied) → `ROLE_CURATOR` (moderation) → `ROLE_ADMIN` (admin backend). Single `User` entity, roles as a JSON list, no separate `AdminUser` table. This spec adds no roles and no per-region scoping (deferred per migration spec §7.4).

## 3. Scope

**In scope (the starter):**
1. Custom EasyAdmin **actions** on `User` (§4).
2. A lightweight **`AdminActionLog`** audit entity + logger service, surfaced in-panel (§5).
3. **Guardrails** — self-protection and last-admin protection (§6).
4. **GDPR "remove account"** semantics: remove personal data, keep/anonymize any contributed content (§7).
5. A **minimal real dashboard** landing — live counts + recent signups (§8).
6. A **read-only reset-request diagnostics** list (§5.1).

**Out of scope (this starter):**
- The `/moderate` review queue and contributor add/vote/improve flows (already built, live in the product shell).
- CRUD for World reference tables (deferred).
- The account-inactivity lifecycle (§9 — future work).

## 4. Action mechanism

**Decision:** implement each support operation as an EasyAdmin **`Action`** on `UserCrudController` (shown on the index row and/or the detail page), each delegating to a single **`UserAdminService`**. Rejected alternatives: a bespoke per-user "manage account" controller outside the CRUD (more UI to own, breaks EA conventions), and editable form fields for state changes (no confirm step → dangerous for irreversible ops). Custom actions keep everything inside EA, give destructive ops a confirmation, and let the CRUD stay read-mostly.

Each action is **conditionally displayed** (`Action::displayIf(...)`) so admins only see what applies, and each writes an audit record (§5) before/around the mutation.

| Action | Precondition (shown when) | Effect | Confirm? |
|---|---|---|---|
| **Unlock** | `isLocked()` | Clear `lockedUntil` + reset `failedLoginAttempts` | no |
| **Disarm 2FA** | `twoFaEnabled` | Clear `totpSecret` + `backupCodes`, set `twoFaEnabled = false` | **yes** |
| **Mark email verified** | `!emailVerified` | Set `emailVerified = true` + `emailVerifiedAt` | no |
| **Unverify email** | `emailVerified` | Set `emailVerified = false`, clear `emailVerifiedAt` | **yes** |
| **Grant curator** | `!ROLE_CURATOR` | Add `ROLE_CURATOR` | no |
| **Revoke curator** | `ROLE_CURATOR` | Remove `ROLE_CURATOR` | **yes** |
| **Grant admin** | `!ROLE_ADMIN` | Add `ROLE_ADMIN` | **yes** |
| **Revoke admin** | `ROLE_ADMIN` | Remove `ROLE_ADMIN` (guardrail §6) | **yes** |
| **Execute account removal** | `deletionRequestedAt != null` | Remove account per §7 | **yes** |
| **Cancel pending removal** | `deletionRequestedAt != null` | Clear `deletionRequestedAt` + `deletionCode` | no |

**Search & filters** on the User index: search by email/displayName; filter by `emailVerified`, `publicProfile`, `country`, `locale`, `createdAt`. **Deferred:** the **role** filter (needs a custom jsonb-containment filter) and the boolean **locked** filter (needs a `lockedUntil > now` custom filter) — both require a custom EA filter class; role/lock *visibility* is partly covered by the dashboard count cards (§8) meanwhile.

## 5. Audit trail — `AdminActionLog`

**Decision:** a small Doctrine entity (chosen over Monolog-only for queryability, survival across log rotation, and GDPR accountability — you can answer "who disarmed 2FA / removed this account?").

```
AdminActionLog
  id           int
  actor        ManyToOne User (the admin who acted; nullable → 'system' for automated actions, see §9)
  action       string  (enum-like: unlock | disarm_2fa | verify_email | grant_curator | execute_removal | ...)
  targetUser   ManyToOne User (nullable — survives target hard-removal)
  note         text (nullable)
  createdAt    datetime_immutable
```

- A single **`AdminActionLogger`** service is called from every action handler in `UserAdminService`.
- Surfaced two ways: a **read-only EA CRUD** ("Activity" menu item, `ROLE_ADMIN`) and a **recent-actions panel** on the user detail page.
- One entity, one migration. `targetUser` is nullable so a log row outlives an account it recorded the removal of; on hard-removal we snapshot the target's email into `note` for traceability.

## 5.1 Reset-request diagnostics (read-only)

A read-only EA CRUD over `ResetPasswordRequest` ("Reset requests" menu item, `ROLE_ADMIN`) for password-reset support and abuse-spotting. Columns: **target user** (email), **requestedAt**, **expiresAt**, and a derived **active/expired** flag. Actions limited to `index`/`detail` (no new/edit); a single **Delete** is permitted to purge a stuck row.

**Never** exposes `hashedToken` or `selector` (secrets, §10). Rows are transient (consumed on use/expiry + repository throttling), so the list is normally short or empty. Purpose: answer *"why can't this user reset / why does it say they already requested one?"* — the throttle window is visible via `expiresAt` — and surface reset-request floods against a single email.

## 6. Guardrails (baked in, not optional)

- An admin **cannot** revoke-admin / lock / remove-account against **their own** account (prevents self-lockout of the panel).
- An admin **cannot** remove the **last remaining `ROLE_ADMIN`** account (system must always have ≥1 admin).
- Destructive actions (disarm 2FA, unverify, revoke roles, execute removal) require an EA confirmation.
- Violations surface as an EA flash error, not an exception.

## 7. Account removal & the commons principle

CyclingCommons is a **commons**: contributed data is community-owned and does **not** cascade-delete with the account. Removal targets **personal data only**.

- **Today (no content entities):** "execute account removal" hard-deletes the `User` row; `ResetPasswordRequest` rows cascade away (transient personal data). Nothing else references `User` except `World\Country` (`onDelete SET NULL`, irrelevant).
- **Once contributions are persisted (future):** removal becomes **anonymize-in-place** — personal fields (`email`, `password`, `totpSecret`, `backupCodes`, IP/login metadata) are scrubbed and the account is dissociated from its contributions, which are **retained under an anonymous "former contributor" identity**. No contributed record is deleted. This is the deliberate difference from Upstream Platform, which *does* delete a user's ride data on the same 24-month clock.
- Either path is **logged first** (§5), then executed.
- **Exception (2026-07-12):** `UserMessage` rows (planned — the moderation-feedback
  inbox, [`2026-07-12-moderation-feedback-and-messages-design.md`](2026-07-12-moderation-feedback-and-messages-design.md)
  M10) are curator/system correspondence **to** the recipient, not contributed
  catalog content, and DB-cascade-delete with the account. This is the schema's
  only intended cascade on `user_id`; the commons rule above continues to govern
  contributed data.

## 8. Dashboard landing

Replace EA's stock welcome page with a **minimal real dashboard**. `DashboardController::index()` renders a custom `templates/admin/dashboard.html.twig` populated by an `AdminDashboardStats` service (thin `UserRepository` count queries — no new schema).

**Cards (all from the `users` table, so always accurate):**
- Members (total) · Curators (`ROLE_CURATOR`) · Admins (`ROLE_ADMIN`)
- Unverified email · Currently locked (`isLocked()`) · Pending removal (`deletionRequestedAt != null`)

**Recent signups:** last ~10 users (displayName / email, country flag, `createdAt`), each linking to that user's admin detail page — so the dashboard doubles as a jump-off into the support actions.

Moderation-queue depth is **omitted** for now: the queue is still `SampleQueue` (6 hardcoded items, `persisted=false`), so a count would be fake. It joins the dashboard once the data API makes the queue real.

## 9. Future work — account-inactivity lifecycle (dormancy → deletion)

> **Not built in this starter.** Documented here so it isn't lost; graduates to its own spec + plan when scheduled. Mirrors Upstream Platform's retention notices ([`UpstreamDataRetentionNotifier`](../../../upstream-geodata/src/Shared/Service/UpstreamDataRetentionNotifier.php): notice types `12m` / `22m` / `23m_final`, deletion at 24 months), with one commons-specific difference (data is anonymized, not deleted).

**Timeline (clock resets on any login/activity):**

| Inactivity | Event | Message |
|---|---|---|
| **12 months** | Email #1 — inactivity notice | "Your account is inactive — just log in to reset the clock." |
| **22 months** | Email #2 — same notice **+ deletion warning** | "…and your account will be deleted in 2 months." |
| **~24mo − 7 days** | Email #3 — final notice | 7 days before deletion (division's `23m_final`). |
| **24 months** | **Account removed** | Personal data scrubbed; **contributed data anonymized & retained** (§7) — no cascade delete. |

**Mechanism (when built):**
- Add an activity timestamp to `User` (e.g. `lastActiveAt`, updated on successful login / meaningful activity). Today `User` tracks `createdAt`/`updatedAt` + login-throttle fields but **no last-active marker** — this is a required schema addition.
- A scheduled console command (Symfony Scheduler/Messenger), **idempotent**, that: finds accounts crossing each threshold, sends the matching localized notice, and at 24 months invokes the same account-removal path as §7 (anonymize-in-place once content exists).
- **Localized notices** EN/FR/NL/DE via `User.locale` — an improvement over division, whose `User` has no locale field and falls back to `'en'`.
- **Audit:** automated removals write an `AdminActionLog` row with `actor = null` (system) so the trail covers machine-driven deletions too.
- **Admin visibility:** the user detail page shows dormancy state / next-notice date, and admins can reset the clock or exempt an account.

## 10. Security constraints (hard rules, all phases)

- **Never** render or make editable: `password`, `totpSecret` (AES-256-GCM `encrypted_string`), `backupCodes` (hashed but sensitive), or `ResetPasswordRequest.hashedToken`/`selector`.
- All admin surfaces stay double-gated (`^/admin` firewall + `#[IsGranted('ROLE_ADMIN')]`).
- Privacy copy: accounts **do** hold personal data (email + password hash + 2FA secret + login/IP metadata). Do not repeat the "no personal data" claim anywhere in admin.

## 11. Data-model & file impact (starter)

- **New:** `web/src/Entity/AdminActionLog.php` + migration; `web/src/Service/UserAdminService.php`; `web/src/Service/AdminActionLogger.php`; `web/src/Service/AdminDashboardStats.php`; `web/src/Controller/Admin/AdminActionLogCrudController.php` (read-only); `web/src/Controller/Admin/ResetPasswordRequestCrudController.php` (read-only, §5.1); `web/templates/admin/dashboard.html.twig`.
- **Changed:** `UserCrudController` (actions, filters, search, detail-page audit panel); `DashboardController::index()` (render dashboard template) + `configureMenuItems()` (Activity + Reset requests menu items); `UserRepository` (count helpers for §8).
- **i18n:** action labels, confirm text, and dashboard card labels via the messages catalog (EN/FR/NL/DE), consistent with the project's day-one i18n.

## 12. Testing

- Unit: `UserAdminService` per action (state transitions + guardrail rejections, esp. self-action and last-admin).
- Functional: `/admin` renders the dashboard with correct counts against a seeded fixture; each EA action as `ROLE_ADMIN` (happy path + guardrail 4xx/flash); `ROLE_CURATOR`/`ROLE_USER` denied at `/admin`; audit row written per action; the reset-request list (§5.1) is read-only (no new/edit) and never renders token/selector; secrets never present in rendered admin HTML.

## 13. Decisions & open questions

1. **Dashboard** — ✅ minimal real dashboard **in scope** (counts + recent signups, §8). *(confirmed 2026-07-01)*
2. **Reset-request diagnostics** — ✅ **in scope** as a read-only list + single-row purge (§5.1). *(confirmed 2026-07-01)*
3. **Inactivity lifecycle (§9)** — ✅ 12 / 22 / 23m-final / 24-month figures + anonymize-not-delete **confirmed**; graduates to its own spec when scheduled. *(confirmed 2026-07-01)*

## 14. Tracked follow-ups (post-starter, non-blocking)

Surfaced by the final whole-branch review of the shipped starter — recorded so they aren't implicit:

1. **CSRF-harden the destructive actions.** The support actions are EA GET links (gated `ROLE_ADMIN`) with a client-side `confirm()` on destructive ones — not CSRF-token protected (an accepted starter tradeoff). The cross-site `<img>`/subresource vector is already neutralized by `cookie_samesite: lax` (session + remember-me); the residual is a top-level link the admin actively clicks (which also hits the confirm). **Before a real multi-admin deployment**, convert the destructive handlers (grant/revoke admin, remove account, disarm 2FA) to POST + CSRF token. Not a merge blocker for an admin-only back-office; should not linger once a second admin exists.
2. **Complete the User-index filters.** Add the deferred **role** (custom jsonb-containment) and **locked** (`lockedUntil > now`) filters as a custom EA filter class (see §4).
