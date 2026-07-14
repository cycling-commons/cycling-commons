<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Public rider profile, "view as others", settings chips & tabs (2026-07-14)

## Problem

1. `users.public_profile` is a stored toggle that nothing consumes: no
   public profile page exists, so "when off, your profile is hidden"
   describes a page that never existed. Riders asked to "see how others
   see my profile" — impossible while there is nothing others can see.
2. The new settings preference checkboxes (bike types / riding styles)
   render as loose input+label rows whose pairing is visually ambiguous
   and out of line with the site's established checkbox styling.
3. Settings has grown into one long page (status, identity, preferences,
   public profile, password, 2FA, danger zone) — the rider asked for two
   tabs, with password and 2FA on the second.

## Decisions

- **Build the real public page** (decided over a preview-only mock): a
  public rider profile at `/riders/{uuid}`, existing only while
  `publicProfile` is ON (404 otherwise). "View as others see it" is then
  simply a link to your own public URL, rendered with zero owner
  special-casing — what you see is byte-for-byte what others get.
- **UUID in the URL** (existing UUIDv7 on `users`), not the integer id —
  non-enumerable. A migration adds the missing unique index on
  `users.uuid` (column existed since the auth foundation but was never
  indexed; v7 values make pre-index duplicates practically impossible, so
  no dedup pass is needed).
- **What the page shows** (owner opted in by enabling the toggle):
  display name, country flag (if set), member-since (month + year from
  `createdAt`), riding preferences as chips (if any), accepted
  contribution count, and the rider's most recent verified/proposed
  routes (up to 10) deep-linking to the map. **Never**: email, IPs,
  locale, roles, 2FA state, or anything account-internal.
- **Toggle-off affordance:** when `publicProfile` is OFF, settings and
  the own-profile page show a hint ("no public page — enable Public
  profile") instead of the view-as link. No preview of a non-existent
  page.
- **Checkbox fix = reuse `chip-check`:** the settings preference groups
  adopt the propose-route form's pill-chip pattern (label wraps the
  input; `:has(input:checked)` drives the active look) — the established
  "other places" styling.

## Model

### Public profile page

- Route `rider_profile`: `/{_locale-prefix}/riders/{uuid}` with a Uuid
  route requirement, `PUBLIC_ACCESS` (firewall stays exact-path public
  like other cacheable public endpoints — but this page renders
  per-request; no caching contract in v1).
- Controller `RiderProfileController::show(string $uuid)`:
  `UserRepository::findOneBy(['uuid' => ...])`; 404 when not found OR
  `!isPublicProfile()`. Renders `profile/public.html.twig` (shares the
  site chrome, not the logged-in account shell).
- Content queries (same shapes ProfileController already uses, filtered
  to public-appropriate states):
  - contributions: count of `Submission` rows by the user with status
    Approved;
  - routes: `RecommendedRoute` by `proposedBy`. Named = **Verified
    only**, newest first, 10 names deep-linked via the existing
    `?route=` map deep-link. Count = **Submitted + Unverified** ("not
    yet fully verified": Submitted awaits the curator's decision,
    Unverified is curator-approved and awaiting ride-verification).
    Submitted names never render — they are un-vetted.
- Riding preferences render via the same translation keys the settings
  form uses (`map.bike_*`, `form.riding_*`).
- Retention: rejected submissions never appear (they are not Approved).
  Account deletion already hard-deletes the row → the URL 404s naturally.

### View-as entry points

- Settings, next to the public-profile toggle: when ON, a link "View
  public profile" (`settings.view_public`) to `rider_profile` with the
  rider's own uuid, `target="_blank"`; when OFF, hint text
  (`settings.view_public_off`).
- Own profile dashboard header: same link/hint pair.

### Settings chip checkboxes

- `templates/settings/index.html.twig`: the two `.checks` blocks are
  replaced by the propose-route pattern:
  `{% for child in profileForm.bikeTypes %}<label class="chip-check">
  {{ form_widget(child) }}<span>{{ child.vars.label|trans }}</span>
  </label>{% endfor %}` inside a `.chip-list` (same for ridingStyles).
