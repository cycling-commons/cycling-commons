// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* E·Where-to-sleep also carries the official Tourisme Wallonie accommodation set
   (CC-BY 4.0, separate fixture). Merge it into the stays dots, each tagged src='pivot' so the
   drawer credits Tourisme Wallonie / Géoportail — NOT OSM. Counts + curated toggle then just work. */
(function(){ var O=window.CC_STAYS_OSM, P=window.CC_STAYS_PIVOT; if(O&&P&&!O._pivot){
  P.features.forEach(function(f){ f.properties.src='pivot'; });
  O.features=O.features.concat(P.features); O._pivot=1; } })();
