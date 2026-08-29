// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The floating bug button and its panel.
   docs/specs/contact-and-support.md §5

   Behaviour worth knowing before changing anything here:

   * The panel NEVER navigates. A rider who has just hit a bug is usually
     halfway through something; taking them to another page to describe it
     loses the state that caused it, and often loses the rider. The whole
     exchange is one fetch.

   * The draft survives leaving the page, PICTURES INCLUDED, so somebody can
     write half a report, go and check the thing they are describing, and come
     back to it. Pasted screenshots are the part that matters here: they are the
     most annoying thing to redo, and the reason the draft lives in IndexedDB
     rather than localStorage, whose ~5 MB budget three screenshots would blow.
     Words fall back to localStorage where IndexedDB is unavailable. The panel
     reopens by itself when it finds a draft, so the work is not merely kept but
     visibly kept. Cleared on a successful send.

   * The context (page, browser, viewport) is shown in an open-able block before
     the send, not just claimed in a sentence. It is the one place this form
     collects something the reporter did not type, so it is the one place that
     has to be visible.

   * The proof of work is solved as soon as the panel is opened, in the
     background, using window.ccPow. By the time anybody has typed a title it is
     done, and submit never waits.

   * With JavaScript off, none of this exists and the footer link to
     /report-bug is the way in. The button element itself is `hidden` in the
     markup and unhidden here, so nothing broken is ever shown.
