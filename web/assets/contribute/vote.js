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
        '<div class="scwrap"><div class="sc">' + c[2] + '% this round</div><div class="bar"><span class="t" style="width:' + c[2] + '%"></span></div></div>' +
        '<button class="add' + (inB ? ' in' : '') + '" data-nm="' + escAttr(c[0]) + '">' + (inB ? '✓ on ballot' : '▲ add') + '</button>';
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
      catLabel.textContent = curCat[0].toUpperCase() + curCat.slice(1);
    }
    var el = document.getElementById('ballot');
    if (!el) return;
    var b = ballot[curCat];
    el.innerHTML = b.length ? '' : '<div class="bempty">Add up to 10 picks, in the order you rate them.</div>';
    b.forEach(function (nm, i) {
      var d = document.createElement('div');
      d.className = 'bitem';
      d.innerHTML =
        '<span class="r">' + (i + 1) + '</span>' +
        '<span>' + escHtml(nm) + '</span>' +
        '<span class="x" data-nm="' + escAttr(nm) + '">remove</span>';
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
