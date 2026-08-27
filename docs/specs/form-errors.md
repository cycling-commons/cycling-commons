# Form errors

Canonical. Covers how a form tells a rider that their submission did not go
through, across every form on the site.

Related: [account-and-auth.md](account-and-auth.md) for the auth forms,
[translations.md](translations.md) for the catalogue.

## 1. The bug this exists to prevent

Found 2026-08-27. Every form template called `form_errors()` on each field and
none on the form itself. Symfony attaches two kinds of error to a form: those
belonging to a field, and those belonging to the **form root**. The root ones
had nowhere to render, so they were silently dropped.

Two land there routinely:

- **A rejected CSRF token.** `CsrfValidationListener` adds it to the root.
- **A controller's own `$form->addError()`.** The sign-up rate limit
  (`RegistrationController`), the wizard's cross-field violations
  (`ContributeController`), the GPX rejection (`ProposeRouteController`).

The result was a 422 or 429 carrying the form redrawn with nothing said, which
a rider cannot tell apart from the button not having worked. The sign-up rate
limiter's own code comment claimed "A visible error, not a silent redirect"; it
was not visible.

## 2. The contract

**Every `form_start($x)` must be followed by something that renders `$x`'s own
errors.** The shared way:

```twig
{{ form_start(myForm) }}
{% include 'partials/_form_errors.html.twig' with {form: myForm} %}
```

The partial renders nothing when there is nothing to say, so it is always safe
to include. It emits `role="alert"` and an `id` of `<form-id>_errors`.

Three older spellings also satisfy the contract and are left in place where they
already read well: `form_errors($x)` (`propose_route`, `translate/edit`), a
hand-rolled `$x.vars.errors` loop with its own heading (`contribute/improve`,
which groups them under one "these need fixing" block), and `form($x)`.

**Guarded by `FormErrorsVisibleTest::testEveryFormRendersItsFormLevelErrors`,**
which scans every `.twig` under `templates/` and fails naming the file and the
form variable. A new form that forgets the include cannot merge. The scan also
asserts it found at least ten forms, so it cannot pass by finding nothing.

## 3. Markup and styling

`.form-errors` lives in `assets/styles/atlas.css`, the global sheet, not in a
page's `<style>` block. Most of these pages style only their field-level `<ul>`,
so a page-local rule would have to be copied ten times and would be missing on
the eleventh.

`.field-errors` and `.alert-error` moved there too, on 2026-08-27. Until then
both were copied into `security/_auth_shell_styles.html.twig` **and**
`settings/index.html.twig`, identical in each, and both were below the contrast
floor on one of their two backgrounds. Fixing one copy would have left the other
wrong. `2fa_setup` keeps a margin override and nothing else.

Two details that are load-bearing:

- **Paragraphs (`.fe-msg`), not a `<ul>`.** Both of those pages style every bare
  `form ul` as a field error, so a list inside `.form-errors` rendered as a
  second boxed message nested inside the first.
- **`color:#7d3a20` on all three surfaces, not `var(--clay)`.** Measured in a
  browser, flattening every translucent layer down to the first opaque
  background:

  | Surface | `var(--clay)` | `#7d3a20` |
  |---|---|---|
  | `.form-errors .fe-msg`, 14.4px, auth card | 4.02 | **5.11** |
  | `.field-errors li`, 12.8px, auth card | 4.12 | **5.24** |
  | `.alert-error`, 14.4px, auth card | 4.09 | **5.20** |
  | the same two rules on `/settings` (paper) | 4.80 / 4.71 | 6.10 / 5.99 |

  The last row is the trap: on `--paper` the old colour scraped past, and only
  the darker auth card failed. A rule copied into two templates can be wrong in
  one of them and right in the other. `.wiz-errors` on `/improve` was measured
  in the same pass and needs nothing (**6.78**).

  Measure with the browser, not by eye and not against `--paper`: the background
  under these boxes is a translucent tint over a card over the page, and a naive
  check reads too favourably. `FormErrorsVisibleTest::testErrorColoursComeFromTheGlobalSheetOnly`
  guards the shape (one definition, one colour, no template overriding it); the
  ratio itself needs a browser and is recorded here instead.

## 4. The message has to exist in five languages

A form-level error is copy like any other, and it was invisible before, so none
of it had been translated.

- **The rate limit** was a hardcoded English sentence in
  `RegistrationController`. It is now the key `security.register.error_rate_limited`.
- **The CSRF message** is Symfony's English default. Symfony ships a translation
  for it, but in the **`validators`** domain, and this app deliberately points
  validation at `messages` instead
  (`config/packages/validator.yaml`, because its own constraint messages are
  keys there). The shipped translation is therefore never found.
  `App\Form\Extension\CsrfMessageExtension` replaces the default with the key
  `form.error_csrf`.

  **The extension's negative priority is load-bearing.** Symfony's own
  `FormTypeCsrfExtension` sets a default for the same option and, with
  `OptionsResolver`, the last writer wins. Tagged extensions run
  highest-priority first, so ours must sort last. At the default priority the
  English sentence quietly overwrites the key again and nothing appears to be
  wrong.

## 5. Every message is a catalogue key

Closed 2026-08-27, in the same round. Until then the form types wrote their
validation messages as literal English sentences and there were no catalogue
entries for any of them, so `/nl/register` rendered `lang="nl"` with every field
error in English.

**No config change was needed.** `config/packages/validator.yaml` already sets
`translation_domain: messages`, so a constraint whose message is a key resolves
out of `messages.*.yaml`. A sentence is looked up in the same domain, missed,
and returned unchanged. That is the whole bug.

Keys live flat under `form:`, matching that section's existing shape
(`label_email`, `error_csrf`): `error_email_invalid`, `error_password_mismatch`,
`error_age_16`. `{{ limit }}` still substitutes, because the constraint passes
it as a parameter and the translated string keeps the placeholder.

**Three spellings of the same mistake**, all now guarded by
`ValidationMessagesTranslatedTest`:

1. A constraint option (`message:`, `minMessage:`, `invalid_message`).
2. A controller calling `new FormError('a sentence')`. `RegistrationController`
   and `TwoFactorController` each had one, and the second was found by the scan
   rather than by grep, because the string sat on its own line inside a
   multi-line call.
3. A key that exists in PHP but in no catalogue, which renders as the key and
   looks broken rather than merely foreign.

A form **label** can carry the same defect: `ImproveType` had
`'label' => 'Name'`, rendered by `form_label()` on `/improve`. The scan does not
cover labels; `grep -rnoE "'label' *=> *'[A-Z]" src/Form/` does, and found
exactly that one.
