// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Vote UI (docs/specs/edit-items/README.md — votability). Rank is the vote
   (docs/specs/route-domain.md). Sample figures are metric-in, converted at
   render (docs/specs/account-and-auth.md §9). */
(function () {
  'use strict';

  function uElev(m) { return window.ccElev ? window.ccElev(m) : Math.round(Number(m)) + ' m'; }
  function uKm(km) { return window.ccKm ? window.ccKm(km) : Number(km).toFixed(1) + ' km'; }

  var DATA = {
    climbs: [
      ['Côte de la Redoute', '△' + uElev(168) + ' · 8.4%', 88],
      ['Mur de Huy', '△' + uElev(121) + ' · 9.3%', 81],
      ['Côte de Stockeu', '△' + uElev(90) + ' · 9.0%', 74],
      ['Côte de la Roche-aux-Faucons', '△' + uElev(135) + ' · 9.0%', 60],
      ['Côte de la Vecquée', '△ ' + uKm(3.1) + ' · 6.4%', 40]
    ],
    stays: [
      ['Cyclist gîte · Amblève', 'secure storage · used', 71],
      ['B&B · Stavelot', 'bike wash · drying', 58],
      ['Hostel · Malmedy', 'group-friendly', 44]
    ],
    views: [
      ['Signal de Botrange', '△' + uElev(694) + ' · highest point', 77],
      ['Barrage de la Gileppe', 'dam panorama', 61],
      ['La Gleize valley', 'golden-hour', 48]
    ],
    heritage: [
      ['Stavelot Abbey', 'Benedictine · museums', 69],
      ['Eddy Merckx stele · Stockeu', 'LBL heritage', 55],
      ['Coo waterfall', 'cascade · café', 47]
    ]
  };

  var curCat = 'climbs';
  var ballot = { climbs: [], stays: [], views: [], heritage: [] };

  var i18nEl = document.getElementById('vote-i18n');
  var I18N = { pct: '%pct%% this round', add: '▲ add', onBallot: '✓ on ballot', remove: 'remove', empty: 'Add up to 10 picks, in the order you rate them.' };
  if (i18nEl) {
    try { I18N = JSON.parse(i18nEl.textContent); } catch (e) { /* keep fallbacks */ }
  }

  function renderCands() {
    var el = document.getElementById('cands');
    if (!el) return;
    el.innerHTML = '';
    DATA[curCat].forEach(function (c) {
      var inB = ballot[curCat].indexOf(c[0]) >= 0;
      var d = document.createElement('div');
      d.className = 'cand';
      d.innerHTML =
        '<div class="nm"><h4>' + escHtml(c[0]) + '</h4><div class="m">' + escHtml(c[1]) + '</div></div>' +
        '<div class="scwrap"><div class="sc">' + escHtml(I18N.pct.replace('%pct%', c[2])) + '</div><div class="bar"><span class="t" style="width:' + c[2] + '%"></span></div></div>' +
        '<button class="add' + (inB ? ' in' : '') + '" data-nm="' + escAttr(c[0]) + '">' + escHtml(inB ? I18N.onBallot : I18N.add) + '</button>';
      el.appendChild(d);
    });

    el.querySelectorAll('button.add').forEach(function (btn) {
      btn.addEventListener('click', function () {
        toggle(btn.getAttribute('data-nm'));
      });
    });
  }

  function renderBallot() {
    var catLabel = document.getElementById('cat-l');
    if (catLabel) {
      var onTab = document.querySelector('.vtabs button.on');
      catLabel.textContent = onTab ? onTab.textContent : curCat;
    }
    var el = document.getElementById('ballot');
    if (!el) return;
    var b = ballot[curCat];
    el.innerHTML = b.length ? '' : '<div class="bempty">' + escHtml(I18N.empty) + '</div>';
    b.forEach(function (nm, i) {
      var d = document.createElement('div');
      d.className = 'bitem';
      d.dataset.idx = i;
      if (b.length > 1) d.title = I18N.reorder;
      d.innerHTML =
        (b.length > 1 ? '<span class="grip" aria-hidden="true">⠿</span>' : '') +
        '<span class="r">' + (i + 1) + '</span>' +
        '<span>' + escHtml(nm) + '</span>' +
        (b.length > 1
          ? '<span class="ud">'
            + '<button type="button" class="mv" data-dir="-1" data-idx="' + i + '" aria-label="' + escAttr(I18N.moveUp) + '"' + (0 === i ? ' disabled' : '') + '>▲</button>'
            + '<button type="button" class="mv" data-dir="1" data-idx="' + i + '" aria-label="' + escAttr(I18N.moveDown) + '"' + (i === b.length - 1 ? ' disabled' : '') + '>▼</button>'
            + '</span>'
          : '') +
        '<span class="x" data-nm="' + escAttr(nm) + '">' + escHtml(I18N.remove) + '</span>';
      el.appendChild(d);
    });

    el.querySelectorAll('span.x').forEach(function (span) {
      span.addEventListener('click', function () {
        toggle(span.getAttribute('data-nm'));
      });
    });

    function moveTo(from, to) {
      var arr = ballot[curCat];
      if (from === to || to < 0 || to >= arr.length) return;
      arr.splice(to, 0, arr.splice(from, 1)[0]);
      renderBallot();
    }

    el.querySelectorAll('button.mv').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var i = +btn.dataset.idx;
        moveTo(i, i + (+btn.dataset.dir));
      });
    });

    // Pointer Events (not HTML5 DnD — mobile never fires those for touch).
    var rows = [].slice.call(el.querySelectorAll('.bitem'));
    rows.forEach(function (row) {
      var grip = row.querySelector('.grip');
      if (!grip) return;
      grip.addEventListener('pointerdown', function (e) {
        e.preventDefault();
        var from = +row.dataset.idx;
        var target = from;
        var mids = rows.map(function (r) {
          var rect = r.getBoundingClientRect();
          return (rect.top + rect.bottom) / 2;
        });
        row.classList.add('dragging');
        grip.setPointerCapture(e.pointerId);

        var onMove = function (ev) {
          target = rows.length - 1;
          for (var i = 0; i < mids.length; i++) {
            if (ev.clientY < mids[i]) { target = i; break; }
          }
          rows.forEach(function (r, i) { r.classList.toggle('dropover', i === target && target !== from); });
        };
        var finish = function (apply) {
          grip.removeEventListener('pointermove', onMove);
          grip.removeEventListener('pointerup', onUp);
          grip.removeEventListener('pointercancel', onCancel);
          row.classList.remove('dragging');
          rows.forEach(function (r) { r.classList.remove('dropover'); });
          if (apply) moveTo(from, target);
        };
        var onUp = function () { finish(true); };
        var onCancel = function () { finish(false); };
        grip.addEventListener('pointermove', onMove);
        grip.addEventListener('pointerup', onUp);
        grip.addEventListener('pointercancel', onCancel);
      });
    });

    syncHiddenField();
  }

  function syncHiddenField() {
    var allSelected = [];
    Object.keys(ballot).forEach(function (cat) {
      ballot[cat].forEach(function (nm) {
        allSelected.push(cat + ':' + nm);
      });
    });
    var field = document.getElementById('vote_ballot');
    if (field) {
      field.value = allSelected.join('|');
    }
  }

  function toggle(nm) {
    var b = ballot[curCat];
    var i = b.indexOf(nm);
    if (i >= 0) {
      b.splice(i, 1);
    } else if (b.length < 10) {
      b.push(nm);
    }
    renderCands();
    renderBallot();
  }

  function cat(btn, c) {
    document.querySelectorAll('.vtabs button').forEach(function (x) {
      x.classList.remove('on');
    });
    btn.classList.add('on');
    curCat = c;
    renderCands();
    renderBallot();
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // Attribute values round-trip through the HTML parser; unescaped & breaks matching.
  function escAttr(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Delegated click: CSP does not cover inline onclick (docs/specs/security-architecture.md §2).
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-votecat]');
    if (b) cat(b, b.dataset.votecat);
  });

  renderCands();
  renderBallot();
})();
