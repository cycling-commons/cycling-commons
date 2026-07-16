<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->
> **Consolidated into** moderation-and-contribution.md (unassigned=global, NULL-region-visible-to-all, filter+hard-guard enforcement, twin SQL/PHP in-scope rule, exactly-one-of CHECK constraint, audited assignment action, visible-scope bar rule, 403 semantics) **(2026-07-16).** This dated working doc is sweepable; the canonical docs above are the source of truth.

# Moderator areas — region/country-scoped moderation (2026-07-14)

## Problem

Every curator sees every moderation item everywhere. As coverage grows
beyond Wallonia, moderators need to be assignable to the areas they know
(regions and/or whole countries), set by admins in `/admin`, with all
moderation surfaces showing only in-scope items.

## Decisions

- **Unassigned = global** (decided over nothing-until-assigned): a curator
  with no area rows moderates everything — rollout-safe (existing curators
  keep working) and right for small teams. `ROLE_ADMIN` is always global.
- **Outside-all-regions items are visible to every curator** (decided over
  global-curators-only): intake deliberately keeps NULL-region proposals
  reviewable; they are rare by construction and must not fall through the
  cracks.
- **Enforcement is filtering AND hard guards**: hidden queue rows are not
  a security boundary — every moderation write verifies the target's
  region against the actor's scope server-side (403 out of scope).
- **Assignment UI = audited support-desk action** (decided over a separate
  EasyAdmin CRUD): the admin user desk's established pattern — one audited,
  transactional mutation per admin action.

## Model

### `moderator_area` (entity `App\Moderation\Entity\ModeratorArea`)

- `id`, `user_id` (BIGINT, DB-level FK → `users(id)` ON DELETE CASCADE,
  plain int column per house convention), and EXACTLY ONE of:
  - `region_id` (BIGINT, FK → `region(id)` ON DELETE CASCADE, nullable),
  - `country_code` (CHAR-ish VARCHAR(2) ISO 3166-1 alpha-2, nullable,
    matching `Region.countryCode` and World `Country.iso2`).
- CHECK constraint: `(region_id IS NULL) <> (country_code IS NULL)` (one
  and only one set). Unique index over `(user_id, region_id,
  country_code)`; index on `user_id`.
- A user's scope = the union of their rows.

### Scope resolution — `App\Moderation\ModerationScope`

- Value object built per request for the acting curator:
  `{global: bool, regionIds: list<int>, countryCodes: list<string>}`;
  `global` when the actor has `ROLE_ADMIN` or zero area rows.
- Item-in-scope rule (the single source of truth, exposed once as a SQL
  fragment builder and once as a PHP predicate):
  `item.region_id IS NULL` OR `item.region_id ∈ regionIds` OR the item's
  region's `country_code ∈ countryCodes`.
- SQL shape (parameterized, used by both queues):
  `(x.region_id IS NULL OR x.region_id IN (:sc_rids) OR EXISTS (SELECT 1
  FROM region r WHERE r.id = x.region_id AND r.country_code IN
  (:sc_ccs)))` — omitted entirely when the scope is global; empty lists
  bind an impossible sentinel so the fragment stays valid.

## Enforcement surfaces

- **SubmissionQueue**: `filtered()`, `pendingForMap()`, `total()`,
  `countries()`, `regions()` gain a `ModerationScope` parameter — queue
  page rows, badge counts, filter dropdown options, and the map's
  CC_PENDING payload are all scoped.
- **RouteQueue**: `pending()`, `pendingSuggestions()`, `total()`,
  `regions()` likewise.
- **Hard guards (403 when out of scope)**: `/moderate/decide`
  (submissions), route moderation writes (approve/reject/retire/save,
  trash, correction done/dismiss), and `/moderate/message` (the message's
  subject item must be in scope). Guards live next to the existing CSRF/
  role checks in the controllers/services, using the same
  `ModerationScope` predicate; the route detail page itself 403s when the
  route is out of scope (its actions would all be forbidden anyway).
- **Visible scope**: the moderator bar's MODERATION label shows the
  actor's scope — assigned area names (regions by name, countries by
  ISO2/flag name) or "All areas" (`account.mod_scope_all`), so curators
  always know what they are looking at.

## Admin UI (support desk)

- New audited action on the admin user desk, next to grant/revoke
  curator: **Assign moderation areas** — a form with a multi-select of
  countries (World bundle, grouped as in the settings country picker) and
  a multi-select of regions (name + country code).
- `UserAdminService::setModeratorAreas(User $target, User $actor,
  list<string> $countryCodes, list<int> $regionIds): void` — replaces the
  target's rows and writes the audit entry (action `moderator_areas`,
  detail = the resulting set, e.g. `regions: [wallonia]; countries: [BE,
  NL]`) in ONE transaction, mirroring every other desk mutation. Only
  meaningful for curators, but storable for any user (rows are inert
  without ROLE_CURATOR).
- The desk's user card lists the current assignment.

## Testing

- Unit: `ModerationScope` predicate (global, region hit, country hit,
  NULL region, no match) + the SQL fragment builder.
- Functional: an area-restricted curator sees only in-scope + NULL-region
  items on the submissions queue, the routes desk, and in the map pending
  payload; counts and dropdowns match; out-of-scope decide/route-write/
  message → 403; unassigned curator sees everything (regression);
  ROLE_ADMIN unaffected by rows; support-desk action replaces rows and
  writes the audit entry.
- Migration applied to test+dev DBs; prod needs it on deploy.

## Out of scope

- Auto-assignment / self-service area picking (admins assign only).
- Scoping riders' own surfaces (messages inbox etc.) — moderation-side
  only.
- Region-aware curator NOTIFICATIONS (nothing notifies curators today).

## Execution note (2026-07-14, symfony-base, NOT pushed)

Executed as planned: model + scope (c15fe8c+243956b), submissions queue
scoping (0f77af5), submission write guards (32d54da+a677d75), routes desk
scoping + guards (65bd130), admin desk assignment action (8d6ef84+9490ab9),
shell label + gates (this commit). Migration Version20260714210000 applied
to test+dev; **prod needs it on deploy**. Full suite green (623 tests),
statics clean.
Final whole-branch review (opus, 656de36..7b8b4f3): ready to merge, 0
critical / 0 important — SQL/PHP predicate twins verified to agree on
casing, NULL-region and deleted-region edges; no unscoped surface found.
Minors fixed in 8d39e6a (single scope resolution per request via
describe(User, ?ModerationScope); explicit unassigned-curator-sees-
everything regression test). Orphaned-suggestion null-region allowance and
the 403-vs-redirect existence signal rationale-closed as consistent,
documented semantics. Final: 624 tests green.
