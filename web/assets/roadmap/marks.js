// SPDX-License-Identifier: AGPL-3.0-only
/* Votes and follows on /roadmap without a page reload. Each item's two
   buttons sit in a plain form that posts to /roadmap/mark and works with no
   script; this file sends the same post in the background and paints the
   answer: pressed or not, and the new vote count.

   While a post is out the form's buttons are disabled, so a second click
   cannot flip the mark back. A refusal the server explains (403, 404, 429)
   falls back to the plain post, which shows the server's message the usual
   way. Any other failure leaves the buttons as they were and sends nothing
   again: the first post may already have flipped the mark, and a second one
   would flip it back. */
document.addEventListener('DOMContentLoaded', () => {
  const EXPLAINED = [403, 404, 429];
  for (const form of document.querySelectorAll('form[data-roadmap-mark]')) {
    form.addEventListener('submit', async (ev) => {
      const button = ev.submitter;
      if (!button) return;
      ev.preventDefault();
      if (form.dataset.busy) return;
      form.dataset.busy = '1';
      const buttons = Array.from(form.querySelectorAll('button'));
      for (const b of buttons) b.disabled = true;
      const body = new FormData(form);
      body.set('do', button.value);
      let res = null;
      try {
        res = await fetch(form.action, {
          method: 'POST',
          body,
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        if (res.ok) {
          const data = await res.json();
          const on = data.on === true;
          button.setAttribute('aria-pressed', on ? 'true' : 'false');
          button.title = on ? button.dataset.titleOn : button.dataset.titleOff;
          if (button.value === 'vote') {
            const count = form.querySelector('.rcount');
            if (count) count.textContent = String(data.votes);
            if (button.dataset.aria) button.setAttribute('aria-label', button.dataset.aria.replace('%count%', String(data.votes)));
          } else {
            const label = button.querySelector('.rfollow-l');
            if (label) label.textContent = on ? button.dataset.on : button.dataset.off;
          }
        }
      } catch (e) {
        // Network trouble: the post may or may not have arrived, so nothing is sent again.
      }
      for (const b of buttons) b.disabled = false;
      delete form.dataset.busy;
      if (res && !res.ok && EXPLAINED.includes(res.status)) {
        // Let the plain form say what went wrong.
        const fallback = document.createElement('input');
        fallback.type = 'hidden';
        fallback.name = 'do';
        fallback.value = button.value;
        form.appendChild(fallback);
        form.submit();
      }
    });
  }
});
