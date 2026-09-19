// SPDX-License-Identifier: AGPL-3.0-only
/* The curator room's composer (moderation-and-contribution.md §13.9).

   Two enhancements over the plain form, which keeps working without them:
   "About submission" becomes a search over the queue, and the pictures field
   becomes a drop zone that uploads at once with the bytes' own progress.

   A file, not an inline block, because the CSP blocks inline handlers. */

/* ---- About submission: type a number, a title or a region, pick a card ---- */
(function () {
  var q = document.getElementById('rm-about-q');
  var hidden = document.getElementById('rm-about');
  var list = document.getElementById('rm-about-list');
  var pick = document.getElementById('rm-about-pick');
  if (!q || !hidden || !list || !pick) return;

  var url = q.getAttribute('data-search-url');
  var tNone = q.getAttribute('data-none') || 'No submission matches';
  var tClear = q.getAttribute('data-clear') || 'Clear';
  var timer = 0;
  var seq = 0;
  var items = [];
  var active = -1;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function close() {
    list.hidden = true;
    list.innerHTML = '';
    items = [];
    active = -1;
    q.setAttribute('aria-expanded', 'false');
  }

  function line(s) {
    return 'SUB-' + s.id + ' · ' + s.title + (s.region ? ' · ' + s.region : '') + ' · ' + s.type + ' · ' + s.status;
  }

  function choose(s) {
    hidden.value = String(s.id);
    q.value = '';
    q.hidden = true;
    pick.hidden = false;
    pick.innerHTML = '<span>' + esc(line(s)) + '</span><button type="button" aria-label="' + esc(tClear) + '">×</button>';
    close();
  }

  function clear() {
    hidden.value = '';
    pick.hidden = true;
    pick.innerHTML = '';
    q.hidden = false;
    q.focus();
  }

  function setActive(i) {
    active = i;
    var rows = list.querySelectorAll('li[role=option]');
    for (var k = 0; k < rows.length; k++) rows[k].classList.toggle('on', k === i);
    if (rows[i]) q.setAttribute('aria-activedescendant', rows[i].id);
  }

  function render(found) {
    items = found;
    list.innerHTML = '';
    if (!found.length) {
      list.innerHTML = '<li class="rm-none">' + esc(tNone) + '</li>';
    }
    found.forEach(function (s, i) {
      var li = document.createElement('li');
      li.id = 'rm-about-opt-' + i;
      li.setAttribute('role', 'option');
      li.textContent = line(s);
      li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(s); });
      li.addEventListener('mousemove', function () { setActive(i); });
      list.appendChild(li);
    });
    list.hidden = false;
    q.setAttribute('aria-expanded', 'true');
    setActive(found.length ? 0 : -1);
  }

  function search() {
    var text = q.value.trim();
    if (!text) { close(); return; }
    var mine = ++seq;
    fetch(url + '?q=' + encodeURIComponent(text), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (found) { if (mine === seq) render(Array.isArray(found) ? found : []); })
      .catch(function () { if (mine === seq) close(); });
  }

  q.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(search, 220);
  });
  q.addEventListener('keydown', function (e) {
    if (list.hidden) return;
    if ('ArrowDown' === e.key) { e.preventDefault(); setActive(Math.min(items.length - 1, active + 1)); }
    else if ('ArrowUp' === e.key) { e.preventDefault(); setActive(Math.max(0, active - 1)); }
    else if ('Enter' === e.key) { e.preventDefault(); if (items[active]) choose(items[active]); }
    else if ('Escape' === e.key) { close(); }
  });
  q.addEventListener('blur', function () { setTimeout(close, 120); });
  pick.addEventListener('click', function (e) { if (e.target.closest('button')) clear(); });
  /* Edit mode: the page arrives with a pick already showing; nothing to do. */

  /* A number typed by hand still works: it is read as the id on the server. */
  q.setAttribute('role', 'combobox');
  q.setAttribute('aria-autocomplete', 'list');
  q.setAttribute('aria-controls', list.id);
  q.setAttribute('aria-expanded', 'false');
})();

/* ---- Pictures: drop, upload at once, real progress, then "Checking…" ----
   Each finished upload puts its id in #rm-images; the post claims them. */
