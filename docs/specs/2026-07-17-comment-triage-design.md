# Comment triage pass — design (working doc)

**Date:** 2026-07-17
**Scope:** all PHP comments under `web/src` (157 files). Comments only — no logic changes.
**Baseline:** branch `pre-comment-cleanup` at `703b7a4`. Compare with
`git diff pre-comment-cleanup..symfony-base`.

## Goal

Every comment must make sense to a developer who opens the file for the first
time. No references to code that no longer exists. No references to private
plans or reviews. Short, simple English.

## Why

The current comments were written for a reviewer watching each change land.
They mix four kinds of content, and only one kind helps a new reader.
The repo is about to get its first public commit, so history references
("replaces the deleted X") point at things no reader can see.

Survey numbers (2026-07-17):

- 157 PHP files, ~3,500 comment lines out of ~16,900 total (21%).
- 29 files mention deleted or replaced code.
- Dangling refs ("Task 5", "review #54", "(#49)") resolve to nothing shipped.
- Only 4 of 157 files link to a spec, while `docs/specs/` has 22 good documents.

## The triage rule

Applied to every comment, one file at a time:

| Category | Example | Action |
|---|---|---|
| History | "replaces the deleted honest-stub service" | **Delete** |
| Dangling process refs | "Task 5", "review #54", "(#49)" | **Delete** |
| Code narration | "'climb' → NewItem: item row (state=submitted…)" | **Delete** — it restates the code |
| Constraint notes | "do not array_filter — an emptied field must survive as a removal" | **Keep**, tighten wording |
| Design decisions not in specs | "votes pass through unpersisted" | **Compress** to 1–2 lines; add `@see` spec link if a section fits |
| Spec references | "see account-and-auth.md §6.3" | **Keep** — this is the good pattern |

## Language rule

Comments are written in **simple English**. Not every reader is a native
speaker.

- Short sentences. One idea per sentence.
- Common words. No idioms, no jokes, no cultural references.
  Example: "land the climb on Null Island" becomes
  "an empty coordinate must not become 0.0".
- Present tense, active voice: "Rejects bad input", not
  "Bad input would have been rejected".

## Docblock format

Class/file docblocks collapse to 1–3 lines: what the class is **for**,
present tense. Add `@see docs/specs/<file>.md` (with section, per the
doc-qualified convention) when a relevant spec section exists.
Keep `@api` annotations.

Example (before, 12 lines → after, 5 lines):

```php
/**
 * Turns contribute-form payloads into catalog submissions for moderator
 * review. New climbs create an item plus a submission; improvements
 * snapshot only the changed fields.
 *
 * @see docs/specs/moderation-and-contribution.md
 * @api Autowired via ContributionStubInterface.
 */
```

## Untouched

- SPDX license headers.
- `@param` / `@var` / `@return` / `@throws` and other type annotations.
- PHP attributes (`#[\Override]` etc.).
- All executable code. The diff must be comment-only.

## Execution

1. Files are split into disjoint batches. Parallel subagents apply the
   triage rule, each given this document as the rule set.
2. Heaviest offenders get attention first: `BackfillAttributesCommand`
   (53-line docblock), `SeedManualCatalogCommand` (47), then the 29
   history-flagged files, then the full sweep.
3. Every diff is reviewed centrally before staging.
4. Design decisions that deserve a real spec section are **collected in a
   list for the user**, not written into specs during this pass.

## Verification

- `git diff` stays comment-only (checked per file: only comment lines in
  the diff).
- `php -l` on every touched file (a broken `*/` breaks parsing).
- Test suite run once at the end as a belt-and-braces check.

## Out of scope

- Editing files under `docs/specs/` (except this document).
- Twig, JS, YAML comments — PHP only for this pass.
- Any behavior change, however small.

## Open point

- Files or areas the user wants protected from the pass: none named yet —
  confirm before execution.
