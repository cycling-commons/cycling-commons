// SPDX-License-Identifier: AGPL-3.0-only
/* Let somebody add screenshots one at a time, and see what they added.
   docs/specs/contact-and-support.md §6

   THE BUG THIS FIXES. `<input type="file" multiple>` REPLACES its FileList on
   every selection. So a page that says "up to 3" and offers one input is
   telling the truth only if all three are picked in a single dialog; anybody
   who adds a second one afterwards silently loses the first, and finds out
   after the report is filed, or never. That is the whole reason this file
   exists.

   It keeps its own list, rebuilds a DataTransfer, and writes it back to the
   input, so the form still posts an ordinary `screenshot[]` and the server is
   unchanged.

   Without JavaScript the native input still works: `multiple` means a single
   dialog can pick all three at once. The list and the remove buttons are the
   enhancement, not the feature. */
(function () {
  'use strict';

  var input = document.getElementById('b-shot');
  if (!input || typeof DataTransfer !== 'function') return;

  /* The three strings, from the input's own attributes rather than a global an
     inline script had to set. A page carrying an inline script needs a CSP
     nonce, and a nonce cannot be held in a shared cache (page-caching.md
     §3.2). */
  var L = {
    remove: input.dataset.labelRemove || '',
    tooBig: input.dataset.labelTooBig || '',
    full: input.dataset.labelFull || ''
  };
  var max = parseInt(input.dataset.max, 10) || 3;
  var maxBytes = parseInt(input.dataset.maxBytes, 10) || 0;

  var list = document.createElement('ul');
  list.className = 'shotlist';
  input.parentNode.insertBefore(list, input.nextSibling);

  var note = document.createElement('p');
  note.className = 'chint shotnote';
  note.setAttribute('role', 'status');
  input.parentNode.insertBefore(note, list.nextSibling);

  var picked = [];

  function human(bytes) {
    var mb = bytes / (1024 * 1024);
    return (mb >= 1 ? mb.toFixed(1) : (bytes / 1024).toFixed(0) + ' k')
      + (mb >= 1 ? ' MB' : 'B');
  }

  function say(text) {
    note.textContent = text || '';
    note.hidden = !text;
  }

  function commit() {
    var dt = new DataTransfer();
    picked.forEach(function (f) { dt.items.add(f); });
    input.files = dt.files;
    render();
  }

  function render() {
    list.textContent = '';
    picked.forEach(function (file, i) {
      var li = document.createElement('li');

      var name = document.createElement('span');
      name.className = 'shotname';
      name.textContent = file.name + ' · ' + human(file.size);
      li.appendChild(name);

      var drop = document.createElement('button');
      drop.type = 'button';
      drop.className = 'shotdrop';
      drop.textContent = '×';
      /* The glyph is a symbol, so the name has to come from the label. */
      drop.setAttribute('aria-label', (L.remove || 'Remove {name}').replace('{name}', file.name));
      drop.addEventListener('click', function () {
        picked.splice(i, 1);
        say('');
        commit();
      });
      li.appendChild(drop);

      list.appendChild(li);
    });

    /* Once the limit is reached the control would only disappoint. */
    input.disabled = picked.length >= max;
  }

  input.addEventListener('change', function () {
    var incoming = Array.prototype.slice.call(input.files);
    var refusedBig = [];
    var room = max - picked.length;
    var refusedFull = 0;

    incoming.forEach(function (file) {
      if (maxBytes && file.size > maxBytes) { refusedBig.push(file.name); return; }
      if (room <= 0) { refusedFull += 1; return; }
      /* Same file twice is a mis-click, not a second picture. */
      var already = picked.some(function (p) {
        return p.name === file.name && p.size === file.size && p.lastModified === file.lastModified;
      });
      if (already) return;
      picked.push(file);
      room -= 1;
    });

    var messages = [];
    if (refusedBig.length) {
      messages.push((L.tooBig || '{names} is over {max}.')
        .replace('{names}', refusedBig.join(', '))
        .replace('{max}', human(maxBytes)));
    }
    if (refusedFull) {
      messages.push((L.full || 'Only {max} can be sent.').replace('{max}', String(max)));
    }
    say(messages.join(' '));

    commit();
  });
})();
