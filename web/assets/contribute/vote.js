// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
(function () {
  'use strict';

  var DATA = {
    climbs: [
      ['Côte de la Redoute', '△168 m · 8.4%', 88],
      ['Mur de Huy', '△121 m · 9.3%', 81],
      ['Côte de Stockeu', '△90 m · 9.0%', 74],
      ['Côte de la Roche-aux-Faucons', '△135 m · 9.0%', 60],
      ['Côte de la Vecquée', '△ 3.1 km · 6.4%', 40]
    ],
    stays: [
      ['Cyclist gîte · Amblève', 'secure storage · used', 71],
      ['B&B · Stavelot', 'bike wash · drying', 58],
      ['Hostel · Malmedy', 'group-friendly', 44]
    ],
    views: [
      ['Signal de Botrange', '△694 m · highest point', 77],
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

  // Server-translated strings (vote.* catalog keys, #vote-i18n JSON block) —
  // the client renders candidate rows and the ballot panel, so hardcoded
  // English here would leak into every locale. Fallbacks keep the demo alive
  // if the block is ever missing.
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
      // The active tab already carries the translated category label.
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
      d.innerHTML =
        '<span class="r">' + (i + 1) + '</span>' +
        '<span>' + escHtml(nm) + '</span>' +
        '<span class="x" data-nm="' + escAttr(nm) + '">' + escHtml(I18N.remove) + '</span>';
      el.appendChild(d);
    });

    el.querySelectorAll('span.x').forEach(function (span) {
      span.addEventListener('click', function () {
        toggle(span.getAttribute('data-nm'));
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

  // Full escape (not just quotes): attribute values round-trip through the HTML
  // parser, so unescaped & would decode entity-like sequences on getAttribute
  // and break toggle() matching.
  function escAttr(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Expose cat() for inline onclick in Twig template
  window.voteCat = cat;

  renderCands();
  renderBallot();
})();
