<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The per-type field schemas that make the improve wizard type-aware.
 *
 * Schemas are per item type, not per individual feature: the demo registry in
 * `atlas/demo/edit-items.js` has one entry per climb, but here the four demo
 * climbs collapse to one Climbs schema, and so on for every type. Design
 * source of truth: docs/specs/edit-items/<LETTER>-*.md and
 * docs/specs/edit-items/README.md.
 *
 * This is a form/attribute schema, not a domain schema: it declares the
 * per-type field shape and vocabulary, consumed by
 * {@see Import\AttributeVocabulary} and
 * {@see \App\Contribution\CatalogContributionService}, independent of
 * whether a given kind ('climb', 'improve', …) is wired to real persistence yet.
 *
 * @api Injected into the improve form/controller to build type-aware fields.
 */
final class CatalogFormRegistry
{
    private const array UNKNOWN_YES_NO = ['Unknown', 'Yes', 'No'];

    /**
     * Whether a place is still what the map says it is.
     *
     * One vocabulary across every confirmable point type, because a rider
     * standing in front of a dead tap, a shut shelter and a bench that is no
     * longer there is answering the same question each time, and three
     * different wordings would make the same fact three fields. The map's
     * one-tap answers write exactly these values (OsmConfirmController), so a
     * tap and a form edit can never disagree about what "gone" is called.
     *
     * 'Not there anymore' is the one with teeth: an item carrying it is not
     * drawn (CatalogProvider::itemRows), while its OSM ref stays claimed, so
     * the reference point does not come back in its place.
     *
     * No DEFAULT, deliberately. A default would make every untouched edit form
     * assert 'As mapped' about a place the editor never looked at - and worse,
     * turn a no-op edit into a change, which the intake is supposed to refuse
     * (MovedPinTest). Silence here means nobody has said.
     */
    private const array CONDITION = ['As mapped', 'Out of order', 'Closed', 'Not there anymore'];

    /**
     * The same vocabulary, minus the answer that cannot be true here.
     *
     * 'Out of order' needs working parts. A tap, a pump and a toilet have
     * them; a viewpoint, a shelter, a monument and a station platform do not,
     * and the wizard offered a rider standing at a viewpoint the choice of
     * calling the view broken (owner-reported 2026-08-14, "those are strange
     * options for Scenery"). The map's one-tap row has drawn this line since
     * 2026-08-12 - `CC_BREAKABLE` in community.js, whose comment uses this
     * exact example - so the form was the odd one out, offering by form what
     * the map refused to offer by tap.
     *
     * Same VALUES as CONDITION, deliberately: this is a narrower menu, never a
     * second vocabulary, so a tap and a form edit still cannot disagree about
     * what "gone" is called. Keep the two lists in step with `CC_BREAKABLE`.
     */
    private const array CONDITION_NO_PARTS = ['As mapped', 'Closed', 'Not there anymore'];

    /**
     * Opening hours is intentionally NOT free text: specific weekly hours change
     * without notice and we can't verify them, so we only record what stays true,
     * round-the-clock or "check the source", and default to Unknown.
     */
    private const array OPENING_HOURS = ['Unknown', '24/7', 'See website'];

