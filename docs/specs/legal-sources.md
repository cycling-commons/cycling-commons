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
| AI Act | Regulation (EU) 2024/1689, artificial intelligence | <https://eur-lex.europa.eu/eli/reg/2024/1689/oj> |
| Brussels Ia | Regulation (EU) 1215/2012, jurisdiction in civil and commercial matters | <https://eur-lex.europa.eu/eli/reg/2012/1215/oj> |
| Rome I | Regulation (EC) 593/2008, the law applicable to contracts | <https://eur-lex.europa.eu/eli/reg/2008/593/oj> |
| CJEU C-191/15 | *Verein für Konsumenteninformation v Amazon EU*, 28 July 2016: a choice-of-law term that does not tell a consumer they keep their own country's mandatory law is unfair | <https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:62015CJ0191> |
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
| DSA Art. 11 | a single point of contact for Member State authorities, the Commission and the Board | terms §16: the contact page, in the five site languages |
| DSA Art. 12 | a single point of contact for recipients, by electronic means, not only automated tools | terms §16: the contact page, read by a person, in the five site languages |
| DSA Art. 14(1) | the terms state the moderation policies, procedures and tools, including algorithmic decision-making and human review, and complaint handling | terms §12 (the grounds in full; who decides; what software does on its own and which decisions send a statement, as rules with examples; appeal; reports) and §13 (where machines are involved); the full lists are under "The terms: the rule on the page" below |
| DSA Art. 14(2) | tell recipients about any significant change to the terms | terms §15; `App\Legal\LegalNotice` emails every account at least 30 days ahead (`emails/legal_change.html.twig`); texts the terms include by reference are pinned by hash to the terms version that last accepted them (`TermsIncludedTexts`, `TermsIncludedTextsTest`, translations.md §6.2) |
| DSA Art. 16(1) | notice and action, any person, no account | `/report/{type}/{id}` sits outside the firewall |
| DSA Art. 16(2)(c) | a notice carries the reporter's name and email, except for content involving Arts. 3 to 7 of Directive 2011/93/EU | the address is required, except on the `intimate_or_child` ground |
| DSA Art. 16(4) | acknowledge receipt | `emails/report_acknowledged.html.twig` |
| DSA Art. 16(5) | tell the reporter the outcome | `emails/report_decided.html.twig` |
| DSA Art. 17 | statement of reasons to whoever's content or account is restricted | `App\Moderation\StatementOfReasons`, one wording for every path: rejections, Trash as abuse, retired places and routes, photos removed, hidden or refused, upheld reports (a place's author is the rider who added it), in the rider's messages and by email (content-reports.md §7); account suspension and removal for a breach, by email (account-and-auth.md §6.8). Art. 17(2): Trash as spam sends none |
| DSA Art. 18 | notify the authorities of a suspected criminal offence threatening life or safety | Escalate holds the photo or submission and alerts an administrator, who notifies the authority by hand and records it on the held row (`AuthorityNotifications`, `/admin/escalated`); overdue after 24 hours; procedure and authority per country in operations.md §7; terms §12 (`terms.mod_escalate`) and the privacy notice (`privacy.share_authorities`, `privacy.retention_authorities`) say so |
| AI Act Art. 50 | transparency about content a machine generated or manipulated | terms §13: machine-translated descriptions are labelled where they appear, generated photos are a ground for removal; no compliance claimed |
| Brussels Ia Art. 18 | a consumer may sue in their own courts, and can be sued only there | terms §14 |
| Rome I Art. 6(2) | a chosen law cannot take away the mandatory protection of the consumer's own law | terms §14, worded as C-191/15 asks |

## The terms: the rule on the page, the full list in the specs

Owner, 2026-10-10: "every detail is a promise; this is getting to be too big
an administration, and possibly needless constant updates to the terms or
privacy document." Where `/terms` would list every case of something, it
states the rule and one to three examples, and the spec named below holds the
full list and the code. A change the rule already covers updates that spec,
not the terms. The privacy notice follows the same rule (privacy-notice.md §9).

Kept exact on the page, because the law asks for them or readers rely on them:
the grounds a report can name (terms §12, `terms.mod_std1` to `mod_std9`:
DSA Art. 14(1) asks for the restrictions themselves, the list is nine lines
and stable, and `ReportGround` and the report form mirror it word for word);
the 1 to 365 days of a suspension and that the ground and the facts are
stated; that every decision restricting a rider's content or account carries a
statement of reasons, with its Art. 17(3) elements; the reply route for
contesting a decision; the Art. 18 duty and its fallback authority; the single
point of contact and its languages; the change rules (versions, the 30-day
email, the included texts); and the licence names.

| On the page | Rule and examples | The full list |
|---|---|---|
| Which decisions send a statement of reasons (`terms.mod_told_post`) | every decision that restricts something a rider added, with four examples; the elements of the statement in full | content-reports.md §7, "Which decisions send one" |
| Which decisions send none (`terms.mod_told_p2`) | spam to the bin, a legal hold, edits, and content with no single author, each as a rule; the urgent hide's statement after review | content-reports.md §7, "Which send none, and why" |
| The ground of a suspension or removal (`terms.suspension_admin`) | "we always name the ground, such as spam, unlawful content or misuse of the service" | `StatementGround::forAccounts()`, account-and-auth.md §6.8 |
| What software does on its own (`terms.mod_who`, `mod_automated`, `mod_auto_*`, `ai_checks`, `ai_none`) | four kinds as examples (files, scenic photos, urgent reports, accounts); §13 says the unsafe-link check only warns the curator who reviews a submission, and hides nothing | the inventory below; the link check is catalog-data-model.md §7 |
| Dormant accounts (`terms.suspension_dormant`) | "we email you first, more than once" | `DormancyLadder::NOTICES`, account-and-auth.md §6.5 |
| What stays after closing an account (`terms.suspension_p2`) | three examples, and the privacy notice for the rest | privacy-notice.md §2, the deletion row |
| The bulk export (`terms.opendata_export_post`) | "the data of the Commons, such as its places and routes", its licences, and what it never holds; no file format | api-strategy.md §3.1 |
| Scenic photos (`terms.mod_auto_scenic`) | "taken too far from the pin"; no distance | scenic-views.md rule 4 |
| The urgent hide (`terms.mod_auto_urgent`) | "safeguards stop the report form from being used to take photos down in bulk"; no budget, and not what happens over it | photo-uploads.md §6c, `UrgentWithholdBreaker` |

**What software does on its own, in full.** Terms §12 and §13 give examples.
A new case of a kind listed here updates this table; a new kind of decision
changes the terms first, as `terms.ai_none` says.

| What | Code |
|---|---|
| An upload that is infected, unreadable, or not an accepted format or size is refused, with an automated statement of reasons | `ScanAndReleaseUploadHandler::refuse()` (content-reports.md §7) |
| A scenic-view photo taken more than 250 m from the pin, or with no known camera position, stays hidden on that place until a curator confirms it | `PhotoLocationConfirmation` (scenic-views.md rule 4) |
| A report on the intimate-imagery-or-child ground hides the photo at once, within a site-wide budget | `ReportGround::autoWithholds()`, `UrgentWithholdBreaker` (photo-uploads.md §6c) |
| An account whose address is not confirmed within 7 days is deleted | `UnverifiedSweep` (account-and-auth.md §6.7) |
| An account unused for 24 months is deleted after the warnings | `DormancySweep` (account-and-auth.md §6.5) |
| Repeated failed sign-ins pause signing in to an account | account-and-auth.md §3 |

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
