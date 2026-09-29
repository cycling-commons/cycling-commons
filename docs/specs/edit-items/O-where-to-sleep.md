<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — O · Where to sleep

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** O · Where to sleep
- **Map depiction:** ⛺ pin, colour #B5532E
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *votable* — verified (≥ X community confirmations) → votable → **best-of** (top-voted); appears in **Best-of** mode once it earns votes. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Bike-friendly stays riders actually used — gîtes and B&Bs with secure storage and a welcome for muddy kit.

## Read view (drawer "current details")
- Type
- Secure bike storage
- Area

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Town / commune | input (`town`) | `[edit]` |
| Website | url (`web`) | `[edit]` |
| Secure bike storage | select(Yes — locked room / Yes — garage/shed / On request / No) | `[edit]` |
| Drying / washing for kit | select(Unknown / Yes / No) | `[edit]` |
| Booking link | url | `[edit]` |
| Note for riders | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Pets allowed? | select(Unknown / Yes / No) | `[edit]` |
| Meals / breakfast? | select(Unknown / Yes / No) | `[edit]` |
| Tools to borrow? | select(Unknown / Yes / No) | `[edit]` |
| Accessibility | multiselect(Step-free access / Handbike-friendly / Wheelchair-accessible); nothing ticked means not stated | `[edit]` |

### Report a problem
Not built: per-type reasons are not offered. A rider reports a place through the content report ([../content-reports.md](../content-reports.md)).

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Production:** OSM `tourism=camp_site`, `hostel`, `guest_house`, `chalet`, `wilderness_hut`,
  `alpine_hut`, `motel` and `hotel` harvested (`pipeline/contract/coverage-contract.json`, letter O),
  plus community and partner contributions.
