// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
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
      syncReviewNotice();
      onChange(items.map(function (i) { return { name: i.name, sm: i.sm || null }; }));
    }

    function addRow(name) {
      var row = document.createElement('span');
      row.className = 'chip up';
      row.innerHTML =
        '<span class="chip-thumb"></span>' +
        '<span class="chip-name">' + esc(name) + '</span>' +
        '<span class="chip-bar"><i style="width:0%"></i></span>' +
        '<button type="button" class="chip-x" aria-label="' + esc(t('remove', 'Remove')) + '">×</button>' +
        /* Hidden until the upload has an id to attach a description to. The
           prompt asks what somebody who cannot see it needs to know, rather
           than saying "alt text", which means nothing to a rider. */
        '<label class="chip-alt" hidden><span class="vh">' + esc(t('altLabel', 'Describe this photo')) + '</span>' +
        '<input type="text" maxlength="300" placeholder="' + esc(t('altPlaceholder', 'What would somebody who cannot see it need to know?')) + '" /></label>';
      queueEl.appendChild(row);
      var item = { id: null, name: name, row: row, bar: row.querySelector('.chip-bar i'), state: 'uploading' };
      row.querySelector('.chip-x').addEventListener('click', function () { removeItem(item); });

      var altInput = row.querySelector('.chip-alt input');
      altInput.addEventListener('change', function () {
        /* No id yet means the upload has not landed; the field is hidden then,
           so this is belt and braces rather than a real path. */
        if (!item.id) return;
        var body = new FormData();
        body.append('_token', token);
        body.append('alt', altInput.value);
        fetch(cfg.uploadUrl + '/' + encodeURIComponent(item.id) + '/alt', {
          method: 'POST', body: body, credentials: 'same-origin'
        }).then(function (r) {
          /* Silent on success. A failure must not eat what they typed, so the
             field keeps its value and the next change tries again. */
          row.querySelector('.chip-alt').classList.toggle('saved', r.ok);
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
      var altWrap = item.row.querySelector('.chip-alt');
      if (altWrap) altWrap.hidden = false;
      item.state = 'checking';
      item.row.classList.remove('indeterminate');
      item.row.classList.add('checking');
      setThumb(item, localPreview(item, file), item.name);
      var bar = item.row.querySelector('.chip-bar');
      if (bar) bar.outerHTML = '<span class="chip-note">' + esc(t('checking', 'Checking…')) + '</span>';
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

    /* Stop waiting, keep the id — the photo still travels with the submission. */
    function stopWaiting(item) {
      item.state = 'waiting';
      item.row.classList.remove('checking');
      item.row.classList.add('waiting');
      var note = item.row.querySelector('.chip-note');
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
      var slot = item.row.querySelector('.chip-bar') || item.row.querySelector('.chip-note');
      if (slot) slot.outerHTML = '<span class="chip-err">' + esc(errorText(reason)) + '</span>';
      syncHidden();
    }

    function errorText(reason) {
      var errors = cfg.i18n && cfg.i18n.errors;
      return (errors && errors[reason]) || (errors && errors.unknown) || 'Upload failed.';
    }

    function pin(key) {
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
