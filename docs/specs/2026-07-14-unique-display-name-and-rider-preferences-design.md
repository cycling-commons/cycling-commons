<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Unique display names & rider preferences (2026-07-14)

## Problem

1. `users.display_name` has no uniqueness guarantee — two riders can register
   the same (or a case-variant of the same) name, which invites confusion and
   impersonation-by-casing everywhere names are shown (moderation queues,
   route proposals, community votes, messages).
2. Riders have no way to declare **which bikes they ride** and **what kind of
   riding they do**. The map will later use those preferences to prefilter
   its Bike select and Discipline chips; today there is nothing to read.

## Decisions

- **Uniqueness is case-insensitive** (decided over exact-match and
  case+whitespace-insensitive): `Xander` and `xander` collide. Leading and
  trailing whitespace is trimmed (Symfony forms already trim), but inner
  whitespace variants stay distinct.
- **"Type of riding" gets a new style-only enum** rather than reusing the map
  rail's 9 Discipline chips: those chips mix hardware (MTB, E-bike,
  Recumbent, Handbike) with style, and hardware is already declared
  separately via bike types. The map chips are a visual stub today; when
  prefiltering is built they are re-based onto this enum — the enum is the
  contract.
- **Preferences live on the Settings page only** — registration stays
  friction-free; the profile page is untouched.
- **Map prefiltering itself is out of scope** for this iteration. This
  change only creates the data (canonical enum values on the user) that the
  map layer will read later.

## Model

### Display-name uniqueness (shadow canonical column)

- New column `users.display_name_canonical` (string 100, nullable, unique
  constraint `uniq_users_display_name_canonical`): the lowercased
  (`mb_strtolower`) and trimmed copy of `display_name`, maintained **inside
  `setDisplayName()`** so every write path is covered automatically
  (registration form, settings form, `app:create-user` console command,
  admin CRUD, fixtures). Empty display names canonicalize to NULL, which
  neither the unique index nor UniqueEntity considers — unnamed rows (tests,
  partial flows) never collide.
- Chosen over a raw `LOWER(display_name)` functional index: the shadow
  column is Doctrine-native (no DBAL schema-diff drift, no schema_filter
  hack) and lets stock `UniqueEntity` validation work with zero custom
  validator code.
- Validation on the **entity** (same rationale as the email block,
  `User.php` — every write path covered, not just one form):
  `#[UniqueEntity(fields: ['displayNameCanonical'], errorPath: 'displayName',
  message: ...)]`, message translated in en/fr/nl/de.
- Migration order: add the column (nullable, stays nullable) → backfill
  `NULLIF(LOWER(TRIM(display_name)), '')` → **de-duplicate collisions by
  suffixing** (`name`, `name-2`, `name-3`, … applied to both columns,
  NULL canonicals excluded) → unique constraint. There is no production DB
  yet, so the dedup path only ever touches dev data — but it makes the
  migration order-safe anyway.

### RidingStyle enum

`App\Catalog\RidingStyle` string enum, sibling of `BikeType`, 7 cases:

| Case | Value | Meaning |
|---|---|---|
| Road | `Road` | racing / sportive riding |
| Gravel | `Gravel` | gravel adventure riding |
| Touring | `Touring` | multi-day supported touring |
| Bikepacking | `Bikepacking` | self-supported off-tarmac travel |
| Trail | `Trail` | MTB / singletrack riding |
| Urban | `Urban` | commuting / city riding |
| Leisure | `Leisure` | family / casual riding |

Hardware (E-bike, Handbike, Recumbent, Trike, Tandem) is deliberately **not**
in this enum — it lives in `BikeType`, which the rider declares separately.

### Preference storage

- Two JSON columns on `users`, both defaulting to `[]`:
  - `bike_types` — `list<string>` of `BikeType` values (existing 8-value
    enum, `App\Catalog\BikeType`).
  - `riding_styles` — `list<string>` of `RidingStyle` values.
- Entity accessors are enum-typed (`/** @return list<BikeType> */` etc.) and
  silently drop unknown stored strings on read, so a future enum rename
  cannot fatal a page render.

## Surfaces

- `User` entity: `displayNameCanonical`, `bikeTypes`, `ridingStyles` columns
  + accessors; `UniqueEntity` attribute; one migration for all three columns
  (backfill + dedup + constraint).
- `RidingStyle` enum (`web/src/Catalog/RidingStyle.php`).
- `SettingsType`: two optional multi-select checkbox groups (`ChoiceType`,
  `multiple` + `expanded`), choices from the enums, labels + help text
  translated in 4 locales; settings template renders them as the existing
  form-section pattern.
- Translations: new `form.*` keys + the uniqueness error message in
  en/fr/nl/de (translation-parity pre-commit hook enforces parity).
- Registration/profile templates: **unchanged** (registration picks up the
  uniqueness error automatically via the entity constraint).

## Testing

TDD per repo practice:

- Entity: canonicalization on `setDisplayName()` (case, trim), preference
  accessors round-trip + unknown-value dropping.
- Registration: duplicate name (exact and case-variant) rejected with the
  translated form error; unique name still registers.
- Settings: saving both preference fields round-trips; empty selection
  allowed; existing settings tests stay green.
- Migration dedup covered via the backfill logic.

## Future work (explicitly deferred)

- Map prefiltering: preselect the map's Bike filter and (re-based)
  Discipline chips from the logged-in rider's stored preferences.
- Re-base the map Discipline chips onto `RidingStyle` and make them filter.

## Execution note (2026-07-14, symfony-base, NOT pushed)

Landed in six commits: `RidingStyle` enum (824d1e7); canonical display-name +
preference columns + migration `Version20260714150000` (c525aa0), fixed by
f1d6f3d so empty display names canonicalize to NULL (unique index ignores
unnamed rows); test-user display-name sweep for the new canonical unique
index (49685e0); case-insensitive uniqueness surfaces + 4-locale error
(9d624af); settings UI preference selectors (2b6bad6). Full suite green (581
tests, 2544 assertions), phpstan clean (0 errors), php-cs-fixer reports no
diff. Psalm found 5 pre-existing errors in `CatalogSchemaProvider.php` and
`RideCheckService.php`, both untouched by this feature's commits (confirmed
via `git blame`, predating this work by same-day hours) — left as-is, not in
scope. SPDX, licence, and translation-parity checks all pass. The migration
was applied to both the test and dev databases; **prod still needs it** on
deploy. Map prefiltering remains deferred as specced.
