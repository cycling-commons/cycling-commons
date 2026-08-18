// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The wizard's real photo uploads (docs/specs/photo-uploads.md §4).

   The upload is ASYNCHRONOUS (media-storage-architecture.md §3.3): the server
   answers "received, checking" and the photo appears when a worker has scanned
   it. Two decisions shape everything below, both the owner's (2026-08-16):

   (a) OPTIMISTIC PREVIEW. While the worker runs, the chip shows the rider's
       OWN file through URL.createObjectURL, swapped for the served URL when
       it resolves. It is never another rider's unscanned bytes, because those
       bytes never leave the uploader's browser.

   (b) A 30-SECOND PATIENCE LIMIT, and what it is NOT. Past it the wizard stops
       WAITING and says "we will let you know". It does not fail the upload:
       the id stays in the hidden field, the photo is still submitted with the
       contribution, the worker finishes on its own schedule, and the rider is
       told when it lands. This is a client-side limit and never a server
       timeout - nothing here may cause a scan to be abandoned. Getting that
       backwards turns a slow scan into a lost contribution.

   Classic script, mounted by improve.js through window.Cc, the same idiom
   climb-editor.js uses, because the contribute templates load plain scripts,
   not modules.

   Consent is fail-closed here as everywhere: consentId starts null, is set
   ONLY from a server acknowledgement, and the file input plus drop zone stay
   disabled until it is. A failed consent POST keeps them disabled and shows
   the error in the modal. Nothing is remembered in localStorage: the source
   of truth is the server's consent ledger, re-read on every visit. */
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  window.Cc.mountMediaUploads = function (options) {
    var cfg = window.CC_MEDIA || {};
    var T = cfg.i18n || {};
    var MAX = cfg.max || 6;
    /* (b) above. Mirrored in ScanAndReleaseUploadHandler::PATIENCE_S, which
       uses it for ONE thing: deciding whether the rider has to be told by
       message that their photo landed after they stopped watching. */
    var PATIENCE_MS = 30000;
    var POLL_MS = 1200;

    var input = document.getElementById('file-photo');
    var zone = document.getElementById('drop-photo');
    var queueEl = document.getElementById('q-photo');
    var noticeEl = document.getElementById('media-consent');
    var reviewNoticeEl = document.getElementById('media-consent-review');
    var hidden = options && options.hidden;
    var onChange = (options && options.onChange) || function () {};
    if (!input || !zone || !queueEl || !hidden) return null;

    var consentId = null;   // FAIL-CLOSED: negative until the server says otherwise
    var csrfToken = null;
    var items = [];         // {id, name, row, bar, state}
    var consentHtml = '';   // the standing notice, '' until consent is known to exist

    function t(key, fallback) { return T[key] || fallback; }

    function esc(str) {
      return String(str == null ? '' : str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* Files a rider chose before consent existed. They are held here across
       the modal so agreeing uploads what they already picked, instead of
       throwing their choice away and making them find the photo twice. */
    var awaitingConsent = [];

    /* Through the shared formatter so a rider who asked for 01-08-2026 gets it
       here too (account-and-auth.md §9). Falls back to the old locale-long form
       if cc-dates.js somehow did not load, rather than printing nothing. */
    function fmtDate(iso) {
      if (!iso) return '';
      if (window.ccDate) return window.ccDate(iso);
      var d = new Date(iso);
      if (isNaN(d.getTime())) return '';
      try {
        return d.toLocaleDateString(document.documentElement.lang || undefined,
          { year: 'numeric', month: 'long', day: 'numeric' });
      } catch (e) { return iso.slice(0, 10); }
    }

    /* The standing notice (§4): once consent exists it is shown on every later
       visit, on the photo step AND beside the queued photos on review.
       The date is inside the sentence, not a bare number after it: "agreed on
       31 July 2026" says what the timestamp is FOR, where a loose date beside
       a restatement of the licence read as two ways of saying one thing. The
       disclosure below is then unambiguously the exact wording that was
       agreed, rather than a second paraphrase of it. */
    function renderConsent(consentedAt) {
      var terms = cfg.termsUrl
        ? ' <a href="' + esc(cfg.termsUrl) + '">' + esc(t('siteTerms', 'Site terms')) + '</a>'
        : '';
      var standing = esc(t('standing', '')).replace('%date%',
        '<span class="consent-when">' + esc(fmtDate(consentedAt)) + '</span>');
      consentHtml = '<div class="consent-ok">✓ ' + standing +
        ' <details class="consent-more"><summary>' + esc(t('readContract', 'Read the contract')) +
        '</summary><p>' + esc(t('contract', '')) + '</p></details>' + terms + '</div>';
      if (noticeEl) noticeEl.innerHTML = consentHtml;
      syncReviewNotice();
    }

    /* The photo step always shows the standing notice — that step IS about
       photos. The review step only shows it when this submission actually
       carries one. Consent is durable (§4: granted once, remembered), so a
       rider who donated a photo last month was otherwise told "your photos
       join the Commons" at the foot of a text-only correction that has no
       photos in it — a sentence about nothing, in the one place the rider is
       checking what they are actually sending. */
    function syncReviewNotice() {
      if (!reviewNoticeEl) return;
      reviewNoticeEl.innerHTML = (consentHtml && items.length) ? consentHtml : '';
    }

    /* Before consent exists there is nothing to show here. The rider drops a
       photo; the contract is put to them at that moment, about that photo,
       which is when it means something — rather than as a toll gate in front
       of a drop zone they cannot use yet. The licence terms are stated in
       full on this step regardless (the notice below the drop zone), so
       nothing is sprung on anyone. */
    function renderConsentPrompt() {
      consentHtml = '';
      if (noticeEl) noticeEl.innerHTML = '';
      syncReviewNotice();
    }

    /* ---------- consent ---------- */

    function openConsentModal() {
      var modal = document.getElementById('modal');
      var box = document.getElementById('modal-box');
      if (!modal || !box) return;
      box.innerHTML =
        '<h3>' + esc(t('title', '')) + '</h3>' +
        '<p>' + esc(t('intro', '')) + '</p>' +
        '<div class="rules">' + esc(t('keepOwnership', '')) + '</div>' +
        // The acknowledgement, with the licence on its own line beneath it: a
        // rider agreeing to a specific licence has to be able to read it
        // before ticking, not merely see it named. The link sits OUTSIDE the
        // label on purpose — inside it, clicking through to the licence would
        // also toggle the checkbox the rider had not decided on yet.
        '<label class="ok-check"><input type="checkbox" id="ok-check" />' +
        '<span>' + esc(t('contract', '')) + '</span></label>' +
        (cfg.licenceUrl
          ? '<p class="consent-licence"><a href="' + esc(cfg.licenceUrl)
            + '" target="_blank" rel="noopener license">'
            + esc(t('readLicence', 'Read the licence')) + ' \u2197</a></p>'
          : '') +
        '<div class="consent-err" id="consent-err" hidden></div>' +
        '<div class="mrow"><button type="button" class="b-cancel" id="modal-cancel">' +
        esc(t('cancel', 'Cancel')) + '</button>' +
        '<button type="button" class="b-ok" id="ok-btn" disabled>' + esc(t('accept', '')) + '</button></div>';
      modal.classList.add('open');

      var check = document.getElementById('ok-check');
      var ok = document.getElementById('ok-btn');
      var cancel = document.getElementById('modal-cancel');
      if (check && ok) check.addEventListener('change', function () { ok.disabled = !check.checked; });
      if (cancel) cancel.addEventListener('click', cancelConsent);
      if (ok) ok.addEventListener('click', function () { submitConsent(ok); });
    }

    function closeModal() {
      var modal = document.getElementById('modal');
      if (modal) modal.classList.remove('open');
    }

    /* Dismissing the contract is a refusal, so whatever was waiting on it is
       dropped rather than queued behind a decision the rider declined. */
    function cancelConsent() {
      awaitingConsent = [];
      closeModal();
    }

    function consentError(message) {
      var box = document.getElementById('consent-err');
      if (box) { box.textContent = message; box.hidden = false; }
    }

    function submitConsent(button) {
      button.disabled = true;
      button.classList.add('pending');
      ensureToken()
        .then(function (token) {
          var body = new URLSearchParams();
          body.set('_token', token);
          return fetch(cfg.consentUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json',
                       'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
          });
        })
        .then(function (res) { if (!res.ok) throw new Error('consent'); return res.json(); })
        .then(function (data) {
          // The ONLY place consentId is ever set to a non-null value.
          if (!data || !data.consentId) throw new Error('consent');
          consentId = data.consentId;
          renderConsent(data.consentedAt);
          closeModal();
          var held = awaitingConsent;
          awaitingConsent = [];
          if (held.length) accept(held);
        })
        .catch(function () {
          // Still locked. Still no consentId. That is the correct outcome.
          button.disabled = false;
          button.classList.remove('pending');
          consentError(t('consentError', 'Could not record your consent.'));
        });
    }

    function bootstrapConsent() {
      fetch(cfg.consentCurrentUrl, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (res) { if (!res.ok) throw new Error('bootstrap'); return res.json(); })
        .then(function (data) {
          if (data && data.consentId) {
            consentId = data.consentId;
            renderConsent(data.consentedAt);
          } else {
            renderConsentPrompt();
          }
        })
        .catch(function () { renderConsentPrompt(); });   // any error → no consent
    }

    /* ---------- csrf ---------- */

    function ensureToken() {
      if (csrfToken) return Promise.resolve(csrfToken);
      return fetch(cfg.tokenUrl, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (res) { if (!res.ok) throw new Error('token'); return res.json(); })
        .then(function (data) { csrfToken = data.token; return csrfToken; });
    }

    /* ---------- the queue ---------- */

    /* Is any photo still uploading?
       The wizard gates Next on this: a rider who presses Next mid-upload
       submits a form whose hidden media ids do not yet include the photo they
       are watching upload, so the photo is orphaned and the contribution
       arrives without it. Announced as a DOM event rather than a return value
       because the queue changes from three different places (enqueue, succeed,
       fail) and every one of them must move the button. */
    /* 'uploading' = bytes in flight. 'checking' = the worker has them and the
       rider is still watching. Both hold Next, because a rider who presses it
       mid-flight submits a form whose hidden ids may not include the photo
       they are watching. 'waiting' does NOT hold it: the id is already in the
       field and the rider was told we will follow up, so making them sit
       there would be the timeout this deliberately is not. */
    function announceBusy() {
      var busy = items.some(function (i) { return 'uploading' === i.state || 'checking' === i.state; });
      document.dispatchEvent(new CustomEvent('cc:media-busy', { detail: { busy: busy } }));
    }

    function syncHidden() {
      announceBusy();
      var ids = items.filter(function (i) { return i.id; }).map(function (i) { return i.id; });
      hidden.value = ids.length ? JSON.stringify(ids) : '';
      syncReviewNotice();   // first photo in / last photo out flips the review notice
      // Name AND thumbnail: the review step shows the photos themselves, and a
      // rider checking their submission over should be looking at the pictures
      // rather than at a list of filenames.
      onChange(items.map(function (i) { return { name: i.name, sm: i.sm || null }; }));
    }

    function addRow(name) {
      var row = document.createElement('span');
      row.className = 'chip up';
      row.innerHTML =
        '<span class="chip-thumb"></span>' +
        '<span class="chip-name">' + esc(name) + '</span>' +
        '<span class="chip-bar"><i style="width:0%"></i></span>' +
        '<button type="button" class="chip-x" aria-label="' + esc(t('remove', 'Remove')) + '">×</button>';
      queueEl.appendChild(row);
      var item = { id: null, name: name, row: row, bar: row.querySelector('.chip-bar i'), state: 'uploading' };
      row.querySelector('.chip-x').addEventListener('click', function () { removeItem(item); });
      items.push(item);
      announceBusy();
      return item;
    }

    function removeItem(item) {
      item.state = 'removed';   // stops any poll still in flight
      releaseBlob(item);
      announceBusy();
      // Forgetting the id is all that is needed: an unclaimed object becomes an
      // orphan and is collected after seven days (docs/specs/photo-uploads.md §6).
      items = items.filter(function (i) { return i !== item; });
      if (item.row.parentNode) item.row.parentNode.removeChild(item.row);
      syncHidden();
    }

    function setProgress(item, percent) {
      item.row.classList.remove('indeterminate');
      if (item.bar) item.bar.style.width = percent + '%';
    }

    function setIndeterminate(item) {
      item.row.classList.add('indeterminate');
    }

    /* The server has the bytes and is checking them. The id counts from this
       moment: it goes into the hidden field now, so a rider who submits while
       the worker is still running still submits the photo. */
    function received(item, data, file) {
      item.id = data.id;
      item.state = 'checking';
      item.row.classList.remove('indeterminate');
      item.row.classList.add('checking');
      setThumb(item, localPreview(item, file), item.name);
      var bar = item.row.querySelector('.chip-bar');
      if (bar) bar.outerHTML = '<span class="chip-note">' + esc(t('checking', 'Checking…')) + '</span>';
      syncHidden();
      pollState(item, Date.now());
    }

    /* (a): the rider's own file, straight from their disk, never uploaded to
       anyone to be shown back. Revoked when the served URL replaces it, and on
       removal, so a wizard left open all afternoon holds no blobs. */
    function localPreview(item, file) {
      if (!file || !window.URL || !window.URL.createObjectURL) return null;
      item.blobUrl = window.URL.createObjectURL(file);
      return item.blobUrl;
    }

    function releaseBlob(item) {
      if (item.blobUrl && window.URL && window.URL.revokeObjectURL) {
        window.URL.revokeObjectURL(item.blobUrl);
      }
      item.blobUrl = null;
    }

    function setThumb(item, src, alt) {
      if (!src) return;
      var thumb = item.row.querySelector('.chip-thumb');
      if (!thumb) return;
      var img = thumb.querySelector('img');
      if (!img) {
        img = document.createElement('img');
        img.loading = 'lazy';
        thumb.appendChild(img);
      }
      img.src = src;
      img.alt = alt;
    }

    /* The checks finished while the rider was still here. */
    function landed(item, data) {
      item.sm = data.sm;
      item.state = 'done';
      item.row.classList.remove('checking');
      item.row.classList.add('done');
      setThumb(item, data.sm, item.name);
      releaseBlob(item);
      var note = item.row.querySelector('.chip-note');
      if (note && note.parentNode) note.parentNode.removeChild(note);
      syncHidden();
    }

    /* (b): we stop watching, we do not stop caring. The photo keeps its id and
       travels with the submission; the local preview stays on screen because
       it is still the truest picture of what was sent. */
    function stopWaiting(item) {
      item.state = 'waiting';
      item.row.classList.remove('checking');
      item.row.classList.add('waiting');
      var note = item.row.querySelector('.chip-note');
      if (note) note.textContent = t('stillChecking', 'Still checking. We will let you know.');
      syncHidden();
    }

    /* Poll rather than push: one small JSON read every second or so, for at
       most half a minute, against a server that answers from one row. A socket
       for this would be more machinery than the question deserves. */
    function pollState(item, startedAt) {
      if ('checking' !== item.state) return;   // removed, or already resolved
      if (Date.now() - startedAt >= PATIENCE_MS) { stopWaiting(item); return; }

      window.setTimeout(function () {
        if ('checking' !== item.state) return;
        fetch(stateUrl(item.id), {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
          .then(function (res) { if (!res.ok) throw new Error('state'); return res.json(); })
          .then(function (data) {
            if ('checking' !== item.state) return;
            if (data && data.ready) { landed(item, data); return; }
            if (data && data.error) { fail(item, data.error); return; }
            pollState(item, startedAt);
          })
          // A blip is not an answer: keep asking until the window closes.
          .catch(function () { pollState(item, startedAt); });
      }, POLL_MS);
    }

    function stateUrl(id) {
      return cfg.stateUrl
        ? cfg.stateUrl.replace('__ID__', encodeURIComponent(id))
        : cfg.uploadUrl + '/' + encodeURIComponent(id);
    }

    function fail(item, reason) {
      item.state = 'error';
      /* A photo the worker refused is not part of this contribution. Dropping
         the id here is what keeps the submission from carrying a dead one. */
      item.id = null;
      releaseBlob(item);
      item.row.classList.remove('indeterminate');
      item.row.classList.remove('checking');
      item.row.classList.add('failed');
      var slot = item.row.querySelector('.chip-bar') || item.row.querySelector('.chip-note');
      if (slot) slot.outerHTML = '<span class="chip-err">' + esc(errorText(reason)) + '</span>';
      syncHidden();
    }

    function errorText(reason) {
      var errors = cfg.i18n && cfg.i18n.errors;
      return (errors && errors[reason]) || (errors && errors.unknown) || 'Upload failed.';
    }

    /* ---------- the transfer ---------- */

    function pin(key) {
      var field = document.querySelector('[name="improve[' + key + ']"]');
      var value = field && field.value;
      return value !== '' && value != null && isFinite(parseFloat(value)) ? String(parseFloat(value)) : null;
    }

    function upload(file, item) {
      /* The pin is REQUIRED (server: missing_location 422): every photo
         belongs to a located place, and the EXIF GPS is only the second
         verification. Refusing here saves the rider a full upload that the
         server would refuse anyway. */
      var lat = pin('lat');
      var lng = pin('lng');
      if (!lat || !lng) { fail(item, 'missing_location'); return Promise.resolve(); }
      return ensureToken().then(function (token) {
        return new Promise(function (resolve) {
          var form = new FormData();
          form.append('photo', file);
          form.append('_token', token);
          form.append('consentId', consentId);
          form.append('lat', lat);
          form.append('lng', lng);

          var xhr = new XMLHttpRequest();
          xhr.open('POST', cfg.uploadUrl, true);
          xhr.withCredentials = true;
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.setRequestHeader('Accept', 'application/json');

          // Real bytes, not a spinner (§4). Browsers that cannot compute the
          // length get an honest indeterminate pulse instead of a fake number.
          xhr.upload.onprogress = function (event) {
            if (event.lengthComputable && event.total > 0) {
              setProgress(item, Math.round(100 * event.loaded / event.total));
            } else {
              setIndeterminate(item);
            }
          };
          xhr.onload = function () {
            var payload = {};
            try { payload = JSON.parse(xhr.responseText || '{}'); } catch (e) { payload = {}; }
            if (xhr.status >= 200 && xhr.status < 300 && payload.id) {
              received(item, payload, file);
            } else if (401 === xhr.status || 403 === xhr.status) {
              csrfToken = null;            // stale token: the next attempt re-fetches
              fail(item, 'unknown');
            } else {
              fail(item, payload.error || 'unknown');
            }
            resolve();
          };
          xhr.onerror = function () { fail(item, 'unknown'); resolve(); };
          xhr.send(form);
        });
      }).catch(function () { fail(item, 'unknown'); });
    }

    function accept(fileList) {
      var files = Array.prototype.slice.call(fileList || []);
      if (!files.length) return;
      // FAIL-CLOSED still: nothing is uploaded until the server has stored a
      // consent record. The files simply wait here while the rider decides.
      if (!consentId) { awaitingConsent = files; openConsentModal(); return; }
      for (var i = 0; i < files.length; i++) {
        if (items.length >= MAX) {
          var full = document.createElement('span');
          full.className = 'chip failed';
          full.textContent = t('tooMany', '');
          queueEl.appendChild(full);
          (function (el) {
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 4000);
          })(full);
          break;
        }
        upload(files[i], addRow(files[i].name));
      }
    }

    /* ---------- wiring ---------- */

    renderConsentPrompt();
    bootstrapConsent();

    zone.addEventListener('click', function () { input.click(); });
    zone.addEventListener('keydown', function (e) {
      if ('Enter' === e.key || ' ' === e.key) { e.preventDefault(); zone.click(); }
    });
    input.addEventListener('change', function () { accept(input.files); input.value = ''; });
    ['dragenter', 'dragover'].forEach(function (name) {
      zone.addEventListener(name, function (e) { e.preventDefault(); zone.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (name) {
      zone.addEventListener(name, function (e) { e.preventDefault(); zone.classList.remove('over'); });
    });
    zone.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files) accept(e.dataTransfer.files);
    });

    return { count: function () { return items.length; } };
  };
})();
