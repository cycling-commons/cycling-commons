// SPDX-License-Identifier: AGPL-3.0-only
/* "Edit this text": the current text of the chosen language shows read-only
   above an empty box (docs/specs/moderation-and-contribution.md §3.1b).

   Picking another language swaps that quote, or says plainly there is no
   text in that language yet. The box keeps whatever the rider wrote. Under
   the box a polite live count says how many of the allowed characters are
   used, counted as the server counts them (code points); the server enforces
   the limit either way. The adaptation tick of a region lead follows the
   language too, and hides where there is no article to adapt; the server
   checks it again either way.

   A file rather than an inline block, so the page carries no CSP nonce. */
(function () {
  var form = document.getElementById('place-text-form');
  var select = document.getElementById('pt-lang');
  var box = document.getElementById('pt-text');
  if (!form || !select || !box) { return; }
  var texts = {};
  var derived = null;
  try { texts = JSON.parse(form.getAttribute('data-texts') || '{}'); } catch (e) { texts = {}; }
  try { derived = form.hasAttribute('data-derived') ? JSON.parse(form.getAttribute('data-derived')) : null; } catch (e) { derived = null; }
  var field = document.getElementById('pt-derived-field');
  var tick = document.getElementById('pt-derived');
  var quote = document.getElementById('pt-current-text');
  var none = document.getElementById('pt-current-none');
  var count = document.getElementById('pt-text-count');

  function showCurrent(lang) {
    if (!quote || !none) { return; }
    var t = typeof texts[lang] === 'string' ? texts[lang] : '';
    quote.textContent = t;
    quote.hidden = t === '';
    none.hidden = t !== '';
  }

  function recount() {
    if (!count) { return; }
    var max = parseInt(count.getAttribute('data-max'), 10) || 0;
    var n = Array.from(box.value).length;
    count.textContent = (count.getAttribute('data-words') || '%count% / %max%')
      .replace('%count%', String(n)).replace('%max%', String(max));
    count.classList.toggle('is-over', max > 0 && n > max);
  }

  select.addEventListener('change', function () {
    var lang = select.value;
    showCurrent(lang);
    if (derived && field && tick) {
      var d = derived[lang];
      field.hidden = d === null || d === undefined;
      tick.checked = d === true;
    }
  });
  box.addEventListener('input', recount);
  recount();
})();
