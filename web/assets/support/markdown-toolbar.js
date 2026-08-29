// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* A small formatting toolbar for the two bug-report textareas.
   docs/specs/contact-and-support.md §15

   What this is FOR: somebody pasting a stack trace, or listing three steps,
   should not have to know that ``` and - mean something. The buttons write the
   markdown for them, and the hint under the field says what is understood.

   What it is NOT: a preview, and not a rich editor. The textarea still holds
   plain text and the form still posts plain text, so with JavaScript off the
   field works exactly as before and somebody who types markdown by hand gets
   the same result. That is why the toolbar is built here rather than shipped in
   the template: a row of buttons that does nothing is worse than no row.

   Rendering happens on the server (App\Support\BugMarkdown), which escapes
   first and sanitises after. Nothing in this file is a security boundary. */
(function () {
  'use strict';

  var LABELS = window.CC_MD_LABELS || {};

  /* Wrap the selection, or drop a placeholder and select it so the next
     keystroke replaces it. An empty selection that leaves ** ** behind is the
     single most annoying thing a toolbar can do. */
  function wrap(area, before, after, placeholder) {
    var start = area.selectionStart;
    var end = area.selectionEnd;
    var selected = area.value.slice(start, end) || placeholder;
    var value = area.value.slice(0, start) + before + selected + after + area.value.slice(end);

    /* setRangeText where it exists: it keeps the browser's own undo stack, so
       ctrl+Z after a button press undoes the button and not the whole field. */
    if (typeof area.setRangeText === 'function') {
      area.setRangeText(before + selected + after, start, end, 'select');
    } else {
      area.value = value;
      area.setSelectionRange(start + before.length, start + before.length + selected.length);
    }
    area.focus();
    area.dispatchEvent(new Event('input', { bubbles: true }));
  }

  /* Prefix every selected line. An empty selection prefixes the line the
     caret is on, which is what somebody starting a list expects. */
  function prefixLines(area, marker) {
    var value = area.value;
    var start = value.lastIndexOf('\n', area.selectionStart - 1) + 1;
    var end = area.selectionEnd;
    var lineEnd = value.indexOf('\n', end);
    if (lineEnd === -1) lineEnd = value.length;

    var block = value.slice(start, lineEnd) || '';
    var n = 0;
    var out = block.split('\n').map(function (line) {
      n += 1;
      return (marker === '1.' ? n + '. ' : marker + ' ') + line;
    }).join('\n');

    if (typeof area.setRangeText === 'function') {
      area.setRangeText(out, start, lineEnd, 'select');
    } else {
      area.value = value.slice(0, start) + out + value.slice(lineEnd);
      area.setSelectionRange(start, start + out.length);
    }
    area.focus();
    area.dispatchEvent(new Event('input', { bubbles: true }));
  }

  var BUTTONS = [
    { key: 'bold', text: 'B', cls: 'md-b', run: function (a) { wrap(a, '**', '**', LABELS.boldWord || 'bold'); } },
    { key: 'italic', text: 'I', cls: 'md-i', run: function (a) { wrap(a, '*', '*', LABELS.italicWord || 'italic'); } },
    { key: 'code', text: '<>', cls: 'md-c', run: function (a) { wrap(a, '`', '`', LABELS.codeWord || 'code'); } },
    { key: 'block', text: '{ }', cls: 'md-p', run: function (a) { wrap(a, '```\n', '\n```', LABELS.blockWord || 'paste here'); } },
    { key: 'bullet', text: '•', cls: 'md-u', run: function (a) { prefixLines(a, '-'); } },
    { key: 'number', text: '1.', cls: 'md-o', run: function (a) { prefixLines(a, '1.'); } }
  ];

  function build(area) {
    if (!area || area.dataset.mdToolbar === '1') return;
    area.dataset.mdToolbar = '1';

    var bar = document.createElement('div');
    bar.className = 'md-bar';
    /* A group of buttons that all act on one field: name the group, so a
       screen reader says what these six things are before reading them. */
    bar.setAttribute('role', 'group');
    bar.setAttribute('aria-label', LABELS.barLabel || 'Formatting');

    BUTTONS.forEach(function (b) {
      var el = document.createElement('button');
      el.type = 'button';
      el.className = 'md-btn ' + b.cls;
      el.textContent = b.text;
      /* The glyph is a symbol, so the accessible name has to come from the
         label and not from the text content. */
      el.setAttribute('aria-label', LABELS[b.key] || b.key);
      el.title = LABELS[b.key] || b.key;
      el.addEventListener('click', function () { b.run(area); });
      bar.appendChild(el);
    });

    area.parentNode.insertBefore(bar, area);

    if (LABELS.hint) {
      var hint = document.createElement('p');
      hint.className = 'md-hint';
      hint.textContent = LABELS.hint;
      /* Described-by, so the hint is read when the field takes focus rather
         than only when somebody happens to reach it. */
      var id = area.id + '-md-hint';
      hint.id = id;
      var described = area.getAttribute('aria-describedby');
      area.setAttribute('aria-describedby', described ? described + ' ' + id : id);
      area.parentNode.insertBefore(hint, area.nextSibling);
    }
  }

  function init() {
    ['b-body', 'b-steps', 'bf-body', 'bf-steps'].forEach(function (id) {
      build(document.getElementById(id));
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
