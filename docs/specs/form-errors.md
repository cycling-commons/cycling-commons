# Form errors

Canonical. Covers how a form tells a rider that their submission did not go
through, across every form on the site.

Related: [account-and-auth.md](account-and-auth.md) for the auth forms,
[translations.md](translations.md) for the catalogue.

## 1. Why form-level errors need their own rule

Symfony attaches two kinds of error to a form: those belonging to a field, and
those belonging to the **form root**. Calling `form_errors()` on each field
renders only the first kind. A template that never renders the root's errors
drops them silently.

Two land on the root routinely:

- **A rejected CSRF token.** `CsrfValidationListener` adds it to the root.
- **A controller's own `$form->addError()`.** The sign-up rate limit
  (`RegistrationController`), the wizard's cross-field violations
  (`ContributeController`), the GPX rejection (`ProposeRouteController`).

Without the rule, the rider gets a 422 or 429 carrying the form redrawn with
nothing said, which they cannot tell apart from the button not having worked.

## 2. The contract

**Every `form_start($x)` must be followed by something that renders `$x`'s own
errors.** The shared way:

```twig
{{ form_start(myForm) }}
{% include 'partials/_form_errors.html.twig' with {form: myForm} %}
```

The partial renders nothing when there is nothing to say, so it is always safe
to include. It emits `role="alert"`, `tabindex="-1"` and an `id` of
`<form-id>_errors`.

Three other spellings also satisfy the contract and are left in place where they
already read well: `form_errors($x)` (`contribute/propose_route`,
`translate/_form`), a hand-rolled `$x.vars.errors` loop with its own heading
(`contribute/improve`, which groups them under one "these need fixing" block),
and `form($x)`.

**Guarded by `FormErrorsVisibleTest::testEveryFormRendersItsFormLevelErrors`,**
which scans every `.twig` under `templates/` and fails naming the file and the
form variable. A new form that forgets the include cannot merge. The scan also
asserts it found at least ten forms, so it cannot pass by finding nothing.

## 3. Markup and styling

`.form-errors`, `.field-errors` and `.alert-error` live in
`assets/styles/atlas.css`, the global sheet, not in a page's own stylesheet.
Most of these pages style only their field-level `<ul>`, so a page-local rule
would have to be copied to every page and would drift: a rule copied into two
places can be wrong in one and right in the other. A page may override layout
only (`page/security/2fa_setup.css` keeps a margin on `.alert-error`).

Two details that are load-bearing:

- **Paragraphs (`.fe-msg`), not a `<ul>`.** `atlas.css` styles every bare
  `form ul` inside `.card` or `.wrap` as a field error (Symfony's default
  field-error markup), so a list inside `.form-errors` would render as a
  second boxed message nested inside the first.
- **`color:#7d3a20` on all three surfaces, not `var(--clay)`.** Measured in a
  browser, flattening every translucent layer down to the first opaque
  background:

  | Surface | `var(--clay)` | `#7d3a20` |
  |---|---|---|
  | `.form-errors .fe-msg`, 14.4px, auth card | 4.02 | **5.11** |
  | `.field-errors li`, 12.8px, auth card | 4.12 | **5.24** |
  | `.alert-error`, 14.4px, auth card | 4.09 | **5.20** |
  | the same two rules on `/account/settings` (paper) | 4.80 / 4.71 | 6.10 / 5.99 |

  `var(--clay)` passes on `--paper` and fails only on the darker auth card,
  which is why one colour is used everywhere. `.wiz-errors` on `/improve`
  measures **6.78** and needs nothing.

  Measure with the browser, not by eye and not against `--paper`: the background
  under these boxes is a translucent tint over a card over the page, and a naive
  check reads too favourably. `FormErrorsVisibleTest::testErrorColoursComeFromTheGlobalSheetOnly`
  guards the shape (the three rules defined in `atlas.css`, one colour, no
  template and no page stylesheet under `assets/styles/page/` setting an error
  colour of its own); the ratio itself needs a
  browser and is recorded here instead.

## 4. The message has to exist in five languages

A form-level error is copy like any other.

- **The sign-up rate limit** is the key `security.register.error_rate_limited`.
- **The CSRF message** is Symfony's English default. Symfony ships a translation
  for it, but in the **`validators`** domain, and this app deliberately points
  validation at `messages` instead
  (`config/packages/validator.yaml`, because its own constraint messages are
  keys there). The shipped translation is therefore never found.
  `App\Form\Extension\CsrfMessageExtension` replaces the default with the key
  `form.error_csrf`.

  **The extension's negative priority (-1000) is load-bearing.** Symfony's own
  `FormTypeCsrfExtension` sets a default for the same option and, with
  `OptionsResolver`, the last writer wins. Tagged extensions run
  highest-priority first, so ours must sort last. At the default priority the
  English sentence quietly overwrites the key again and nothing appears to be
  wrong.

## 5. Every message is a catalogue key

`config/packages/validator.yaml` sets `translation_domain: messages`, so a
constraint whose message is a key resolves out of `messages.*.yaml`. A literal
sentence is looked up in the same domain, missed, and returned unchanged, so it
renders in English on every locale.

Keys live flat under `form:`, matching that section's existing shape
(`label_email`, `error_csrf`): `error_email_invalid`, `error_password_mismatch`,
`error_age_16`. `{{ limit }}` still substitutes, because the constraint passes
it as a parameter and the translated string keeps the placeholder.

**Three spellings of the same mistake**, all guarded by
`ValidationMessagesTranslatedTest`:

1. A constraint option (`message:`, `minMessage:`, `invalid_message`).
2. A controller calling `new FormError('a sentence')`, including one whose
   string sits on its own line inside a multi-line call.
3. A key that exists in PHP but in no catalogue, which renders as the key and
   looks broken rather than merely foreign.

A form **label** can carry the same defect (`'label' => 'Name'` rendered by
`form_label()`). The scan does not cover labels;
`git grep -nE "'label' *=> *'[A-Z]" src/Form/` does, and finds none.