- The `chip-check`/`chip-list` CSS block is copied into the settings
  page's style block (both pages keep self-contained styles — the
  established pattern); the now-unused `.checks` CSS is removed. Labels
  keep their translated strings; no form-type change needed
  (`choice_label` already supplies the keys).

### Settings tabs (Profile / Security)

- Two tabs under the settings heading:
  - **Profile** (default): account status, identity & privacy (profile
    form incl. preferences + public-profile toggle + view-as link), the
    profiles-opt-in notice.
  - **Security**: password change, the 2FA block, the danger zone
    (account deletion).
- Markup: an accessible tab bar (`role="tablist"`, buttons with
  `role="tab"`/`aria-selected`/`aria-controls`) over two
  `role="tabpanel"` panes; pane switching is client-side (tiny inline
  JS, no reload), same show/hide pattern as the account shell's
  `.dpane` panes.
- **Server picks the initial tab**: `?tab=security` (or a submitted
  password form re-render) activates Security; everything else defaults
  to Profile. The password-change and account-deletion controller
  redirects change from `settings` to `settings` + `['tab' =>
  'security']` so flashes land on the visible tab — the existing
  functional tests' redirect assertions update accordingly.
- New keys `settings.tab_profile` / `settings.tab_security` in 4 locales.

## Surfaces

- New: `RiderProfileController`, `templates/profile/public.html.twig`,
  migration `Version20260714190000` (unique index `uniq_users_uuid`).
- Modified: `templates/settings/index.html.twig` (view-as link + chip
  checkboxes), `templates/profile/show.html.twig` (view-as link),
  `User` entity table attribute (`#[ORM\UniqueConstraint(name:
  'uniq_users_uuid', columns: ['uuid'])]` + `unique: true` on the column
  mapping to match), security firewall/access config only if the public
  route needs an explicit PUBLIC_ACCESS entry (locale-prefixed paths).
- Translations (en/fr/nl/de, same commit): `settings.view_public`,
  `settings.view_public_off`, `profile.public_*` page strings
  (member-since, contributions, routes, preferences headings, empty
  states).
- Spec updates in the same change: account-shell/profile-related notes if
  any spec documents the profile surface (checked at implementation
  time); privacy copy stays accurate — the page only exists on explicit
  opt-in.

## Testing

- Functional: public page 200 + expected content for an opted-in user
  (anonymous client); 404 when toggle OFF; 404 for unknown uuid; email
  never present in the response body; unverified route names not leaked
  (count only); view-as link visible in settings when ON, hint when OFF.
- Entity/migration: unique uuid index present; existing users unaffected.
- Settings chips: existing RiderPreferencesTest keeps passing (markup
  changes, field names unchanged — `settings[bikeTypes][]` etc. are
  rendered by the same form widgets inside the new labels).

## Out of scope

- Public profile discovery/linking from contributions or moderation
  surfaces (attribution stays anonymous everywhere else for now — a
  future decision).
- Avatars, bios, or any new profile fields.
- Caching/ESI for the public page.

## Execution note (2026-07-14, symfony-base, NOT pushed)

Executed as six commits (a settings-tabs task was folded in mid-plan at
the rider's request, growing the plan from four commits to six):

- `0e183aa` uuid unique index (migration `Version20260714190000`, applied
  to test+dev — prod needs it run on deploy).
- `f6fb532` public rider profile page + access-control + 4-locale
  strings, plus `75f6bbb` fix: the pending-route count spans
  Submitted+Unverified — the plan's Unverified-only read was a
  domain-semantics bug caught in review, not a late scope change.
- `5b297fd` view-as links (settings + own profile).
- `77f4ef9` chip-pattern preference checkboxes (bike types / riding
  styles), field names unchanged.
- `560f37e` two-tab settings layout (Profile / Security), including the
  redirect changes to `?tab=security` for the password and both
  deletion flows.

Full gate run (`web/`): `php bin/phpunit` green — 596 tests, 2611
assertions. `phpstan analyse` — no errors. `psalm --no-cache` — no
errors (69 pre-existing info-level suggestions, none touching this
plan's files). `php-cs-fixer fix --dry-run --diff` — 0 of 263 files
need changes. `check-spdx.sh`, `check-licenses.sh` — clean.
`check-translations.sh` — en/fr/nl/de all at 1720 keys, full parity.
