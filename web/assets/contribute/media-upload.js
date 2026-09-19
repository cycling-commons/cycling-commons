// SPDX-License-Identifier: AGPL-3.0-only
/* Wizard photo uploads (docs/specs/photo-uploads.md §4).
   Async scan: preview the rider's own file; after 30s stop WAITING, keep the
   id, do not abandon the scan. Consent is fail-closed: consentId is set only
   from a server acknowledgement. */
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  window.Cc.mountMediaUploads = function (options) {
    var cfg = window.CC_MEDIA || {};
    var T = cfg.i18n || {};
    var MAX = cfg.max || 6;
    /* Mirrored in ScanAndReleaseUploadHandler::PATIENCE_S (follow-up message, not a scan timeout). */
    var PATIENCE_MS = 30000;
    var POLL_MS = 1200;

    var input = document.getElementById('file-photo');
    var zone = document.getElementById('drop-photo');
    var queueEl = document.getElementById('q-photo');
    var noticeEl = document.getElementById('media-consent');
    var reviewNoticeEl = document.getElementById('media-consent-review');
    var hidden = options && options.hidden;
    /* The descriptions, keyed by upload id, in a second hidden field. The
       live save below is what normally lands them; this copy is what lands
       them when it does not, at claim time on the server. Typing a
       description and pressing Next in one breath used to lose it
       (owner, 2026-09-06). */
    var hiddenAlts = options && options.hiddenAlts;
    var onChange = (options && options.onChange) || function () {};
    if (!input || !zone || !queueEl || !hidden) return null;

    var consentId = null;   // FAIL-CLOSED until the server says otherwise
    var csrfToken = null;
    var items = [];
    var consentHtml = '';

    function t(key, fallback) { return T[key] || fallback; }

    function esc(str) {
      return String(str == null ? '' : str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* Files chosen before consent: held across the modal so agreeing uploads them. */
    var awaitingConsent = [];

    /* Shared date formatter (docs/specs/account-and-auth.md §9). */
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

    /* Standing notice once consent exists (docs/specs/photo-uploads.md §4). */
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

    /* Review step shows the notice only when this submission actually carries a photo. */
    function syncReviewNotice() {
      if (!reviewNoticeEl) return;
      reviewNoticeEl.innerHTML = (consentHtml && items.length) ? consentHtml : '';
    }

    function renderConsentPrompt() {
      consentHtml = '';
      if (noticeEl) noticeEl.innerHTML = '';
      syncReviewNotice();
    }

    function openConsentModal() {
      var modal = document.getElementById('modal');
      var box = document.getElementById('modal-box');
      if (!modal || !box) return;
      box.innerHTML =
        '<h3>' + esc(t('title', '')) + '</h3>' +
        '<p>' + esc(t('intro', '')) + '</p>' +
        '<div class="rules">' + esc(t('keepOwnership', '')) + '</div>' +
        // Licence link sits outside the label so clicking it does not toggle the checkbox.
        '<label class="ok-check"><input type="checkbox" id="ok-check" />' +
        '<span>' + esc(t('contract', '')) + '</span></label>' +
        /* TWO links, because the sentence above makes two different promises
           and only one of them is the licence's. CC BY-SA 4.0 governs how the
           photo may be shared and says nothing whatever about who took it or
           about AI: those are OUR conditions and they live in our terms.
           Offering the licence alone invited somebody to tick "not generated
           or altered by AI" and then read a document that never mentions it
           (owner, 2026-08-30). */
        (cfg.licenceUrl
          ? '<p class="consent-licence"><a href="' + esc(cfg.licenceUrl)
            + '" target="_blank" rel="noopener license">'
            + esc(t('readLicence', 'Read CC BY-SA 4.0')) + ' \u2197</a></p>'
          : '') +
        (cfg.termsUrl
          ? '<p class="consent-licence"><a href="' + esc(cfg.termsUrl)
            + '" target="_blank" rel="noopener">'
            + esc(t('readRules', 'What we accept, and what we do not')) + ' \u2197</a></p>'
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

    /* Dismissing the contract is a refusal: drop files that were waiting on it. */
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
          if (!data || !data.consentId) throw new Error('consent');
          consentId = data.consentId;
          renderConsent(data.consentedAt);
          closeModal();
          var held = awaitingConsent;
          awaitingConsent = [];
          if (held.length) accept(held);
        })
        .catch(function () {
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
        .catch(function () { renderConsentPrompt(); });
    }

    function ensureToken() {
      if (csrfToken) return Promise.resolve(csrfToken);
      return fetch(cfg.tokenUrl, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (res) { if (!res.ok) throw new Error('token'); return res.json(); })
        .then(function (data) { csrfToken = data.token; return csrfToken; });
    }

    /* uploading/checking hold Next; waiting does not (id is already in the field). */
    function announceBusy() {
      var busy = items.some(function (i) { return 'uploading' === i.state || 'checking' === i.state; });
      document.dispatchEvent(new CustomEvent('cc:media-busy', { detail: { busy: busy } }));
    }

    function syncHidden() {
      announceBusy();
      var ids = items.filter(function (i) { return i.id; }).map(function (i) { return i.id; });
      hidden.value = ids.length ? JSON.stringify(ids) : '';
      if (hiddenAlts) {
        var alts = {};
        items.forEach(function (i) { if (i.id && i.alt) alts[i.id] = i.alt; });
        hiddenAlts.value = Object.keys(alts).length ? JSON.stringify(alts) : '';
      }
      syncReviewNotice();
      /* `alt` travels with the entry so the review card can show what the rider
         WROTE about the picture rather than what their phone called the file
         (owner, 2026-08-30). A filename is not a description, and reviewing
         one tells somebody nothing about what they just added. */
      onChange(items.map(function (i) { return { name: i.name, sm: i.sm || null, alt: i.alt || '' }; }));
    }

    function addRow(name) {
      var row = document.createElement('span');
      row.className = 'uq-row up';
      row.innerHTML =
        '<span class="uq-thumb"></span>' +
        '<span class="uq-name">' + esc(name) + '</span>' +
        '<span class="uq-bar"><i style="width:0%"></i></span>' +
        '<button type="button" class="uq-x" aria-label="' + esc(t('remove', 'Remove')) + '">×</button>' +
        /* Hidden until the upload has an id to attach a description to. The
           prompt asks what somebody who cannot see it needs to know, rather
           than saying "alt text", which means nothing to a rider. */
        '<label class="uq-alt" hidden><span class="vh">' + esc(t('altLabel', 'Describe this photo')) + '</span>' +
        '<input type="text" maxlength="300" placeholder="' + esc(t('altPlaceholder', 'What would somebody who cannot see it need to know?')) + '" /></label>';
      queueEl.appendChild(row);
      var item = { id: null, name: name, alt: '', row: row, bar: row.querySelector('.uq-bar i'), state: 'uploading' };
      row.querySelector('.uq-x').addEventListener('click', function () { removeItem(item); });

      var altInput = row.querySelector('.uq-alt input');
      /* Every keystroke reaches the hidden copy, so the last word typed
         before Next is in the submission even if no change event fires. */
      altInput.addEventListener('input', function () {
        item.alt = altInput.value;
        syncHidden();
      });
      altInput.addEventListener('change', function () {
        /* Held on the item whether or not the save lands, so the review card
           shows what they typed even while the network is being difficult. */
        item.alt = altInput.value;
        syncHidden();
        /* No id yet means the upload has not landed; the field is hidden then,
           so this is belt and braces rather than a real path. */
        if (!item.id) return;
        var body = new FormData();
        body.append('_token', token);
        body.append('alt', altInput.value);
        /* keepalive: the change event fires on the blur that a click on
           Next causes, and without it the browser cancels this request
           when the page moves on. */
        fetch(cfg.uploadUrl + '/' + encodeURIComponent(item.id) + '/alt', {
          method: 'POST', body: body, credentials: 'same-origin', keepalive: true
        }).then(function (r) {
          /* Silent on success. A failure must not eat what they typed, so the
             field keeps its value and the next change tries again. */
          row.querySelector('.uq-alt').classList.toggle('saved', r.ok);
        }).catch(function () { /* offline: the value stays, retried on next change */ });
      });
      items.push(item);
      announceBusy();
      return item;
    }

    function removeItem(item) {
      item.state = 'removed';
      releaseBlob(item);
      announceBusy();
      // Unclaimed object becomes an orphan after seven days (docs/specs/photo-uploads.md §6).
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

    /* Id goes into the hidden field now so submit during scan still includes the photo. */
    function received(item, data, file) {
      item.id = data.id;
      /* There is now something to attach a description to, so offer the field. */
      var altWrap = item.row.querySelector('.uq-alt');
      if (altWrap) altWrap.hidden = false;
      item.state = 'checking';
      item.row.classList.remove('indeterminate');
      item.row.classList.add('checking');
      setThumb(item, localPreview(item, file), item.name);
      var bar = item.row.querySelector('.uq-bar');
      if (bar) bar.outerHTML = '<span class="uq-note">' + esc(t('checking', 'Checking…')) + '</span>';
      syncHidden();
      pollState(item, Date.now());
    }

    /* Local preview of the rider's own file; revoke when replaced or removed. */
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
      var thumb = item.row.querySelector('.uq-thumb');
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

    function landed(item, data) {
      item.sm = data.sm;
      item.state = 'done';
      item.row.classList.remove('checking');
      item.row.classList.add('done');
      setThumb(item, data.sm, item.name);
      releaseBlob(item);
      var note = item.row.querySelector('.uq-note');
      if (note && note.parentNode) note.parentNode.removeChild(note);
      syncHidden();
    }

    /* Stop waiting, keep the id — the photo still travels with the submission. */
    function stopWaiting(item) {
      item.state = 'waiting';
      item.row.classList.remove('checking');
      item.row.classList.add('waiting');
      var note = item.row.querySelector('.uq-note');
      if (note) note.textContent = t('stillChecking', 'Still checking. We will let you know.');
      syncHidden();
    }

    function pollState(item, startedAt) {
      if ('checking' !== item.state) return;
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
      /* Drop the id so the submission does not carry a refused photo. */
      item.id = null;
      releaseBlob(item);
      item.row.classList.remove('indeterminate');
      item.row.classList.remove('checking');
      item.row.classList.add('failed');
      var slot = item.row.querySelector('.uq-bar') || item.row.querySelector('.uq-note');
      if (slot) slot.outerHTML = '<span class="uq-err">' + esc(errorText(reason)) + '</span>';
      syncHidden();
    }

    function errorText(reason) {
      var errors = cfg.i18n && cfg.i18n.errors;
      return (errors && errors[reason]) || (errors && errors.unknown) || 'Upload failed.';
    }

    /* Where the photo is for. A page with no improve[lat]/[lng] pin (a route)
       passes options.pin, answering {lat, lng} or null. */
    var pinSource = options && options.pin;
    function pin(key) {
      if (pinSource) {
        var at = pinSource();
        var v = at && at[key];
        return v != null && isFinite(parseFloat(v)) ? String(parseFloat(v)) : null;
      }
      var field = document.querySelector('[name="improve[' + key + ']"]');
      var value = field && field.value;
      return value !== '' && value != null && isFinite(parseFloat(value)) ? String(parseFloat(value)) : null;
    }

    function upload(file, item) {
      /* Pin required (server: missing_location 422). */
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

          // Real bytes, not a spinner (docs/specs/photo-uploads.md §4).
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
              csrfToken = null;
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
      // Fail-closed: nothing uploads until a consent record exists.
      if (!consentId) { awaitingConsent = files; openConsentModal(); return; }
      for (var i = 0; i < files.length; i++) {
        if (items.length >= MAX) {
          var full = document.createElement('span');
          full.className = 'uq-row failed';
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

    /* Carry an edited description through to the review card's copy of it.
       Matched on the photo's uuid, because a caption is the only thing the two
       places share and a filename is not stable between them. */
    function syncExistingCaption(id, text, mine) {
      var fig = document.querySelector('.rm-item.is-existing[data-photo="' + id.replace(/"/g, '') + '"]');
      if (!fig) return;
      var cap = fig.querySelector('figcaption');
      if (cap) cap.textContent = text;
      var img = fig.querySelector('img');
      if (img && text) img.alt = text;
      fig.classList.toggle('has-alt', !!text);
      /* Somebody else's photo: the words are a proposal until a curator has
         seen them, and the caption says so rather than hiding them (owner
         2026-09-08: "missing my added alt text"). */
      fig.classList.toggle('is-proposed', !mine && !!text);
    }

    /* Fixing the description of a photograph that is ALREADY on the item.
       Rendered by the template only for the rider's own pictures, because
       `/media/photos/{id}/alt` is owner-only and would answer 404 to anybody
       else (owner, 2026-08-30: "it should be possible to change the description
       of an existing image").

       Same endpoint, same silence on success as a new upload's field: a green
       edge says saved, a clay edge says it did not, and either way what they
       typed stays in the box so the next change tries again. */
    Array.prototype.forEach.call(document.querySelectorAll('.cur-alt'), function (wrap) {
      var input = wrap.querySelector('input');
      var id = wrap.getAttribute('data-photo');
      if (!input || !id) return;

      /* The uploader's words take effect at once; anybody else's go to the
         queue. The server enforces the same split either way: the direct route
         refuses a non-owner and the suggestion route refuses the owner, so a
         tampered attribute changes nothing but which 404 you get. */
      var mine = wrap.getAttribute('data-mine') !== '0';
      var endpoint = cfg.uploadUrl + '/' + encodeURIComponent(id) + (mine ? '/alt' : '/alt-suggestion');

      input.addEventListener('change', function () {
        wrap.classList.remove('saved', 'failed');
        /* The review two steps on is server-rendered, so it would otherwise
           keep showing the description as it was when the page loaded (owner,
           2026-08-30). For anybody but the owner the caption is tagged as
           proposed: shown, since it is what they typed, but not claimed live. */
        syncExistingCaption(id, input.value, mine);
        ensureToken().then(function (tok) {
          var body = new FormData();
          body.append('_token', tok);
          body.append('alt', input.value);
          return fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
        }).then(function (r) {
          wrap.classList.toggle('saved', r.ok);
          wrap.classList.toggle('failed', !r.ok);
        }).catch(function () { wrap.classList.add('failed'); });
      });
    });

    /* `accept` lets a page hand over files it already holds (a Scout bundle's
       photos); they take the same consent gate as a picked file. */
    return { count: function () { return items.length; }, accept: accept };
  };
})();
