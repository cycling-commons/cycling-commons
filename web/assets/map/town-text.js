// SPDX-License-Identifier: AGPL-3.0-only
/* The town card's text line: who wrote the text, and "Edit this text".

   Anyone signed in can suggest a change to a town card's text; a curator of
   the town's region approves it (docs/specs/moderation-and-contribution.md
   §3.1b, owner 2026-09-30). The card says so in one plain sentence and links
   the form, and the credit line carries a ringed pencil right after its "!"
   report mark that opens the same form. A visitor gets the same sentence and
   a sign-in link that comes back to the form; their pencil goes there too.
   Whether the reader is signed in is the page's own signal: the confirm
   token the riders' block carries (templates/map/index.html.twig).

   The credit follows who wrote the local text: the curators ("Edited by our
   curators"), or a rider whose proposal a curator approved, by the name the
   server may show (their public name, else their rider# handle). */
import { escPend, safeHref } from './util.js';

/* Unprefixed paths are English; the others carry their language. */
const PREFIX = { fr: '/fr', nl: '/nl', de: '/de', es: '/es' };

/** The form's address for one town, or '' when the card names no OSM element. */
export function townTextHref(meta, name, lang, from) {
  const [type, id] = String((meta && meta.osm) || '').split('/');
  if (!/^(node|way|relation)$/.test(type || '') || !/^\d{1,16}$/.test(id || '')) return '';
  const q = new URLSearchParams({ lang: lang || 'en' });
  if (name) q.set('name', name);
  const ll = (meta && meta.ll) || [];
  if (ll.length >= 2 && Number.isFinite(+ll[0]) && Number.isFinite(+ll[1])) {
    q.set('lat', (+ll[0]).toFixed(5));
    q.set('lng', (+ll[1]).toFixed(5));
  }
  if (from) q.set('from', from);
  return `${PREFIX[lang] || ''}/town/${type}/${id}/text?${q}`;
}

/** The licence and credit words for the text, before escaping. */
export function townCredit(d, D) {
  const t = d && d.text;
  const fromWiki = !!(t && t.url);
  if (!d || !d.edited) return D.wikiText || 'Text CC BY-SA 4.0';
  if (d.editedBy) {
    const name = d.editedBy.name || D.riderRemoved || 'a removed rider';
    return (fromWiki
      ? (D.wikiEditedBy || 'Edited by {name}, approved by our curators, after Wikipedia CC BY-SA 4.0')
      : (D.textWrittenBy || 'Written by {name}, approved by our curators, CC BY-SA 4.0')).replace('{name}', name);
  }
  return fromWiki
    ? (D.wikiEdited || 'Edited by our curators, after Wikipedia CC BY-SA 4.0')
    : (D.textWritten || 'Written by our curators, CC BY-SA 4.0');
}

/** Where "Edit this text" goes: the form, or for a visitor the sign-in that
    comes back to it; '' when the card names no element. */
export function townEditTarget(meta, name, opts) {
  const o = opts || {};
  const href = townTextHref(meta, name, o.lang, o.from);
  if (!href || o.signedIn) return href;
  return (PREFIX[o.lang] || '') + '/login?_target_path=' + encodeURIComponent(href);
}

/** The pencil after the credit line's "!", or '' when the card names no element. */
export function townPenHtml(meta, name, opts, D) {
  const target = townEditTarget(meta, name, opts);
  if (!target) return '';
  const label = escPend((opts || {}).signedIn ? (D.textEdit || 'Edit this text') : (D.textEditSignin || 'Sign in to edit this text'));
  return `<a class="cc-pen" href="${safeHref(target)}" title="${label}" aria-label="${label}">✎</a>`;
}

/** The sentence and the link under the text, or '' when the card names no element. */
export function townEditHtml(d, meta, name, opts, D) {
  const o = opts || {};
  const target = townEditTarget(meta, name, o);
  if (!target) return '';
  const words = !o.signedIn ? (D.textEditSignin || 'Sign in to edit this text')
    : ((d && d.text) ? (D.textEdit || 'Edit this text') : (D.textAdd || 'Write a text for this town'));
  const link = `<a class="cc-town-edit-link" href="${safeHref(target)}">${escPend(words)}</a>`;
  return `<p class="cc-town-edit">${escPend(D.textEditNote || 'Anyone signed in can suggest an edit to this text. A curator of this region approves it.')} ${link}</p>`;
}
