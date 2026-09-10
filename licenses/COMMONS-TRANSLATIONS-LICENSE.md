# Cycling Commons UI Translations Licence (AGPL-3.0-only)

> This is a plain-language notice. The binding terms are the verbatim GNU AGPL v3 text
> (see "Full text" below).

## The licence
**UI translations** are part of the software. A rendering of an interface string into an enabled
locale, for example the Dutch wording behind the "Add a climb" button, is licensed under the
**GNU Affero General Public License version 3**, SPDX `AGPL-3.0-only`, the same licence as the code
it runs in.

That covers a translation in both places it can live:
- the developer-shipped catalogues in git (`web/translations/messages.*.yaml`); and
- the proposal and approved overlay rows submitted through the website.

The same string carries the same licence wherever it is stored. Where a translation sits is a
storage detail, not a licensing one.

## Why the software licence, and not a Creative Commons one
A translation of an interface string is a derivative work of that string. You cannot write the
Dutch for "Add a climb" without the English source, the key it hangs on, its placeholders and the
plural rules the code expects, and the result only means anything inside the program. A derivative
of AGPL code is under the AGPL whatever label is put on it. A Creative Commons label would not make
these translations any freer, it would only describe them wrongly. Translations are software, and
they carry the software's licence.

Translations proposed before this change were offered under CC BY-SA 4.0, and nobody loses a right
they already hold: a copy someone took under those terms stays under those terms for them. From
here on the whole catalogue is published with the software under AGPL-3.0-only. The inbound grant
in the [Terms clause](COMMONS-TERMS-CLAUSE.md), 1.3, is what allows that: it lets the steward
sublicense a contribution under the licences listed in 1.2, and AGPL-3.0-only is one of them.

## What you may do
Anyone may run, study, change and redistribute the translations, commercial use included, on the
AGPL's terms:
1. **Keep the licence.** Copies and modified versions stay AGPL-3.0-only.
2. **Pass on the source.** Distribute a modified version and its Corresponding Source goes with it,
   message catalogues included.
3. **Network use counts.** AGPL section 13: if you run a modified version as a service that people
   use over a network, those users must be offered its Corresponding Source. A translated interface
   is precisely what they are interacting with, so an edited catalogue is a modification like any
   other.

## Credit
The AGPL asks you to keep the copyright and licence notices intact rather than to print a credit
line. We credit translators in the translation history, and if you want to name the source in your
own interface or on an about page, this wording is accurate:

> UI translations from the **Cycling Commons**, © Cycling Commons contributors, licensed under the
> GNU AGPL v3.

The name, the logo and the wordmark are not part of that licence. A fork is free to take the
translations and must carry its own name: see [`../TRADEMARK.md`](../TRADEMARK.md).

## Provenance, the hard rule
A UI translation may enter the Commons **only** if the contributor has the right to license it.
- Your own wording of an existing catalogue key: yes.
- Someone else's copyrighted translation, lifted from another product, scraped from another site,
  or taken from a translation memory you are not licensed to reuse: **no.**

By proposing a translation on the website you confirm you hold those rights (see the
[Terms clause](COMMONS-TERMS-CLAUSE.md), 1.4). By sending one as a pull request you confirm the
same thing with a Developer Certificate of Origin sign-off, `git commit -s`. Either way you keep
your copyright: we ask for a licence, never for an assignment.

## Scope
Covers proposed and approved renderings of interface strings into an enabled locale, in git and in
the database alike. It does **not** cover:
- personal data (private, never published);
- the underlying factual data (ODbL, see [`COMMONS-DATA-LICENSE.md`](COMMONS-DATA-LICENSE.md));
- contributed media such as photographs (CC BY-SA 4.0, see
  [`COMMONS-MEDIA-LICENSE.md`](COMMONS-MEDIA-LICENSE.md));
- wiki prose in `wiki/` (CC BY-SA 4.0), which is documentation rather than interface; or
- the Cycling Commons name, logo and wordmark, which are reserved
  (see [`../TRADEMARK.md`](../TRADEMARK.md)).

## Full text
The verbatim official text is committed in this repository:
- GNU AGPL v3 → [`../LICENSE`](../LICENSE), the same text as
  [`../LICENSES/AGPL-3.0-only.txt`](../LICENSES/AGPL-3.0-only.txt)
  (canonical: <https://www.gnu.org/licenses/agpl-3.0.txt>)

The binding text is that verbatim version, not this summary. The SPDX identifier to write is
`AGPL-3.0-only`.
