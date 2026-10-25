// SPDX-License-Identifier: AGPL-3.0-only
/* "Read more" under a region page's two texts on a phone (map-and-search.md
   §4.6): the geography and the history text show three lines, faded out,
   and a button that shows the rest. A text of three lines or fewer gets no
   button. On a wider screen, and without this script, the whole text shows:
   the clamp is a class this script sets and a phone-only rule in region.css.

   A file rather than an inline block, so the page carries no CSP nonce. */
(function () {
  var phone = window.matchMedia('(max-width: 759px)');
  var texts = document.querySelectorAll('[data-read-more]');
  if (texts.length === 0) { return; }

  Array.prototype.forEach.call(texts, function (text, i) {
    if (!text.id) { text.id = 'rg-read-more-' + i; }
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'rg-more';
    button.textContent = text.getAttribute('data-read-more') || 'Read more';
    button.setAttribute('aria-controls', text.id);
    button.setAttribute('aria-expanded', 'false');
    button.hidden = true;
    text.parentNode.insertBefore(button, text.nextSibling);

    button.addEventListener('click', function () {
      text.classList.remove('is-clamped');
      button.hidden = true;
      button.setAttribute('aria-expanded', 'true');
    });

    /* The button only where the clamp hides something. */
    function fit() {
      if (button.getAttribute('aria-expanded') === 'true') { return; }
      text.classList.add('is-clamped');
      button.hidden = !(phone.matches && text.scrollHeight > text.clientHeight + 1);
    }
    fit();
    if (phone.addEventListener) { phone.addEventListener('change', fit); }
    window.addEventListener('resize', fit);
  });
})();
