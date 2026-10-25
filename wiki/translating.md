<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Translating the site

Cycling Commons is written in English, and English is the source of every
other language. The site has five languages:

| Language | State |
|----------|-------|
| English | The source. Every other language translates it. |
| Dutch | Live on the site. |
| French, German, Spanish | Translated automatically, not live. Each one needs a careful read by a native speaker before it goes live. |

Any other language is welcome too.

## What there is to translate

| Text | Where it lives | How you change it |
|------|----------------|-------------------|
| The words of the site: menus, buttons, pages, messages | `web/translations/messages.en.yaml` and one file per language beside it | On the site for a live language, in the files for any language |
| The privacy notice, the terms and the licences page | `web/translations/privacy.en.yaml`, `terms.en.yaml` and `licenses.en.yaml`, one file per language beside each | In the files only. These are legal texts, so every change is reviewed like code. |
| The texts of a region page | The region editor | See [Writing region texts](region-texts.md) |

## A live language: translate on the site

Sign in, then open **Translate** in the account menu, or go to the address
`/nl/translate` for Dutch. There are two ways to work:

- **The catalogue.** A list of every sentence, with the English beside the
  live translation. Search by word, or pick **Stale** to see the sentences
  whose English changed after they were translated. Open one and write your
  wording.
- **On the page.** Pick **Translate this page** in the account menu, and
  every sentence on the page you read gets a thin underline. Click one to
  open the same form, right where the sentence is used. **Stop translating**
  ends it.

A curator reads your wording and approves it. Then it is live, without a new
release. The first time you send a translation, you confirm the licence
(below).

## A language that is not live: work in the files

French, German and Spanish are not on the site yet, so the catalogue does not
offer them. Review them in the files instead:

1. Open `web/translations/messages.fr.yaml` (or `.de.yaml`, `.es.yaml`) next
   to `web/translations/messages.en.yaml`.
2. Read each sentence against the English with the same name.
3. Fix what reads wrong, stiff or unclear.
4. Send the change as a pull request (see [Contributing](contributing.md),
   *Contributing to this repo*).

Each file is a list of names and sentences. Change only the sentence, the
part in quotes:

<!-- CODE-ILLUSTRATIVE a shortened excerpt of a translation file, the French blog strings -->
```yaml
blog:
  back: 'Tous les articles'
  archive: 'Autres articles'
  empty: 'Rien ici pour l''instant. Le premier article arrive.'
```

- **Never change a name**, the part before the colon (`back`, `archive`).
  The site finds the sentence by its name.
- **Keep every placeholder** exactly as it is. In `'%count% verified'` the
  site puts a number where `%count%` stands, so `'%count% vérifiés'` works
  and `'%nombre% vérifiés'` breaks.
- **Keep every tag**, such as `<b>` and `</b>`, around the words they mark.
- **Write an apostrophe twice** inside single quotes: `l''instant` shows as
  *l'instant*.
- **Every file has the same names as the English one.**
  `web/tools/check-translations.sh` checks it on every pull request.

Write the way the English does: short sentences, plain words, and the reader
as *you*. French uses *vous*, Dutch *je*, German *du* and Spanish *tú*.

A language goes live when its review is done. It is switched on with a
release, and from then on its riders translate on the site like the Dutch
ones do.

## A new language

A new language needs a change to the code as well as a new file for each
text, so it starts with a message. Write through the contact page on the
site, or open an issue on
[GitHub](https://github.com/cycling-commons/cycling-commons/issues), and
say which language and how much time you have. A few hours a month already
help.

## The licence

A translation of the site is part of the software, so it carries the
licence of the code: AGPL-3.0-only. That holds wherever you typed it, on the
site or in a file. See `licenses/COMMONS-TRANSLATIONS-LICENSE.md`.