*/
(function () {
  'use strict';

  var root = document.getElementById('cc-bugfab');
  if (!root || !window.ccPow) return;

  var DRAFT_KEY = 'cc-bug-draft';
  var MAX_SHOTS = parseInt(root.dataset.maxShots, 10) || 3;

  var panel = root.querySelector('#cc-bugpanel');
  var openBtn = root.querySelector('.bugfab-open');
  var body = root.querySelector('.bugfab-body');
  var done = root.querySelector('.bugfab-done');
  var msg = root.querySelector('.bugfab-msg');
  var status = root.querySelector('.bugfab-status');
  var thumbs = root.querySelector('.bugfab-thumbs');

  var fTitle = root.querySelector('#bf-title');
  var fBody = root.querySelector('#bf-body');
  var fSteps = root.querySelector('#bf-steps');
  var fArea = root.querySelector('#bf-area');
  var fEmail = root.querySelector('#bf-email');
  var sendBtn = root.querySelector('.bugfab-send');
  var againBtn = root.querySelector('.bugfab-again');

  var severity = 'minor';
  var shots = [];
  var nonce = null;
  var solving = null;

  /* --- context ---------------------------------------------------------- */

  function viewport() {
    return window.innerWidth + 'x' + window.innerHeight +
      (window.devicePixelRatio && window.devicePixelRatio !== 1 ? ' @' + window.devicePixelRatio : '');
  }

  function browser() {
    /* The user-agent string, trimmed. Not a fingerprint we build: it is the
       header the browser already sends on every request, kept alongside the
       report so a curator can tell "Safari on iOS" from "Firefox on Linux". */
    return (navigator.userAgent || '').slice(0, 300);
  }

  function pagePath() {
    return root.dataset.pagePath || location.pathname;
  }

  function fillContext() {
    var page = root.querySelector('.bf-ctx-page');
    var br = root.querySelector('.bf-ctx-browser');
    var vp = root.querySelector('.bf-ctx-viewport');
    if (page) page.textContent = pagePath();
    if (br) br.textContent = browser();
    if (vp) vp.textContent = viewport();
  }

  /* --- draft ------------------------------------------------------------ */

  /* One IndexedDB store, one row. Screenshots are data URLs and go in it too,
     which localStorage could not hold. Every call resolves rather than rejects:
     a browser in private mode, or with storage blocked, must still get a
     working form. */
  var DB_NAME = 'cc-support';
  var DB_STORE = 'draft';

  function idb() {
    return new Promise(function (resolve) {
      if (!window.indexedDB) { resolve(null); return; }
      var req;
      try { req = indexedDB.open(DB_NAME, 1); } catch (e) { resolve(null); return; }
      req.onupgradeneeded = function () {
        if (!req.result.objectStoreNames.contains(DB_STORE)) req.result.createObjectStore(DB_STORE);
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { resolve(null); };
      req.onblocked = function () { resolve(null); };
    });
  }

  function idbPut(value) {
    return idb().then(function (db) {
      if (!db) return false;
      return new Promise(function (resolve) {
        try {
          var tx = db.transaction(DB_STORE, 'readwrite');
          tx.objectStore(DB_STORE).put(value, DRAFT_KEY);
          tx.oncomplete = function () { resolve(true); };
          tx.onerror = function () { resolve(false); };
          tx.onabort = function () { resolve(false); };
        } catch (e) { resolve(false); }
      });
    });
  }

  function idbGet() {
    return idb().then(function (db) {
      if (!db) return null;
      return new Promise(function (resolve) {
        try {
          var req = db.transaction(DB_STORE, 'readonly').objectStore(DB_STORE).get(DRAFT_KEY);
          req.onsuccess = function () { resolve(req.result || null); };
          req.onerror = function () { resolve(null); };
        } catch (e) { resolve(null); }
      });
    });
  }

  function idbDelete() {
    return idb().then(function (db) {
      if (!db) return;
      try { db.transaction(DB_STORE, 'readwrite').objectStore(DB_STORE).delete(DRAFT_KEY); } catch (e) { /* gone anyway */ }
    });
  }

  function currentDraft() {
    return {
      title: fTitle.value,
      body: fBody.value,
      steps: fSteps ? fSteps.value : '',
      area: fArea.value,
      severity: severity,
      shots: shots.slice()
    };
  }

  function isEmpty(d) {
    return !d.title.trim() && !d.body.trim() && !d.steps.trim() && !d.shots.length;
  }

  function saveDraft() {
    var draft = currentDraft();
    if (isEmpty(draft)) { clearDraft(); return; }

    idbPut(draft);
    /* Words also go to localStorage, so they survive even where IndexedDB is
       refused. Pictures never do: they would blow the quota and take the words
       down with them. */
    try {
      var light = { title: draft.title, body: draft.body, steps: draft.steps, area: draft.area, severity: draft.severity };
      localStorage.setItem(DRAFT_KEY, JSON.stringify(light));
    } catch (e) { /* private mode, or full. IndexedDB may still have it. */ }
  }

  function loadDraft() {
    return idbGet().then(function (fromDb) {
      if (fromDb) return fromDb;
      try {
        var raw = localStorage.getItem(DRAFT_KEY);
        return raw ? JSON.parse(raw) : null;
      } catch (e) { return null; }
    });
  }

  function clearDraft() {
    idbDelete();
    try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* nothing to do */ }
  }

  function applyDraft(draft) {
    if (!draft) return false;
    if (draft.title) fTitle.value = draft.title;
    if (draft.body) fBody.value = draft.body;
    if (draft.steps && fSteps) fSteps.value = draft.steps;
    if (draft.area) fArea.value = draft.area;
    if (draft.severity) setSeverity(draft.severity);
    if (draft.shots && draft.shots.length) {
      shots = draft.shots.slice(0, MAX_SHOTS);
      renderShots();
    }
    return !isEmpty(currentDraft());
  }

  /* --- small helpers ---------------------------------------------------- */

  function say(key) {
    if (!status) return;
    status.textContent = status.dataset[key] || '';
    status.hidden = !status.textContent;
  }

  function warn(text) {
    if (!msg) return;
    msg.textContent = text || '';
    msg.hidden = !text;
  }

  function t(key, fallback) {
    return (window.ccT ? window.ccT(key, fallback) : fallback);
  }

  function setSeverity(value) {
    severity = value;
    root.querySelectorAll('.bugfab-sev button').forEach(function (b) {
      var on = b.dataset.value === value;
      b.classList.toggle('on', on);
      b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
  }

  /* Steps used to hide behind a "+ add steps" link. They are always shown now,
     marked optional, so the panel and /report-bug offer the same fields
     (owner 2026-08-27). Kept as a function because reset() still calls it. */
  function clearSteps() {
    if (fSteps) fSteps.value = '';
  }

  /* --- proof of work ---------------------------------------------------- */

  function startSolving() {
    if (solving || nonce) return;
    if (!window.ccPow.available()) { say('failed'); return; }
    say('working');
    solving = window.ccPow
      .solve(root.dataset.powChallenge, root.dataset.powDifficulty)
      .then(function (value) {
        nonce = value;
        say(value ? 'ready' : 'failed');
      })
      .catch(function () { say('failed'); });
  }

  /* --- open / close ----------------------------------------------------- */

  function open() {
    panel.hidden = false;
    panel.classList.remove('is-min');
    openBtn.setAttribute('aria-expanded', 'true');
    fillContext();
    startSolving();
    fTitle.focus();
  }

  function close() {
    panel.hidden = true;
    openBtn.setAttribute('aria-expanded', 'false');
    openBtn.focus();
  }

  openBtn.addEventListener('click', function () {
    if (panel.hidden) { open(); } else { close(); }
  });

  root.querySelector('.bugfab-close').addEventListener('click', close);
  root.querySelector('.bugfab-min').addEventListener('click', function () {
    panel.classList.toggle('is-min');
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) close();
  });

  root.querySelectorAll('.bugfab-sev button').forEach(function (b) {
    b.addEventListener('click', function () { setSeverity(b.dataset.value); saveDraft(); });
  });

  [fTitle, fBody, fSteps, fArea].forEach(function (el) {
    if (el) el.addEventListener('input', saveDraft);
  });

  /* --- drag ------------------------------------------------------------- */

  var head = root.querySelector('.bugfab-head');
  head.addEventListener('mousedown', function (e) {
    /* Buttons in the header are not a drag handle. */
    if (e.target.closest('button')) return;
    e.preventDefault();
    var rect = panel.getBoundingClientRect();
    var startX = e.clientX, startY = e.clientY;

    function move(ev) {
      var x = Math.max(4, Math.min(window.innerWidth - rect.width - 4, rect.left + ev.clientX - startX));
      var y = Math.max(4, Math.min(window.innerHeight - 40, rect.top + ev.clientY - startY));
      panel.style.left = x + 'px';
      panel.style.top = y + 'px';
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';
    }
    function up() {
      document.removeEventListener('mousemove', move);
      document.removeEventListener('mouseup', up);
    }
    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', up);
  });

  /* --- screenshots ------------------------------------------------------ */

  function addShot(dataUrl) {
    if (shots.length >= MAX_SHOTS) {
      warn(t('bug_shot_max', 'That is as many pictures as one report takes.'));
      return;
    }
    shots.push(dataUrl);
    renderShots();
    saveDraft();
  }

  function renderShots() {
    thumbs.textContent = '';
    shots.forEach(function (src, i) {
      var wrap = document.createElement('div');
      wrap.className = 'bugfab-thumb';
      var img = document.createElement('img');
      img.src = src;
      img.alt = '';
      var del = document.createElement('button');
      del.type = 'button';
      del.textContent = '×';
      del.setAttribute('aria-label', t('bug_shot_remove', 'Remove this picture'));
      del.addEventListener('click', function () { shots.splice(i, 1); renderShots(); saveDraft(); });
      wrap.appendChild(img);
      wrap.appendChild(del);
      thumbs.appendChild(wrap);
    });
  }

  /* Ctrl+V anywhere in the panel. The clipboard is the only way most people
     have of getting a screenshot out of their own screen and into a form. */
  panel.addEventListener('paste', function (e) {
    var items = (e.clipboardData && e.clipboardData.items) || [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].type.indexOf('image/') !== 0) continue;
      var file = items[i].getAsFile();
      if (!file) continue;
      e.preventDefault();
      var reader = new FileReader();
      reader.onload = function () { addShot(String(reader.result)); };
      reader.readAsDataURL(file);
    }
  });

  /* --- send ------------------------------------------------------------- */

  function reset() {
    fTitle.value = '';
    fBody.value = '';
    clearSteps();
    shots = [];
    renderShots();
    setSeverity('minor');
    warn('');
    nonce = null;
    solving = null;
    body.hidden = false;
    done.hidden = true;
    startSolving();
  }

  if (againBtn) againBtn.addEventListener('click', reset);

  sendBtn.addEventListener('click', function () {
    warn('');

    if (!fTitle.value.trim()) {
      warn(t('bug_need_title', 'Give it a short title first.'));
      fTitle.focus();
      return;
    }
    if (!fBody.value.trim()) {
      warn(t('bug_need_body', 'Say what happened.'));
      fBody.focus();
      return;
    }
    /* Required without an account, same as /report-bug. The field only exists
       for anonymous visitors, so its absence IS the signed-in case. */
    if (fEmail && !fEmail.value.trim()) {
      warn(t('bug_need_email', 'We need an address to tell you what happened.'));
      fEmail.focus();
      return;
    }

    sendBtn.disabled = true;
    startSolving();

    Promise.resolve(solving).then(function () {
      if (!nonce) {
        sendBtn.disabled = false;
        warn(t('bug_no_pow', 'This browser cannot finish the spam check. Use the contact page instead.'));
        return;
      }

      var data = new FormData();
      data.append('_token', root.dataset.token);
      data.append(root.dataset.stampField, root.dataset.stamp);
      data.append('pow_challenge', root.dataset.powChallenge);
      data.append('pow_nonce', nonce);
      data.append('title', fTitle.value.trim());
      data.append('body', fBody.value.trim());
      data.append('steps', fSteps ? fSteps.value.trim() : '');
      data.append('severity', severity);
      data.append('area', fArea.value);
      data.append('page_url', pagePath());
      data.append('browser', browser());
      data.append('viewport', viewport());
      /* CC_VERSION is an OBJECT ({number, date}), set by base.html.twig, not
         a string. Sending it whole stored the literal "[object Object]" as the
         build, which is exactly the field meant to tell a curator which build
         the reporter was looking at. */
      data.append('app_version', (window.CC_VERSION && window.CC_VERSION.number) || '');
      if (fEmail) data.append('email', fEmail.value.trim());
      shots.forEach(function (s) { data.append('screenshots[]', s); });

      return fetch(root.dataset.endpoint, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        body: data
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false }; });
      }).then(function (out) {
        sendBtn.disabled = false;
        if (!out || !out.ok) {
          /* The server sends a translation key. It is not in the site-wide
             CC_I18N set, so the generic sentence is the honest fallback and
             the key is never shown raw to a rider. */
          warn(t('bug_send_failed', 'That did not send. Try again, or use the contact page.'));
          /* The challenge is spent either way; a retry needs a fresh one. */
          nonce = null;
          solving = null;
          return;
        }
        clearDraft();
        body.hidden = true;
        done.hidden = false;
      });
    }).catch(function () {
      sendBtn.disabled = false;
      warn(t('bug_send_failed', 'That did not send. Try again, or use the contact page.'));
    });
  });

  /* --- go --------------------------------------------------------------- */

  root.hidden = false;
  loadDraft().then(function (draft) {
    /* Opening on its own is the point: a draft that is kept but invisible is
       one the writer assumes they lost. */
    if (applyDraft(draft)) open();
  });
})();
