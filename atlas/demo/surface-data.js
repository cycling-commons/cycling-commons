// SPDX-License-Identifier: ODbL-1.0
// Road-segment geometry derived from OpenStreetMap (© OpenStreetMap contributors)
// (production: Overpass way[surface]/[smoothness]/[width]). ODbL 1.0.
/* A · Road surface — hand-picked demo segments (frontend fixture).
 * In production these come from an Overpass `way[surface]` / `[smoothness]` / `[width]`
 * query over the region bbox; here they are illustrative real-world segment types so the
 * map can show how surface is depicted (solid paved · dashed gravel · dotted pavé).
 *
 * cls drives colour + pattern (see SURFACE_STYLE in map.html):
 *   cycleway · paved · gravel · pave · ground
 */
window.CC_SURFACE = {
  segments: [
    {
      name: 'RAVeL L45 · ex-railway',
      surface: 'Asphalt', smoothness: 'Excellent', width: '3.0 m',
      traffic: 'Car-free (RAVeL)', cls: 'cycleway',
      photoFile: 'Charleroi-ravel.jpg', photoCredit: 'Bronstein', photoUser: 'Bronstein', photoLicense: 'CC BY-SA 4.0',
      // real geometry from OSM way/455528906 (RAVeL L45, Coo → Stavelot, surface=asphalt), decimated to ~21 pts
      path: [
        [50.37649, 5.87599], [50.37683, 5.87804], [50.37746, 5.88152], [50.37902, 5.88607],
        [50.37896, 5.89078], [50.37933, 5.89456], [50.38068, 5.89732], [50.38257, 5.89876],
        [50.38377, 5.89968], [50.38461, 5.90084], [50.38512, 5.90192], [50.3856, 5.9037],
        [50.38576, 5.90586], [50.38558, 5.91342], [50.38556, 5.91545], [50.38577, 5.91702],
        [50.38617, 5.91848], [50.38679, 5.91984], [50.38742, 5.92078], [50.38873, 5.92195],
        [50.39034, 5.92284]
      ]
    },
    {
      name: 'Hautes Fagnes gravel · ride',
      surface: 'Gravel', smoothness: 'Intermediate', width: '3.0 m',
      traffic: 'Quiet', cls: 'gravel',
      // real geometry via BRouter (trekking) between two ride points
      path: [
        [50.49351, 6.10205], [50.4932, 6.10063], [50.4927, 6.09919], [50.49185, 6.09688],
        [50.49105, 6.09473], [50.49073, 6.09348], [50.49054, 6.09197], [50.49053, 6.08946],
        [50.49052, 6.08652], [50.4905, 6.08122], [50.49058, 6.07912], [50.49119, 6.07574],
        [50.49237, 6.07258], [50.49412, 6.06809], [50.49436, 6.06747], [50.49607, 6.06308],
        [50.49641, 6.06272], [50.49687, 6.06257], [50.49726, 6.06238], [50.49779, 6.0616],
        [50.49805, 6.06069], [50.49665, 6.05482], [50.49504, 6.05272], [50.49284, 6.04981],
        [50.49225, 6.04877], [50.49059, 6.04468], [50.48756, 6.03735], [50.48676, 6.03604],
        [50.4856, 6.0321], [50.48423, 6.02867], [50.48311, 6.02446], [50.48267, 6.02446],
        [50.48155, 6.02522], [50.47813, 6.0244], [50.47623, 6.024], [50.47504, 6.02403],
        [50.47412, 6.02443], [50.47409, 6.02443]
      ]
    },
    {
      name: 'Rue Neuve · Stavelot old town',
      surface: 'Sett (pavé)', smoothness: 'Bad', width: '4.0 m',
      traffic: 'Moderate', cls: 'pave',
      photoFile: 'Sett pavement in Liège.jpg', photoCredit: 'Jan Honvehlmann', photoUser: 'Jan Honvehlmann', photoLicense: 'CC BY-SA 4.0',
      // real geometry from OSM way/77930062 (Rue Neuve, surface=sett — genuine Stavelot old-town pavé)
      path: [
        [50.39206, 5.9257], [50.39212, 5.9258], [50.39225, 5.92605], [50.39273, 5.9269],
        [50.39299, 5.92734], [50.39316, 5.92771], [50.39337, 5.9282], [50.39343, 5.92837],
        [50.39349, 5.92852], [50.3936, 5.92897]
      ]
    },
    {
      // along the real Spa · Sankt Vith loop — the gravelly descent back into Spa near the end of the ride
      name: 'Spa · Sankt Vith descent · gravel',
      surface: 'Gravel', smoothness: 'Bad', width: '3.0 m',
      traffic: 'Quiet', cls: 'gravel',
      path: [
        [50.49136, 5.89579], [50.49059, 5.89475], [50.48955, 5.89434], [50.48930, 5.89377],
        [50.48921, 5.89303], [50.48833, 5.89187], [50.48765, 5.89062], [50.48747, 5.88992],
        [50.48749, 5.88887], [50.48761, 5.88799], [50.48748, 5.88636], [50.48754, 5.88490],
        [50.48775, 5.88387], [50.48730, 5.88146]
      ]
    },
    {
      name: 'Avenue André Grégoire · Stavelot', surface: 'Cobblestone', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 579788408,
      path: [
        [50.39029, 5.92313], [50.39023, 5.92326], [50.39016, 5.92331], [50.39004, 5.9233],
        [50.38957, 5.92305], [50.3894, 5.92292], [50.38893, 5.92248], [50.38883, 5.92244],
        [50.38875, 5.92246], [50.38871, 5.92254], [50.38859, 5.92301]
      ]
    },
    {
      name: 'Avenue Ferdinand Nicolay · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 283608313,
      path: [
        [50.39496, 5.93198], [50.39504, 5.9322], [50.39528, 5.93261], [50.39531, 5.93267],
        [50.39566, 5.93336], [50.39595, 5.93404]
      ]
    },
    {
      name: 'Avenue Ferdinand Nicolay · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 77930069,
      path: [
        [50.3941, 5.93051], [50.39417, 5.93063], [50.39425, 5.93077], [50.39472, 5.93165]
      ]
    },
    {
      name: 'Avenue Ferdinand Nicolay · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 283608312,
      path: [
        [50.39472, 5.93165], [50.39478, 5.93184], [50.39485, 5.93195], [50.39496, 5.93198]
      ]
    },
    {
      name: 'Avenue Ferdinand Nicolay · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 283608311,
      path: [
        [50.39496, 5.93198], [50.39492, 5.93187], [50.39487, 5.93183], [50.39483, 5.93178],
        [50.39472, 5.93165]
      ]
    },
    {
      name: 'Devant les Capucins · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 44044402,
      path: [
        [50.39572, 5.93152], [50.39626, 5.93175], [50.39637, 5.93181]
      ]
    },
    {
      name: 'Devant les Capucins · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 283608314,
      path: [
        [50.39572, 5.93152], [50.3954, 5.9315], [50.39531, 5.93149]
      ]
    },
    {
      name: 'Place Elise Grandprez · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 694643328,
      path: [
        [50.392, 5.92556], [50.39206, 5.9257]
      ]
    },
    {
      name: 'Place Prume · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 323850492,
      path: [
        [50.39531, 5.93149], [50.39521, 5.93153], [50.39506, 5.93156]
      ]
    },
    {
      name: 'Place Prume · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 696083812,
      path: [
        [50.39506, 5.93156], [50.39503, 5.93137]
      ]
    },
    {
      name: 'Place Prume · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 323850491,
      path: [
        [50.39506, 5.93156], [50.39501, 5.93173]
      ]
    },
    {
      name: 'Place Wibald · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 305824507,
      path: [
        [50.39247, 5.93093], [50.39252, 5.93088], [50.39258, 5.93085]
      ]
    },
    {
      name: 'Place du Vinâve · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 77811130,
      path: [
        [50.3942, 5.92937], [50.39444, 5.92969], [50.39464, 5.92996]
      ]
    },
    {
      name: 'Rue Basse · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 62325945,
      path: [
        [50.39503, 5.93137], [50.395, 5.93127], [50.39489, 5.93102], [50.39469, 5.93061],
        [50.39461, 5.93035], [50.39459, 5.93017]
      ]
    },
    {
      name: 'Rue Haut Rivage · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 77810869,
      path: [
        [50.39374, 5.92957], [50.39368, 5.92953], [50.39354, 5.92948], [50.39342, 5.92946],
        [50.3933, 5.92946], [50.39309, 5.9296], [50.39289, 5.92978]
      ]
    },
    {
      name: 'Rue Haute · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 62325976,
      path: [
        [50.39572, 5.93152], [50.39572, 5.93092], [50.39567, 5.9307], [50.3955, 5.9303]
      ]
    },
    {
      name: 'Rue Haute · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 723873874,
      path: [
        [50.3955, 5.9303], [50.39521, 5.92983], [50.39497, 5.92941]
      ]
    },
    {
      name: 'Rue Henri Massange · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 148819632,
      path: [
        [50.39406, 5.93045], [50.39402, 5.93032], [50.39397, 5.93021], [50.39383, 5.92985],
        [50.39374, 5.92957]
      ]
    },
    {
      name: 'Rue Henri Massange · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 441966453,
      path: [
        [50.3936, 5.92897], [50.39367, 5.92925], [50.39374, 5.92957]
      ]
    },
    {
      name: 'Rue Henri Massange · Stavelot', surface: 'Unhewn cobblestone', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 148819640,
      path: [
        [50.3941, 5.93051], [50.39406, 5.93045]
      ]
    },
    {
      name: 'Rue Hottonruy · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 77811039,
      path: [
        [50.3936, 5.92897], [50.39333, 5.92913], [50.3932, 5.92919], [50.39306, 5.92929],
        [50.39295, 5.92936], [50.39276, 5.92966]
      ]
    },
    {
      name: 'Rue de l\'Église · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 323754646,
      path: [
        [50.39393, 5.92865], [50.3936, 5.92897]
      ]
    },
    {
      name: 'Rue de la Fontaine · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 62325922,
      path: [
        [50.39383, 5.92985], [50.39392, 5.92976], [50.39402, 5.9297], [50.39417, 5.92978],
        [50.39431, 5.92995], [50.39444, 5.93026]
      ]
    },
    {
      name: 'Rue du Bac · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 745017809,
      path: [
        [50.39572, 5.93092], [50.39564, 5.93106], [50.39531, 5.93149]
      ]
    },
    {
      name: 'Rue du Châtelet · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 77810919,
      path: [
        [50.39258, 5.93085], [50.39267, 5.93087], [50.39277, 5.93086], [50.39302, 5.93084],
        [50.39306, 5.93083], [50.39328, 5.93075], [50.39353, 5.93066], [50.39401, 5.93048]
      ]
    },
    {
      name: 'Rue du Vinâve · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 77810710,
      path: [
        [50.39374, 5.92957], [50.39395, 5.92953], [50.39405, 5.9295], [50.39411, 5.92946],
        [50.3942, 5.92937]
      ]
    },
    {
      name: 'Ruelle Delbrouck · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 77811363,
      path: [
        [50.39497, 5.92941], [50.39482, 5.92911], [50.39477, 5.92917], [50.39445, 5.92967],
        [50.39444, 5.92969]
      ]
    },
    {
      name: 'Sur les Ruys · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 154686098,
      path: [
        [50.39367, 5.92925], [50.39386, 5.92908], [50.39404, 5.92888]
      ]
    },
    {
      name: 'Cobbled lane · Stavelot', surface: 'Unhewn cobblestone', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 148822366,
      path: [
        [50.38368, 5.94556], [50.38329, 5.94618], [50.38313, 5.9465], [50.38294, 5.94701],
        [50.38279, 5.94743], [50.38269, 5.94768], [50.38258, 5.94788], [50.38185, 5.94873],
        [50.38142, 5.94924], [50.38107, 5.94956], [50.38062, 5.94993], [50.38038, 5.95015],
        [50.37996, 5.95042], [50.37975, 5.95055], [50.37951, 5.95065], [50.37927, 5.95071],
        [50.37907, 5.95074], [50.37883, 5.95086], [50.37824, 5.95126], [50.37797, 5.95148],
        [50.37783, 5.95172], [50.37768, 5.95207], [50.37756, 5.95228]
      ]
    },
    {
      name: 'Cobbled lane · Stavelot', surface: 'Unhewn cobblestone', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 492172524,
      path: [
        [50.38397, 5.94543], [50.38385, 5.94544], [50.38374, 5.9455], [50.38368, 5.94556]
      ]
    },
    {
      name: 'Cobbled lane · Stavelot', surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',
      traffic: 'Local street', cls: 'pave', wayId: 696083811,
      path: [
        [50.39501, 5.93173], [50.39496, 5.93198]
      ]
    }
  ]
};