    public function for(ItemType $type, ?ServiceKind $serviceKind = null): ItemFieldSet
    {
        return match ($type) {
            ItemType::RoadSurface => new ItemFieldSet(
                fields: [
                    CatalogField::select('surface', 'Surface', SurfaceVocabulary::DECLARABLE),
                    // What KIND of way this is. The drawer has always shown it
                    // for a tile line — straight from OSM's `highway` tag — and
                    // the form had no way to say it was wrong (owner-reported
                    // 2026-08-12), which is the one gap that makes a read-only
                    // row feel like a locked door.
                    //
                    // Rider words, not OSM's. `unclassified` is a British road
                    // -class term meaning "a public road below tertiary", and
                    // nobody outside mapping reads it that way; RoadType owns
                    // the translation both directions.
                    CatalogField::select('roadType', 'Road type', RoadType::DECLARABLE),
                    CatalogField::select('smoothness', 'Smoothness', ['Excellent', 'Good', 'Intermediate', 'Bad', 'Very bad']),
                    // 'Car-free', not 'Car-free (RAVeL)'. RAVeL is one region's
                    // brand for its greenway network and this layer now serves
                    // twelve countries, so the parenthetical was both parochial
                    // and wrong outside Wallonia (owner 2026-08-12). It is also
                    // what the harvester has been storing all along, so the
                    // dropdown now agrees with the data instead of adding a
                    // second spelling of the same fact.
                    CatalogField::select('traffic', 'Traffic', ['Quiet', 'Moderate', 'Busy', 'Car-free']),
                    // Beside Traffic, not stranded in "Add missing": they are
                    // one question asked twice ("how much motor traffic, and is
                    // it kept off?"), and on a narrow screen the two sat pages
                    // apart. The pane a field lives in is presentation — both
                    // panes merge into one flat attribute set on submit — so
                    // moving it changes nothing about storage.
                    CatalogField::select('segregated', 'Segregated from cars?', self::UNKNOWN_YES_NO),
                ],
                addFields: [
                    CatalogField::select('lit', 'Lit at night?', self::UNKNOWN_YES_NO),
                    // 'Forestry work' rather than 'Forestry': the bare noun does
                    // not say what closes the road (owner asked what it meant,
                    // 2026-08-12). It is the logging season, when a forest track
                    // is shut for felling and hauling.
                    CatalogField::select('seasonalClosure', 'Seasonal closure?', ['None', 'Winter', 'Forestry work']),
                    // Width and Note close the form, side by side (owner
                    // 2026-08-14): width is barely useful to a cyclist and had
                    // been shipping a made-up 3.0 default nobody measured —
                    // the default is gone (blank means "not stated", which is
                    // the truth), and the field may go entirely later.
                    CatalogField::text('width', 'Width (m)'),
                    CatalogField::textarea('note', 'Note', 'e.g. resurfaced in 2025, or pavé through the village'),
                ],
            ),

            ItemType::Climbs => new ItemFieldSet(
                fields: [
                    CatalogField::text('name', 'Name', display: false),
                    CatalogField::select('surface', 'Surface', ['Smooth asphalt', 'Asphalt', 'Worn asphalt', 'Cobbles', 'Gravel']),
                    // sq/tr use the same vocabulary as
                    // App\Contribution\CatalogContributionService::CLIMB_FIELDS.
                    // The drawer already shows these per climb (map.js's
                    // 'Road quality'/'Traffic' rows); this makes them editable.
                    CatalogField::select('sq', 'Road quality', ['Smooth', 'Good', 'Worn', 'Rough', 'Broken / loose']),
                    CatalogField::select('tr', 'Traffic', ['Traffic-free', 'Quiet', 'Moderate', 'Busy']),
                    // Both gradients are measured from the drawn line, never
                    // typed: the average is ascent-only over ~100 m bins, the
                    // maximum is the steepest sustained 100 m. See
                    // CatalogField::$derived, B-climbs.md and
                    // climb-elevation.md 4.
                    // Measured off the drawn line by ClimbProfiler, stored in
                    // metres, and — until 2026-08-09 — displayed NOWHERE: the
                    // drawer's elevation caption needs a GPX elevation array
                    // most climbs don't have, so the one number riders look
                    // for first on a climb card was invisible. The drawer
                    // converts it to the reader's unit (uElev), like every
                    // other height.
                    CatalogField::derivedText('gain', 'Ascent'),
                    CatalogField::derivedText('avgGradient', 'Average gradient (%)'),
                    // Still named for what it MEASURES, not for a point
                    // maximum: Mur de Huy's famous ~26% is its steepest
                    // hairpin, ours is the steepest sustained stretch, and
                    // saying "sustained" settles that apparent disagreement
                    // (owner, 2026-08-05).
                    //
                    // The WIDTH left the label on 2026-08-09, and could not
                    // have stayed. A msgid cannot be interpolated, so the
                    // number had to be retyped in five catalogues whenever
                    // ClimbProfiler::MAX_WINDOW_M moved — it went stale
                    // immediately (the window became 250 m on 2026-08-07 while
                    // the label still said 100 m, so the drawer read
                    // "steepest 250m 11%" above "Steepest 100m" for the same
                    // climb). It could not follow a rider reading in feet
                    // either. And it is not even a per-TYPE fact: every climb
                    // stores the window it was actually measured at
                    // (`steepWindowM`), and rows measured before the change
                    // really are 100 m ones.
                    //
                    // So the width travels with the VALUE now — the drawer
                    // writes "13% over 820 ft" from that climb's own
                    // steepWindowM, in the reader's unit
                    // (account-and-auth.md §9).
                    CatalogField::derivedText('maxGradient', 'Steepest sustained (%)'),
                    CatalogField::select('effort', 'Effort', ['Steady', 'Challenging', 'Tough', 'Very steep']),
                    CatalogField::textarea('correction', 'Anything to correct?', 'e.g. the foot starts at the bridge, not the square', display: false),
                ],
                addFields: [
                    CatalogField::select('waterOnClimb', 'Water on climb?', self::UNKNOWN_YES_NO),
                    CatalogField::text('hairpins', 'Hairpins (count)', placeholder: 'e.g. 3'),
                    CatalogField::select('shade', 'Shade / exposure', ['Unknown', 'Wooded', 'Exposed']),
                    CatalogField::text('famousFor', 'Famous for', placeholder: 'e.g. La Flèche Wallonne summit finish'),
                    CatalogField::text('approach', 'Approach', placeholder: 'e.g. From Sougné-Remouchamps (Aywaille)'),
                ],
            ),

            ItemType::WaterFood => new ItemFieldSet(
                fields: [
                    CatalogField::select('type', 'Type', ['Public fountain', 'Drinking tap', 'Cemetery tap', 'Café — refill point']),
                    CatalogField::select('potable', 'Potable?', ['Yes (public supply)', 'Unsigned — use judgement', 'No / non-potable']),
                    CatalogField::select('seasonal', 'Seasonal availability', ['Year-round', 'Summer only', 'Frost-shut in winter', 'Unknown']),
                    CatalogField::select('condition', 'Still as mapped?', self::CONDITION),
                    CatalogField::textarea('note', 'Note for riders', 'e.g. low flow, or hard to spot behind the church'),
                ],
                addFields: [
                    CatalogField::select('bottleFill', 'Bottle-fill friendly?', self::UNKNOWN_YES_NO),
                    CatalogField::select('cost', 'Cost', ['Free', 'Customers only']),
                    // Every letter whose pool the OSM harvest can fill with a
                    // website tag offers the field back (owner 2026-08-16: if
                    // the system fills data automatically, riders must be able
                    // to edit it).
                    CatalogField::url('web', 'Website', placeholder: 'https://… (the place’s own site)'),
                ],
            ),

            // M · Public toilets (2026-07-30): the utility neighbour of C —
            // surfaces order it right after Water & food even though the
            // letter skips over the reserved L (ride heatmap).
            ItemType::PublicToilets => new ItemFieldSet(
                fields: [
                    CatalogField::select('fee', 'Fee', ['Free', 'Paid']),
                    CatalogField::select('wheelchair', 'Wheelchair accessible?', self::UNKNOWN_YES_NO),
                    CatalogField::text('openingHours', 'Opening hours', placeholder: 'e.g. 24/7, or Apr–Oct daylight'),
                    CatalogField::select('condition', 'Still as mapped?', self::CONDITION),
                    CatalogField::textarea('note', 'Note for riders', 'e.g. behind the beach pavilion; code at the counter'),
                ],
                addFields: [
                    CatalogField::select('changingTable', 'Baby changing table?', self::UNKNOWN_YES_NO),
                    CatalogField::select('shower', 'Shower available?', self::UNKNOWN_YES_NO),
                    CatalogField::url('web', 'Website', placeholder: 'https://… (the place’s own site)'),
                ],
            ),

            ItemType::BikeServices => new ItemFieldSet(
                fields: [
                    CatalogField::text('name', 'Name', display: false),
                    // 'web' matches the Stays key so map.js renders the shared
                    // "Website" row; a shop/repair place usually has its own site.
                    CatalogField::url('web', 'Website', placeholder: 'https://… (the shop’s own site)'),
                    CatalogField::select('pumpValve', 'Pump valve', ['Presta + Schrader', 'Presta only', 'Schrader only', 'No pump']),
                    // Kind-specific default (docs/specs/osm-data-architecture.md §5): an
                    // unmanned station or pump is assumed 24/7 (preselected,
                    // overridable; some stations follow a host building's hours,
                    // e.g. inside a library). A staffed shop, and an unknown kind
                    // (null, legacy or untyped call sites), stays 'Unknown' until
                    // someone tells us.
                    CatalogField::select('openingHours', 'Opening hours', self::OPENING_HOURS, default: (null === $serviceKind || $serviceKind->hasOpeningHours()) ? 'Unknown' : '24/7'),
                    CatalogField::text('tools', 'Tools available', placeholder: 'e.g. chain tool, work stand'),
                    CatalogField::select('condition', 'Still as mapped?', self::CONDITION),
                    CatalogField::textarea('correction', 'Anything to correct?', "What's wrong or out of date?", display: false),
                ],
                addFields: [
                    CatalogField::select('workStand', 'Work stand?', self::UNKNOWN_YES_NO),
                    CatalogField::select('chainTool', 'Chain tool?', self::UNKNOWN_YES_NO),
                    CatalogField::select('ebikeCharging', 'E-bike charging?', self::UNKNOWN_YES_NO),
                ],
            ),

            ItemType::WhereToSleep => new ItemFieldSet(
                fields: [
                    CatalogField::text('name', 'Name', display: false),
                    CatalogField::text('town', 'Town / commune'),
                    // Keyed 'web', not 'website': 'web' is the shared vocabulary
                    // key every OSM-harvested stay already carries
                    // (AttributeVocabulary::COMMON), and the one osmDrawer already
                    // renders as the "Website" row in map.js. An edited value must
                    // land in the same key the drawer reads, or the edit is invisible.
                    CatalogField::url('web', 'Website', placeholder: 'https://… (the place’s own site)'),
                    CatalogField::select('bikeStorage', 'Secure bike storage', ['Yes — locked room', 'Yes — garage/shed', 'On request', 'No']),
                    CatalogField::select('dryingWashing', 'Drying / washing for kit', self::UNKNOWN_YES_NO),
                    CatalogField::url('bookingLink', 'Booking link', placeholder: 'https://… (booking platform, if any)'),
                    CatalogField::textarea('note', 'Note for riders', 'What makes it good for cyclists?'),
                ],
                addFields: [
                    CatalogField::select('pets', 'Pets allowed?', self::UNKNOWN_YES_NO),
                    CatalogField::select('meals', 'Meals / breakfast?', self::UNKNOWN_YES_NO),
                    CatalogField::select('toolsToBorrow', 'Tools to borrow?', self::UNKNOWN_YES_NO),
                    // Multi, not single: a stay is routinely step-free AND
                    // handbike-friendly, and a single select forced the rider
                    // to drop the rest — which is exactly the fact a rider who
                    // needs one of them is searching for. 'Unknown' is gone
                    // with the single select: nothing ticked already means
                    // "not stated", and an explicit Unknown alongside real
                    // values is unanswerable ("step-free and unknown"?).
                    CatalogField::multiselect('accessibility', 'Accessibility', ['Step-free access', 'Handbike-friendly', 'Wheelchair-accessible']),
                ],
            ),

            ItemType::Hazards => new ItemFieldSet(
                fields: [
                    // 'Road closed' is the one hazard with an end date, and
                    // the only reason `closedFor` below exists. Scout's Closure
                    // tag lands here too, so a tap on a bike computer and a
                    // typed report decay by the same rule (ClosureLifetime).
                    // Potholes / Junction / Bad corner come from Scout's NOTICE
                    // submenu (POTHOLES · CROSSING · CORNER · OTHER). They were
                    // missing, so every notice a rider tapped on the bars had to
                    // land on 'Other' and lose what they actually saw
                    // (owner 2026-08-12). They are ordinary hazards a typed
                    // report wants too — the device did not invent them.
                    CatalogField::select('hazardType', 'Hazard type', ['Crosswind / fog', 'Ice / frost', 'Loose surface / gravel', 'Potholes', 'Junction / crossing', 'Bad corner', 'Flooding', 'Roadworks', 'Road closed', 'Other']),
                    // Only meaningful when the type above is 'Road closed';
                    // ignored otherwise. Choices are Scout's CLOSED FOR? menu.
                    CatalogField::select('closedFor', 'If closed, for how long?', ClosureLifetime::CHOICES, default: 'Unknown'),
                    CatalogField::select('severity', 'Severity', ['Low', 'Moderate', 'High']),
                    CatalogField::select('worstWhen', 'When is it worst?', ['Autumn / winter', 'Year-round', 'After rain', 'Windy days']),
                    CatalogField::select('stillPresent', 'Still present?', ['Yes — confirmed today', 'Reduced', 'Gone — clear now']),
                    CatalogField::textarea('whatYouSaw', 'What did you see?', 'Describe the conditions'),
                ],
                addFields: [
                    CatalogField::text('detour', 'Alternative / detour', placeholder: 'e.g. drop to the valley road'),
                    CatalogField::select('timeOfDay', 'Time of day', ['Any', 'Morning', 'Afternoon', 'Evening']),
                ],
            ),

            ItemType::GettingThere => new ItemFieldSet(
                fields: [
                    CatalogField::select('bikesOnBoard', 'Bikes on board', ['Allowed with supplement', 'Allowed, free', 'Restricted at peak', 'Not allowed']),
                    CatalogField::select('stepFree', 'Step-free access', self::UNKNOWN_YES_NO),
                    CatalogField::select('bikeParking', 'Bike parking at station', ['Unknown', 'Covered racks', 'Open racks', 'None']),
                    CatalogField::select('condition', 'Still as mapped?', self::CONDITION_NO_PARTS),
                    CatalogField::textarea('note', 'Note for riders', 'e.g. which platform for the climbs'),
                ],
                addFields: [
                    CatalogField::select('liftRamp', 'Lift / ramp?', self::UNKNOWN_YES_NO),
                    CatalogField::select('bikeTicket', 'Bike ticket needed?', self::UNKNOWN_YES_NO),
                    CatalogField::url('web', 'Website', placeholder: 'https://… (the place’s own site)'),
                ],
            ),

            ItemType::Shelter => new ItemFieldSet(
                fields: [
                    CatalogField::select('shelterType', 'Shelter type', ['Refuge / chapel', 'Bus shelter', 'Café (seasonal)', 'Picnic hut']),
                    CatalogField::select('alwaysAccessible', 'Always accessible?', ['Yes — open structure', 'Daytime only', 'Seasonal', 'Unknown']),
                    CatalogField::select('waterNearby', 'Water nearby?', self::UNKNOWN_YES_NO),
                    CatalogField::select('condition', 'Still as mapped?', self::CONDITION_NO_PARTS),
                    CatalogField::textarea('note', 'Note for riders', 'How useful is it in bad weather?'),
                ],
                addFields: [
                    CatalogField::select('seating', 'Bench / seating?', self::UNKNOWN_YES_NO),
                    CatalogField::select('phoneSignal', 'Phone signal?', self::UNKNOWN_YES_NO),
                    CatalogField::url('web', 'Website', placeholder: 'https://… (the place’s own site)'),
                ],
            ),

            ItemType::ScenicViews => new ItemFieldSet(
                fields: [
                    CatalogField::text('name', 'Name', display: false),
                    CatalogField::select('type', 'Type', ['Viewpoint / high point', 'Monument', 'Heritage site', 'Nature reserve']),
                    CatalogField::select('bikeAccess', 'Access for bikes', ['Roadside', 'Short walk', 'Path only']),
                    CatalogField::text('whatYouSee', 'What can you see?'),
                    CatalogField::select('condition', 'Still as mapped?', self::CONDITION_NO_PARTS),
                    CatalogField::textarea('note', 'Description', 'A useful tip about this spot'),
                ],
                addFields: [
                    CatalogField::select('bestLight', 'Best light / time', ['Any', 'Morning', 'Golden hour', 'Sunset']),
                    CatalogField::select('bench', 'Bench?', self::UNKNOWN_YES_NO),
                    // The shared 'web' key (see WhereToSleep's note): a place
                    // other people wrote whole pages about should be able to
                    // point at its official one. ONE storage slot per fact -
                    // the official site is this field and never a `links`
                    // entry, which is why OutboundLinks refuses an entry
                    // labelled "Official site" (catalog-data-model.md §7).
                    CatalogField::url('web', 'Official site', placeholder: 'https://… (the place’s own site)'),
                    // Everything ELSE worth pointing at: the Wikipedia article,
                    // the heritage body, the tourist office. Rider-editable
                    // since 2026-08-16; it was import-only before, so a rider
                    // who knew the page had nowhere to put it.
                    CatalogField::links('links', 'Other pages about this place'),
                ],
            ),

            ItemType::HistoryCulture => new ItemFieldSet(
                fields: [
                    CatalogField::text('name', 'Name', display: false),
                    CatalogField::select('type', 'Type', ['Heritage site', 'Museum', 'Monument', 'Religious site']),
                    CatalogField::select('bikeParking', 'Bike parking', self::UNKNOWN_YES_NO),
                    CatalogField::select('condition', 'Still as mapped?', self::CONDITION_NO_PARTS),
                    CatalogField::textarea('note', 'Description', 'A useful tip about this spot'),
                ],
                addFields: [
                    CatalogField::select('openingHours', 'Opening hours', self::OPENING_HOURS, default: 'Unknown'),
                    CatalogField::select('entryFee', 'Entry fee?', ['Free', 'Paid', 'Unknown']),
                    CatalogField::text('cyclingStory', 'Cycling story / link', placeholder: 'A heritage note worth riding past for'),
                    // Same rationale as ScenicViews: the Muiderslot has an
                    // official castle site, and the drawer should say so.
                    CatalogField::url('web', 'Official site', placeholder: 'https://… (the place’s own site)'),
                    // And its Wikipedia article, and the heritage body's page.
                    // The Muiderslot example in catalog-data-model.md §7 is
                    // exactly this letter.
                    CatalogField::links('links', 'Other pages about this place'),
                ],
            ),

            ItemType::QualityRides => new ItemFieldSet(
                fields: [
                    CatalogField::text('rideName', 'Ride name', placeholder: 'e.g. Spa · Sankt Vith', display: false),
                    CatalogField::select('difficulty', 'Difficulty', array_values(DifficultyVocabulary::LABELS)),
                    // Keyed 'season', not 'bestSeason': 'season' is the key
                    // CatalogProvider::routes() already reads/serves as
                    // route.season and every imported/harvested route already carries.
                    // An edited value must land there or map.js's Season row (and the
                    // provider's forwarding) never sees it.
                    // Multi-select: pick any of the four; all four selected means the retired 'Any'.
                    CatalogField::multiselect('season', 'Best season', ['Spring', 'Summer', 'Autumn', 'Winter']),
                    // All surface types, shared with the A-layer field.
                    CatalogField::select('dominantSurface', 'Dominant surface', SurfaceVocabulary::DECLARABLE),
                    CatalogField::textarea('note', 'Note for riders', 'What is this loop like?'),
                ],
                addFields: [
                    CatalogField::select('quietness', 'Quietness rating (1–5)', ['1', '2', '3', '4', '5']),
                    CatalogField::select('scenic', 'Scenic rating (1–5)', ['1', '2', '3', '4', '5']),
                    CatalogField::select('friendliness', 'Cycling-friendliness (1–5)', ['1', '2', '3', '4', '5']),
                    // A route's suitable bike types is one list of BikeType
                    // values (BikeTypeVocabulary). Handbike is one of those
                    // values, picked here like any other, not a separate field.
                    CatalogField::multiselect('bikeTypes', 'Suitable bike types', BikeType::values()),
                    CatalogField::select('gradientLimited', 'Gradient-limited?', ['No', '≤6%', '≤9%']),
                    CatalogField::select('bestDirection', 'Best direction', ['Clockwise', 'Counter-clockwise', 'Either']),
                ],
            ),
        };
    }
}
