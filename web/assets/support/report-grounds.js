// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Which grounds and which extra fields the content-report form shows, as the
   reporter picks what they are reporting and why.
   docs/specs/content-reports.md §5

   A file rather than an inline block, so the page carries no CSP nonce and may
   be held in a shared cache (docs/specs/page-caching.md §3.2). Nothing here
   reads server data: every value it needs is already a data attribute or an
   element id, which is why it moved out unchanged.

   Deferred, because it only touches elements the document has finished
   parsing. */
        (function () {
          var sel = document.getElementById('rep-ground');
          if (!sel) { return; }
          var rights = document.getElementById('rep-rights');
          var work = document.getElementById('rep-work');
          var claimant = document.getElementById('rep-claimant');
          var statement = document.getElementById('rep-statement');
          var contact = document.getElementById('rep-contact');
          var req = document.getElementById('rep-contact-req');
          var hintOpt = document.getElementById('rep-contact-hint-optional');

          var about = document.getElementsByName('about');

          /* A ground that only a photograph can break is offered only while a
             photograph is the thing being reported. Hidden AND disabled: a
             hidden option stays selectable with a keyboard in some browsers. */
          function syncGrounds() {
            if (!about.length) { return; }
            var onPhoto = false;
            for (var i = 0; i < about.length; i++) {
              if (about[i].checked && about[i].value !== 'entry') { onPhoto = true; }
            }
            for (var j = 0; j < sel.options.length; j++) {
              var o = sel.options[j];
              if (o.dataset.photoOnly !== '1') { continue; }
              o.hidden = !onPhoto;
              o.disabled = !onPhoto;
              if (!onPhoto && o.selected) { sel.selectedIndex = 0; }
            }
          }

          function sync() {
            syncGrounds();
            var opt = sel.options[sel.selectedIndex];
            var proof = opt && opt.dataset.needsProof === '1';
            var noContact = opt && opt.dataset.noContact === '1';

            rights.hidden = !proof;
            work.required = proof;
            claimant.required = proof;
            statement.required = proof;

            /* Absent for a signed-in reader: the account's address is the contact. */
            if (contact) { contact.required = !noContact; }
            if (req) { req.hidden = noContact; }
            if (hintOpt) { hintOpt.hidden = !noContact; }
          }

          sel.addEventListener('change', sync);
          for (var k = 0; k < about.length; k++) { about[k].addEventListener('change', sync); }
          sync();
        })();
