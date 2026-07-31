// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The wizard's real photo uploads (docs/specs/photo-uploads.md §4).
   Classic script, mounted by improve.js through window.Cc — the same idiom
   climb-editor.js uses, because the contribute templates load plain scripts,
   not modules.

   Consent is fail-closed here as everywhere: consentId starts null, is set
   ONLY from a server acknowledgement, and the file input plus drop zone stay
   disabled until it is. A failed consent POST keeps them disabled and shows
   the error in the modal. Nothing is remembered in localStorage — the source
   of truth is the server's consent ledger, re-read on every visit. */
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  window.Cc.mountMediaUploads = function (options) {
    var cfg = window.CC_MEDIA || {};
    var T = cfg.i18n || {};
    var MAX = cfg.max || 6;

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

    function fmtDate(iso) {
      if (!iso) return '';
      var d = new Date(iso);
      if (isNaN(d.getTime())) return '';
      try {
        return d.toLocaleDateString(document.documentElement.lang || undefined,
          { year: 'numeric', month: 'long', day: 'numeric' });
      } catch (e) { return iso.slice(0, 10); }
    }

    /* The standing notice (§4): once consent exists it is shown on every later
       visit, on the photo step AND beside the queued photos on review. */
    function renderConsent(consentedAt) {
      var terms = cfg.termsUrl
        ? ' <a href="' + esc(cfg.termsUrl) + '">' + esc(t('siteTerms', 'Site terms')) + '</a>'
        : '';
      var html = '<div class="consent-ok">✓ ' + esc(t('standing', '')) +
        ' <span class="consent-when">' + esc(fmtDate(consentedAt)) + '</span>' +
        ' <details class="consent-more"><summary>' + esc(t('readContract', 'Read the contract')) +
        '</summary><p>' + esc(t('contract', '')) + '</p></details>' + terms + '</div>';
      if (noticeEl) noticeEl.innerHTML = html;
      if (reviewNoticeEl) reviewNoticeEl.innerHTML = html;
    }

    /* Before consent exists there is nothing to show here. The rider drops a
       photo; the contract is put to them at that moment, about that photo,
       which is when it means something — rather than as a toll gate in front
       of a drop zone they cannot use yet. The licence terms are stated in
       full on this step regardless (the notice below the drop zone), so
       nothing is sprung on anyone. */
    function renderConsentPrompt() {
      if (noticeEl) noticeEl.innerHTML = '';
      if (reviewNoticeEl) reviewNoticeEl.innerHTML = '';
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

    function syncHidden() {
      var ids = items.filter(function (i) { return i.id; }).map(function (i) { return i.id; });
      hidden.value = ids.length ? JSON.stringify(ids) : '';
      onChange(items.map(function (i) { return i.name; }));
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
      return item;
    }

    function removeItem(item) {
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

    function succeed(item, data) {
      item.id = data.id;
      item.state = 'done';
      item.row.classList.remove('indeterminate');
      item.row.classList.add('done');
      var thumb = item.row.querySelector('.chip-thumb');
      if (thumb) {
        var img = document.createElement('img');
        img.src = data.sm;
        img.alt = item.name;
        img.loading = 'lazy';
        thumb.appendChild(img);
      }
      var bar = item.row.querySelector('.chip-bar');
      if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
      syncHidden();
    }

    function fail(item, reason) {
      item.state = 'error';
      item.row.classList.remove('indeterminate');
      item.row.classList.add('failed');
      var bar = item.row.querySelector('.chip-bar');
      if (bar) bar.outerHTML = '<span class="chip-err">' + esc(errorText(reason)) + '</span>';
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
      return ensureToken().then(function (token) {
        return new Promise(function (resolve) {
          var form = new FormData();
          form.append('photo', file);
          form.append('_token', token);
          form.append('consentId', consentId);
          var lat = pin('lat');
          var lng = pin('lng');
          if (lat && lng) { form.append('lat', lat); form.append('lng', lng); }

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
              succeed(item, payload);
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
