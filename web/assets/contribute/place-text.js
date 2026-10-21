// SPDX-License-Identifier: AGPL-3.0-only
/* "Edit this text": the current text of the chosen language shows read-only
   above an empty box (docs/specs/moderation-and-contribution.md §3.1b).

   Picking another language swaps that quote, or says plainly there is no
   text in that language yet. The box keeps whatever the rider wrote. Under
   the box a polite live count says how many of the allowed characters are
   used, counted as the server counts them (code points); the server enforces
   the limit either way. "Where your text comes from" follows the language
   too: shown where there is an article to adapt, hidden and disabled where
   there is none, so its required radios never block the send; the server
   checks the answer again either way.

   A region's "History, nature and culture" text (data-story, §3.1c) works
   the other way round: its box starts from the chosen language's current
   text. Picking another language swaps it, but only while the rider has
   not changed it, so their writing is never lost. For a language without
   a text, the text of another language (English first) shows above the
   box to translate from.

   A file rather than an inline block, so the page carries no CSP nonce. */
(function () {
  var form = document.getElementById('place-text-form');
  var select = document.getElementById('pt-lang');
  var box = document.getElementById('pt-text');
  if (!form || !select || !box) { return; }
  var texts = {};
  var articles = null;
  try { texts = JSON.parse(form.getAttribute('data-texts') || '{}'); } catch (e) { texts = {}; }
  try { articles = form.hasAttribute('data-articles') ? JSON.parse(form.getAttribute('data-articles')) : null; } catch (e) { articles = null; }
  var source = document.getElementById('pt-source');
  var quote = document.getElementById('pt-current-text');
  var none = document.getElementById('pt-current-none');
  var count = document.getElementById('pt-text-count');
  var story = form.hasAttribute('data-story');
  var names = {};
  try { names = JSON.parse(form.getAttribute('data-langs') || '{}'); } catch (e) { names = {}; }
  var translate = document.getElementById('pt-translate');
  var translateH = document.getElementById('pt-translate-h');
  var translateText = document.getElementById('pt-translate-text');

  function textOf(lang) { return typeof texts[lang] === 'string' ? texts[lang] : ''; }

  /* The language a text without one is translated from: English first. */
  function fromFor(lang) {
    if (textOf(lang) !== '') { return null; }
    var order = ['en'].concat(Object.keys(texts));
    for (var i = 0; i < order.length; i++) {
      if (order[i] !== lang && textOf(order[i]) !== '') { return order[i]; }
    }
    return null;
  }

  /* What the box starts from for a language, and what it is translated from. */
  function startOf(lang) {
    return { text: textOf(lang), from: fromFor(lang) };
  }

  function showTranslate(from) {
    if (!translate || !translateH || !translateText) { return; }
    translate.hidden = from === null;
    translateText.textContent = from === null ? '' : textOf(from);
    translateH.textContent = from === null ? '' : (translateH.getAttribute('data-words') || '%lang%')
      .replace('%lang%', typeof names[from] === 'string' ? names[from] : from);
  }

  var start = startOf(select.value);

  function showCurrent(lang) {
    if (!quote || !none) { return; }
    var t = typeof texts[lang] === 'string' ? texts[lang] : '';
    quote.textContent = t;
    quote.hidden = t === '';
    none.hidden = t !== '';
  }

  /* A history text counts what a reader sees: a [words](address) link
     counts its words, and paragraphs are one line apart as stored
     (RegionStory::readableLength). */
  function readable(text) {
    var lines = text.split(/\r\n|\r|\n/).map(function (l) { return l.replace(/\s+/g, ' ').trim(); })
      .filter(function (l) { return l !== ''; });
    var n = 0;
    lines.forEach(function (l) {
      n += Array.from(l.replace(/\[([^\[\]\n]{1,300})\]\((https?:\/\/[^\s()<>"]+)\)/gi, function (m, w) { return w.trim() === '' ? m : w.trim(); })).length;
    });
    return n + 2 * Math.max(0, lines.length - 1);
  }

  function recount() {
    if (!count) { return; }
    var max = parseInt(count.getAttribute('data-max'), 10) || 0;
    var n = box.hasAttribute('data-links') ? readable(box.value) : Array.from(box.value).length;
    count.textContent = (count.getAttribute('data-words') || '%count% / %max%')
      .replace('%count%', String(n)).replace('%max%', String(max));
    count.classList.toggle('is-over', max > 0 && n > max);
  }

  select.addEventListener('change', function () {
    var lang = select.value;
    if (story) {
      var next = startOf(lang);
      if (box.value === start.text) {
        box.value = next.text;
        /* The link editor below redraws on input. */
        box.dispatchEvent(new Event('input'));
      }
      start = next;
      showTranslate(next.from);
      recount();
      return;
    }
    showCurrent(lang);
    if (articles && source) {
      var asked = articles[lang] === true;
      source.hidden = !asked;
      source.disabled = !asked;
    }
  });
  box.addEventListener('input', recount);
  recount();
})();

/* The link editor of a history text (textarea[data-links], §3.1c). A text
   cites its sources as [words](https://address); this makes those easy to
   place and to see without changing what is sent:

   - "Link the selected words": select words in the box, press it, give the
     address, and the words become [words](address). The address must be a
     full http(s) address; the box keeps its undo history where the browser
     allows (execCommand), else the value is set directly.
   - Colours behind the box's own text: a layer under the transparent
     textarea draws the same text invisibly, with a tint behind each link's
     words and a quieter one behind its address, red where the words are
     ones the server refuses ("source", "here"). Only backgrounds: the text
     the rider reads and edits is the textarea's own, so a small mismatch in
     the layer can only shift a tint, never garble the text.
   - Under the box, the links in order: the words, then where they go, with
     a warning on a refused one.
   - Each link is checked as it appears, a paste or a typed link alike:
     the addresses not asked before go to story_link_check (debounced, at
     most 20 a time), which answers with the verdicts a send would meet.
     The list shows each link's state, and the box tints a refused address
     red. A check that cannot run (no answer, the hourly limit) says so and
     changes nothing: the send checks again either way.
   - When a link is not plainly fine, the tick under the list appears: the
     writer confirms they opened it. The send needs it; nothing else does. */
(function () {
  var box = document.querySelector('textarea[data-links]');
  if (!box) { return; }
  var LINK = /\[([^\[\]\n]{1,300})\]\((https?:\/\/[^\s()<>"]+)\)/gi;
  var generic = [];
  try { generic = JSON.parse(box.getAttribute('data-generic') || '[]'); } catch (e) { generic = []; }

  var bar = document.getElementById('pt-linkbar');
  var open = document.getElementById('pt-link-open');
  var panel = document.getElementById('pt-link-form');
  var url = document.getElementById('pt-link-url');
  var insert = document.getElementById('pt-link-insert');
  var cancel = document.getElementById('pt-link-cancel');
  var msg = document.getElementById('pt-link-msg');
  var list = document.getElementById('pt-links');
  var listH = document.getElementById('pt-links-h');
  var listOl = document.getElementById('pt-links-list');
  var confirmBox = document.getElementById('pt-links-ok');

  function isGeneric(words) {
    var w = words.replace(/[\s.:;,!?()"']+/g, ' ').trim().toLowerCase();
    return generic.indexOf(w) !== -1;
  }
  function esc(t) {
    return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function say(text) { if (msg) { msg.textContent = text || ''; } }

  /* The layer under the box. */
  var wrap = box.parentNode;
  var layer = document.createElement('div');
  layer.className = 'pt-hl';
  layer.setAttribute('aria-hidden', 'true');
  wrap.insertBefore(layer, box);
  wrap.classList.add('is-on');

  function fitLayer() {
    var cs = window.getComputedStyle(box);
    ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'wordSpacing', 'tabSize',
     'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth',
     'borderBottomWidth', 'borderLeftWidth', 'borderRadius', 'textIndent'].forEach(function (k) { layer.style[k] = cs[k]; });
    layer.style.width = box.offsetWidth + 'px';
    layer.style.height = box.offsetHeight + 'px';
    /* The box's scrollbar narrows its text; the layer has none, so it gives the same width up as padding. */
    var bar = box.offsetWidth - box.clientWidth - parseFloat(cs.borderLeftWidth) - parseFloat(cs.borderRightWidth);
    layer.style.paddingRight = (parseFloat(cs.paddingRight) + Math.max(0, bar)) + 'px';
    layer.scrollTop = box.scrollTop;
  }

  function draw() {
    var text = box.value;
    var html = '';
    var at = 0;
    var links = [];
    var m;
    LINK.lastIndex = 0;
    while ((m = LINK.exec(text)) !== null) {
      var bad = isGeneric(m[1]);
      html += esc(text.slice(at, m.index))
        + '<mark class="w' + (bad ? ' bad' : '') + '">' + esc('[' + m[1] + ']') + '</mark>'
        + '<mark class="u' + (refused(m[2]) ? ' bad' : '') + '">' + esc('(' + m[2] + ')') + '</mark>';
      at = m.index + m[0].length;
      links.push({ words: m[1], href: m[2], bad: bad });
    }
    /* A last line break needs a character after it, or the layer ends one line short. */
    layer.innerHTML = html + esc(text.slice(at)) + '\n ';
    layer.scrollTop = box.scrollTop;
    drawList(links);
    askLater(links);
  }

  /* Live link checks: url -> {site, safety, refused} | 'checking' | 'failed'. */
  var checks = {};
  var limited = false;
  var timer = null;
  function refused(href) {
    var c = checks[href];
    return !!(c && typeof c === 'object' && c.refused);
  }
  function askLater(links) {
    if (!list || !list.getAttribute('data-check-url') || limited) { return; }
    var todo = links.map(function (l) { return l.href; })
      .filter(function (h, i, all) { return all.indexOf(h) === i && checks[h] === undefined; });
    if (todo.length === 0) { return; }
    clearTimeout(timer);
    timer = setTimeout(function () { ask(todo.slice(0, 20)); }, 700);
  }
  function ask(urls) {
    urls.forEach(function (u) { checks[u] = 'checking'; });
    draw();
    fetch(list.getAttribute('data-check-url'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ token: list.getAttribute('data-check-token'), urls: urls })
    }).then(function (r) {
      if (r.status === 429) { limited = true; }
      return r.ok ? r.json() : null;
    }).then(function (data) {
      var got = (data && data.links) || {};
      urls.forEach(function (u) { checks[u] = got[u] && typeof got[u] === 'object' ? got[u] : 'failed'; });
      draw();
    }).catch(function () {
      urls.forEach(function (u) { checks[u] = 'failed'; });
      draw();
    });
  }
  /* A link's state in words, and its class: ok, wait, warn or bad. */
  function stateOf(href) {
    var c = checks[href];
    var say = function (k) { return list.getAttribute('data-st-' + k) || ''; };
    if (c === undefined) { return limited ? { cls: 'warn', text: say('limit') } : { cls: 'wait', text: say('checking') }; }
    if (c === 'checking') { return { cls: 'wait', text: say('checking') }; }
    if (c === 'failed') { return { cls: 'warn', text: limited ? say('limit') : say('unknown') }; }
    if (c.refused) { return { cls: 'bad', text: say(c.refused) }; }
    if (c.site === 'unknown' || c.safety === 'unknown') { return { cls: 'warn', text: say('unknown') }; }
    return { cls: 'ok', text: say('ok') };
  }

  function drawList(links) {
    /* The tick appears once a link is checked and not plainly fine (or a
       send came back for it); it never blocks while checks are running. */
    if (confirmBox) {
      var unclear = links.some(function (l) { var c = stateOf(l.href).cls; return c === 'warn' || c === 'bad'; });
      confirmBox.hidden = !unclear && !confirmBox.classList.contains('is-asked');
    }
    if (!list || !listH || !listOl) { return; }
    list.hidden = links.length === 0;
    listH.textContent = (list.getAttribute('data-h') || '%count%').replace('%count%', String(links.length));
    listOl.innerHTML = '';
    links.forEach(function (l) {
      var li = document.createElement('li');
      if (l.bad) { li.className = 'is-bad'; }
      var w = document.createElement('span');
      w.className = 'pt-links-w';
      w.textContent = l.words;
      var a = document.createElement('a');
      a.href = l.href;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = l.href.replace(/^https?:\/\//i, '').replace(/\/$/, '');
      var st = stateOf(l.href);
      var chip = document.createElement('span');
      chip.className = 'pt-links-st is-' + st.cls;
      chip.textContent = st.text;
      if (st.cls === 'bad') { li.className = 'is-bad'; }
      li.appendChild(w);
      li.appendChild(document.createTextNode(' → '));
      li.appendChild(a);
      li.appendChild(document.createTextNode(' '));
      li.appendChild(chip);
      if (l.bad) {
        var note = document.createElement('span');
        note.className = 'pt-links-bad';
        note.textContent = ' ' + (list.getAttribute('data-generic-note') || '');
        li.appendChild(note);
      }
      listOl.appendChild(li);
    });
  }

  /* "Link the selected words". */
  var sel = null;
  function closePanel() {
    if (!panel || !open) { return; }
    panel.hidden = true;
    open.hidden = false;
    open.setAttribute('aria-expanded', 'false');
    sel = null;
  }
  function openPanel() {
    var a = box.selectionStart, b = box.selectionEnd;
    var words = box.value.slice(a, b);
    if (a === b || words.trim() === '' || /[\[\]\n]/.test(words)) {
      say(msg ? msg.getAttribute('data-select') : '');
      box.focus();
      return;
    }
    /* Leave the spaces around the selection outside the link. */
    a += words.length - words.replace(/^\s+/, '').length;
    b -= words.length - words.replace(/\s+$/, '').length;
    sel = { start: a, end: b, words: box.value.slice(a, b) };
    say((msg && msg.getAttribute('data-for') || '%words%').replace('%words%', sel.words));
    panel.hidden = false;
    open.hidden = true;
    open.setAttribute('aria-expanded', 'true');
    url.value = '';
    url.focus();
  }
  function insertLink() {
    if (!sel) { closePanel(); return; }
    var href = url.value.trim();
    if (!/^https?:\/\/[^\s()<>"]+\.[^\s()<>"]+$/i.test(href)) {
      say(msg ? msg.getAttribute('data-bad-url') : '');
      url.focus();
      return;
    }
    var link = '[' + sel.words + '](' + href + ')';
    box.focus();
    box.setSelectionRange(sel.start, sel.end);
    var done = false;
    try { done = document.execCommand('insertText', false, link); } catch (e) { done = false; }
    if (!done) {
      box.value = box.value.slice(0, sel.start) + link + box.value.slice(sel.end);
      box.setSelectionRange(sel.start + link.length, sel.start + link.length);
      box.dispatchEvent(new Event('input'));
    }
    say('');
    closePanel();
  }

  if (bar && open && panel && url && insert && cancel) {
    bar.hidden = false;
    open.addEventListener('click', openPanel);
    insert.addEventListener('click', insertLink);
    cancel.addEventListener('click', function () { say(''); closePanel(); box.focus(); });
    /* Enter in the address field adds the link; it must never send the form. */
    url.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); insertLink(); }
      if (e.key === 'Escape') { e.preventDefault(); say(''); closePanel(); box.focus(); }
    });
  }

  box.addEventListener('input', draw);
  box.addEventListener('scroll', function () { layer.scrollTop = box.scrollTop; });
  if (window.ResizeObserver) { new ResizeObserver(fitLayer).observe(box); }
  window.addEventListener('resize', fitLayer);
  fitLayer();
  draw();
})();
