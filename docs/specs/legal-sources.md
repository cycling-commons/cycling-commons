<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Where the law we cite actually is

Canonical. Every spec, wiki page and piece of site copy that names an article
links here or links the act directly, so a reader never has to take a citation
on trust (owner, 2026-08-30: "if you reference GDPR articles or avg, please link
to them in spec/wiki and in the website itself").

## The rule

**Name the article in the text, and link it.** EUR-Lex is the official source.

On the site, a GDPR article links to that article, in the reader's language:
`https://eur-lex.europa.eu/legal-content/<LANG>/TXT/HTML/?uri=CELEX:32016R0679#art_<n>`,
with the law's local name in the link text ("GDPR Art. 17", "AVG art. 17"). The
bare regulation URL opens at the recitals, whose numbers repeat the articles'
(recital 32 is consent, Article 32 security), so a link to the act alone puts a
reader on the wrong numbered paragraph.
`ContentPagesTest::testEveryGdprArticleLinkOpensThatArticle` pins this on
`/privacy` (privacy-notice.md §2). Other acts on the site link the act's ELI
URL from the table below, with the article named in the text, as the DSA
statement-of-reasons email does.

The link goes in the sentence that makes the claim, not in a footnote:
somebody reading why we hold their address should be one click from the
provision that allows it.

Specs and the wiki name the article in the text and link the act's ELI URL
from the table below: anchors into a consolidated version are not stable,
while the ELI URL is.

## The acts

| Short name | Full title | Text |
|---|---|---|
| GDPR, RGPD, AVG, DSGVO | Regulation (EU) 2016/679, general data protection | <https://eur-lex.europa.eu/eli/reg/2016/679/oj> |
| DSA | Regulation (EU) 2022/2065, Digital Services Act | <https://eur-lex.europa.eu/eli/reg/2022/2065/oj> |
| DSA Art. 18 contact points | The European Commission's list of national contact points for Art. 18 notifications, kept with the European Board for Digital Services | <https://digital-strategy.ec.europa.eu/en/library/points-contact-notifying-suspected-criminal-offences> |
| The child-abuse directive | Directive 2011/93/EU | <https://eur-lex.europa.eu/eli/dir/2011/93/oj> |
| AGPL-3.0-only | GNU Affero General Public License v3, for the code and the interface, translations included | <https://www.gnu.org/licenses/agpl-3.0.html> |
| ODbL 1.0 | Open Database License, for the data | <https://opendatacommons.org/licenses/odbl/1-0/> |
| CC BY-SA 4.0 | For standalone media and for the wiki prose | <https://creativecommons.org/licenses/by-sa/4.0/> |

The GDPR has four names in the five languages this site speaks, and the copy
uses whichever one a reader of that language would recognise. They are the same
regulation and they link to the same text.

Two notes on the licence rows, because the mapping is easy to get wrong.

**Interface text is code, not media.** The strings on the page, and the
translations of them, ship in the software and carry the software's licence.
CC BY-SA covers standalone media (rider photographs and the Wikimedia
photographs we reuse) and the wiki prose at wiki.cyclingcommons.org. It does
not cover a button label. See
[translations.md §6](translations.md) for the translation ledger and the one
constraint that still applies to strings given under the older consent wording.

**AGPL section 13 is a duty we owe our own users, not only a rule for forks.**
Anyone interacting with this software over a network must be offered its
Corresponding Source. The full posture, the bucket table it belongs to, and the
reserved brand are in
[osm-data-architecture.md §3](osm-data-architecture.md), which is canonical.
The licence texts themselves live in `LICENSES/`, and `TRADEMARK.md` covers the
name and the logo, which are deliberately not open.

## The articles we lean on, and where

| Citation | What it says | Where we rely on it |
|---|---|---|
| GDPR Art. 6(1)(a) | consent | showing a name, release emails |
| GDPR Art. 6(1)(b) | performance of a contract | the account itself |
| GDPR Art. 6(1)(c) | legal obligation | records we must keep; the statement of reasons (DSA Art. 17) and what an administrator gives the authorities under DSA Art. 18 (`privacy.why_legal`, `privacy.share_authorities`, privacy-notice.md §2) |
| GDPR Art. 6(1)(f) | legitimate interests | abuse prevention, aggregate analytics |
| GDPR Arts. 15 to 22 | the data subject's rights | `/privacy`, and the export archive |
| DSA Art. 16(1) | notice and action, any person, no account | `/report/{type}/{id}` sits outside the firewall |
| DSA Art. 16(2)(c) | a notice carries the reporter's name and email, except for content involving Arts. 3 to 7 of Directive 2011/93/EU | the address is required, except on the `intimate_or_child` ground |
| DSA Art. 16(4) | acknowledge receipt | `emails/report_acknowledged.html.twig` |
| DSA Art. 16(5) | tell the reporter the outcome | `emails/report_decided.html.twig` |
| DSA Art. 17 | statement of reasons to the author | `emails/report_statement_of_reasons.html.twig` |

## What we are NOT bound by, and say so

**US DMCA 512(g)**, the put-back clock: a US host that receives a counter-notice
must restore the material within ten to fourteen business days unless the
complainant sues. That buys a US host its safe harbour. We are an EU service, we
have no such obligation, and running it would mean restoring a photograph we
believe is stolen because a fortnight passed. A person decides instead, with the
claim and the answer on one row. <https://www.law.cornell.edu/uscode/text/17/512>

**DSA Section 3** duties, the internal complaints system, out-of-court dispute
settlement and annual transparency reports: micro and small enterprises are
exempt. Articles 16 and 17 are not part of that exemption and we meet them.
