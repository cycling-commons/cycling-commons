// SPDX-License-Identifier: AGPL-3.0-only
/* Photos on /propose-route (docs/specs/route-domain.md §4.5,
   docs/specs/photo-uploads.md §5i). Mounts the same uploader the /improve
   wizard uses, consent and all; only the pin differs.

   An upload needs a point it is for: the server stores the photo in that
   point's continent and the worker measures the camera's distance to it
   (photo-uploads.md §3). A live route hands its point over in
   `data-route-pin`. A new proposal has no point until its GPX is chosen, so the
   middle track point of the chosen file is used: a point ON the route, never
   the start or the finish, which the privacy trim keeps off the server
   (route-domain.md §4.3). The server measures again against the stored line
   when the photo is claimed. */
(function () {
  'use strict';

  /* The middle <trkpt> of a GPX text (a <rtept> when it has no track), as
     {lat, lng}, or null. Attribute order and namespace prefixes vary between
     exporters, so each point's attributes are read by name. */
  function gpxMidpoint(text) {
    if ('string' !== typeof text) return null;
    function points(tag) {
      var re = new RegExp('<(?:[\\w-]+:)?' + tag + '\\b([^>]*)>', 'g');
      var out = [];
      var m;
      while ((m = re.exec(text)) !== null) {
        var lat = /\blat\s*=\s*["']([^"']+)["']/.exec(m[1]);
        var lon = /\blon\s*=\s*["']([^"']+)["']/.exec(m[1]);
        var la = lat ? parseFloat(lat[1]) : NaN;
        var lo = lon ? parseFloat(lon[1]) : NaN;
        if (isFinite(la) && isFinite(lo) && Math.abs(la) <= 90 && Math.abs(lo) <= 180) out.push({ lat: la, lng: lo });
      }
      return out;
    }
    var pts = points('trkpt');
    if (!pts.length) pts = points('rtept');
    return pts.length ? pts[Math.floor(pts.length / 2)] : null;
  }

  if ('undefined' !== typeof module && module.exports) {
    module.exports = { gpxMidpoint: gpxMidpoint };
    return;
  }

  var form = document.querySelector('form[data-route-photos]');
  if (!form || !window.Cc || !window.Cc.mountMediaUploads) return;

  var at = null;
  var fixed = form.getAttribute('data-route-pin');
  if (fixed) {
    try {
      var p = JSON.parse(fixed);
      if (Array.isArray(p) && 2 === p.length) at = { lat: p[0], lng: p[1] };
    } catch (e) { at = null; }
  }

  var gpx = form.querySelector('input[type="file"][name$="[gpx]"]');
  if (gpx) {
    gpx.addEventListener('change', function () {
      at = null;
      var file = gpx.files && gpx.files[0];
      if (!file || !file.text) return;
      file.text().then(function (text) { at = gpxMidpoint(text); }).catch(function () { at = null; });
    });
  }

  var submit = form.querySelector('button[type="submit"]');
  /* Held while a photo is uploading or checking: an id not yet in the hidden
     field would never be claimed (photo-uploads.md §4). */
  document.addEventListener('cc:media-busy', function (e) {
    if (submit) submit.disabled = !!(e.detail && e.detail.busy);
  });

  window.Cc.mountMediaUploads({
    hidden: form.querySelector('[name$="[mediaIds]"]'),
    hiddenAlts: form.querySelector('[name$="[mediaAlts]"]'),
    pin: function () { return at; }
  });
})();