(function () {
  var box = document.getElementById('rm-pics');
  var input = document.getElementById('rm-image');
  var drop = document.getElementById('rm-drop');
  var queue = document.getElementById('rm-queue');
  var ids = document.getElementById('rm-images');
  if (!box || !input || !drop || !queue || !ids) return;

  var uploadUrl = box.getAttribute('data-upload-url');
  var removeUrl = box.getAttribute('data-remove-url');
  var stamp = box.getAttribute('data-token');
  var max = parseInt(box.getAttribute('data-max') || '4', 10);
  var maxBytes = parseInt(box.getAttribute('data-max-bytes') || '0', 10);
  var t = function (k) { return box.getAttribute('data-t-' + k) || k; };
  var items = [];

  function sync() {
    var done = items.filter(function (i) { return i.id; }).map(function (i) { return i.id; });
    ids.value = done.length ? JSON.stringify(done) : '';
  }

  function chip(file) {
    var el = document.createElement('div');
    el.className = 'rm-up';
    var img = document.createElement('img');
    img.alt = '';
    var url = URL.createObjectURL(file);
    img.src = url;
    var bar = document.createElement('div');
    bar.className = 'rm-bar';
    bar.innerHTML = '<i></i>';
    var state = document.createElement('span');
    state.className = 'rm-state';
    state.textContent = '0%';
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'rm-x';
    x.textContent = '×';
    x.title = t('remove');
    x.setAttribute('aria-label', t('remove'));
    el.append(img, bar, state, x);
    queue.appendChild(el);
    var item = { el: el, bar: bar.firstChild, state: state, id: null, xhr: null, url: url };
    x.addEventListener('click', function () { remove(item); });
    items.push(item);
    return item;
  }

  function fail(item, text) {
    item.el.classList.add('failed');
    item.el.classList.remove('done');
    item.state.textContent = text;
    item.bar.style.width = '0';
  }

  function remove(item) {
    if (item.xhr) item.xhr.abort();
    if (item.id) {
      var body = new FormData();
      body.append('_token', stamp);
      fetch(removeUrl.replace(/0(\/remove)$/, item.id + '$1'), {
        method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).catch(function () {});
    }
    URL.revokeObjectURL(item.url);
    items = items.filter(function (i) { return i !== item; });
    item.el.remove();
    sync();
  }

  function upload(file) {
    var item = chip(file);
    if (maxBytes && file.size > maxBytes) { fail(item, t('too-big')); return; }
    var form = new FormData();
    form.append('image', file);
    form.append('_token', stamp);
    var xhr = new XMLHttpRequest();
    item.xhr = xhr;
    xhr.open('POST', uploadUrl, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    /* Real bytes on the wire, then the server's own work as "Checking…". */
    xhr.upload.onprogress = function (e) {
      if (!e.lengthComputable) return;
      var pct = Math.round(e.loaded * 100 / e.total);
      item.bar.style.width = pct + '%';
      item.state.textContent = pct < 100 ? pct + '%' : t('checking');
    };
    xhr.onload = function () {
      item.xhr = null;
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
      if (xhr.status === 201 && data && data.id) {
        item.id = data.id;
        item.el.classList.add('done');
        item.bar.style.width = '100%';
        item.state.textContent = Math.round(data.bytes / 1024) + ' kB';
        sync();
        return;
      }
      fail(item, (data && data.error) || t('failed'));
    };
    xhr.onerror = function () { item.xhr = null; fail(item, t('failed')); };
    xhr.send(form);
  }

  function accept(list) {
    var files = Array.prototype.slice.call(list || []);
    for (var i = 0; i < files.length; i++) {
      if (items.length >= max) {
        fail(chip(files[i]), t('too-many'));
        continue;
      }
      upload(files[i]);
    }
  }

  /* The script is here: the drop zone takes over, the file input serves the
     chooser only, and the form never carries the files themselves. */
  drop.hidden = false;
  input.hidden = true;
  input.removeAttribute('name');
  drop.addEventListener('click', function () { input.click(); });
  drop.addEventListener('keydown', function (e) { if ('Enter' === e.key || ' ' === e.key) { e.preventDefault(); input.click(); } });
  input.addEventListener('change', function () { accept(input.files); input.value = ''; });
  ['dragenter', 'dragover'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) accept(e.dataTransfer.files); });
  /* A screenshot pasted into the post goes the same way. */
  var body = document.getElementById('rm-body');
  if (body) body.addEventListener('paste', function (e) {
    var files = [];
    var list = e.clipboardData && e.clipboardData.items;
    for (var i = 0; list && i < list.length; i++) {
      if (list[i].kind === 'file') { var f = list[i].getAsFile(); if (f) files.push(f); }
    }
    if (files.length) { e.preventDefault(); accept(files); }
  });
})();

/* ---- Lightbox: a post's pictures large, prev/next when there are more ---- */
(function () {
  var lb = document.getElementById('rm-lb');
  if (!lb) return;
  var img = lb.querySelector('img');
  var prev = lb.querySelector('[data-lb=prev]');
  var next = lb.querySelector('[data-lb=next]');
  var count = lb.querySelector('.rm-lb-n');
  var set = [];
  var at = 0;
  var opener = null;

  function show(i) {
    at = (i + set.length) % set.length;
    var a = set[at];
    img.src = a.getAttribute('href');
    img.alt = a.querySelector('img') ? a.querySelector('img').alt : '';
    prev.hidden = next.hidden = set.length < 2;
    count.textContent = set.length > 1 ? (at + 1) + ' / ' + set.length : '';
  }
  function open(group, i, from) {
    set = Array.prototype.slice.call(group.querySelectorAll('a[href]'));
    opener = from;
    lb.hidden = false;
    show(i);
    lb.querySelector('[data-lb=close]').focus();
  }
  function close() {
    lb.hidden = true;
    img.src = '';
    if (opener) opener.focus();
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-lightbox] a[href]');
    if (a && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
      e.preventDefault();
      var group = a.closest('[data-lightbox]');
      open(group, Array.prototype.indexOf.call(group.querySelectorAll('a[href]'), a), a);
      return;
    }
    if (lb.hidden) return;
    var b = e.target.closest('[data-lb]');
    if (b) {
      var act = b.getAttribute('data-lb');
      if ('close' === act) close(); else if ('prev' === act) show(at - 1); else show(at + 1);
    } else if (e.target === lb) close();
  });
  document.addEventListener('keydown', function (e) {
    if (lb.hidden) return;
    if ('Escape' === e.key) close();
    else if ('ArrowLeft' === e.key && set.length > 1) show(at - 1);
    else if ('ArrowRight' === e.key && set.length > 1) show(at + 1);
    else return;
    e.preventDefault();
  });
})();

/* ---- Delete asks first: the whole post goes, pictures included ---- */
document.addEventListener('submit', function (e) {
  var f = e.target.closest('form.rm-del');
  if (f && f.dataset.confirm && !window.confirm(f.dataset.confirm)) e.preventDefault();
});
