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
                    CatalogField::select('smoothness', 'Smoothness', ['Excellent', 'Good', 'Intermediate', 'Bad', 'Very bad']),
                    CatalogField::text('width', 'Width (m)', default: '3.0'),
                    CatalogField::select('traffic', 'Traffic', ['Quiet', 'Moderate', 'Busy', 'Car-free (RAVeL)']),
                    CatalogField::textarea('note', 'Note', 'e.g. resurfaced in 2025, or pavé through the village'),
                ],
                addFields: [
                    CatalogField::select('lit', 'Lit at night?', self::UNKNOWN_YES_NO),
                    CatalogField::select('segregated', 'Segregated from cars?', self::UNKNOWN_YES_NO),
                    CatalogField::select('seasonalClosure', 'Seasonal closure?', ['None', 'Winter', 'Forestry']),
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
                    CatalogField::text('avgGradient', 'Average gradient (%)'),
                    CatalogField::text('maxGradient', 'Max gradient (%)'),
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
                    CatalogField::textarea('note', 'Note for riders', 'e.g. low flow, or hard to spot behind the church'),
                ],
                addFields: [
                    CatalogField::select('bottleFill', 'Bottle-fill friendly?', self::UNKNOWN_YES_NO),
                    CatalogField::select('cost', 'Cost', ['Free', 'Customers only']),
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
                    CatalogField::select('accessibility', 'Accessibility', ['Step-free access', 'Handbike-friendly', 'Wheelchair-accessible', 'Unknown']),
                ],
            ),

            ItemType::Hazards => new ItemFieldSet(
                fields: [
                    CatalogField::select('hazardType', 'Hazard type', ['Crosswind / fog', 'Ice / frost', 'Loose surface / gravel', 'Flooding', 'Roadworks', 'Other']),
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
                    CatalogField::textarea('note', 'Note for riders', 'e.g. which platform for the climbs'),
                ],
                addFields: [
                    CatalogField::select('liftRamp', 'Lift / ramp?', self::UNKNOWN_YES_NO),
                    CatalogField::select('bikeTicket', 'Bike ticket needed?', self::UNKNOWN_YES_NO),
                ],
            ),

            ItemType::Shelter => new ItemFieldSet(
                fields: [
                    CatalogField::select('shelterType', 'Shelter type', ['Refuge / chapel', 'Bus shelter', 'Café (seasonal)', 'Picnic hut']),
                    CatalogField::select('alwaysAccessible', 'Always accessible?', ['Yes — open structure', 'Daytime only', 'Seasonal', 'Unknown']),
                    CatalogField::select('waterNearby', 'Water nearby?', self::UNKNOWN_YES_NO),
                    CatalogField::textarea('note', 'Note for riders', 'How useful is it in bad weather?'),
                ],
                addFields: [
                    CatalogField::select('seating', 'Bench / seating?', self::UNKNOWN_YES_NO),
                    CatalogField::select('phoneSignal', 'Phone signal?', self::UNKNOWN_YES_NO),
                ],
            ),

            ItemType::ScenicViews => new ItemFieldSet(
                fields: [
                    CatalogField::text('name', 'Name', display: false),
                    CatalogField::select('type', 'Type', ['Viewpoint / high point', 'Monument', 'Heritage site', 'Nature reserve']),
                    CatalogField::select('bikeAccess', 'Access for bikes', ['Roadside', 'Short walk', 'Path only']),
                    CatalogField::text('whatYouSee', 'What can you see?'),
                    CatalogField::textarea('note', 'Anything to add?', 'A useful tip about this spot'),
                ],
                addFields: [
                    CatalogField::select('bestLight', 'Best light / time', ['Any', 'Morning', 'Golden hour', 'Sunset']),
                    CatalogField::select('bench', 'Bench?', self::UNKNOWN_YES_NO),
                ],
            ),

            ItemType::HistoryCulture => new ItemFieldSet(
                fields: [
                    CatalogField::text('name', 'Name', display: false),
                    CatalogField::select('type', 'Type', ['Heritage site', 'Museum', 'Monument', 'Religious site']),
                    CatalogField::select('bikeParking', 'Bike parking', self::UNKNOWN_YES_NO),
                    CatalogField::textarea('note', 'Anything to add?', 'A useful tip about this spot'),
                ],
                addFields: [
                    CatalogField::select('openingHours', 'Opening hours', self::OPENING_HOURS, default: 'Unknown'),
                    CatalogField::select('entryFee', 'Entry fee?', ['Free', 'Paid', 'Unknown']),
                    CatalogField::text('cyclingStory', 'Cycling story / link', placeholder: 'A heritage note worth riding past for'),
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
                    // A route's suitable bike types is one canonical list<string>
                    // over BikeType::values() (BikeTypeVocabulary). The retired 'Any'
                    // and the separate 'handbike' Yes/No field both fold into this list
                    // rather than staying independent attributes.
                    CatalogField::multiselect('bikeTypes', 'Suitable bike types', BikeType::values()),
                    CatalogField::select('gradientLimited', 'Gradient-limited?', ['No', '≤6%', '≤9%']),
                    CatalogField::select('bestDirection', 'Best direction', ['Clockwise', 'Counter-clockwise', 'Either']),
                ],
            ),
        };
    }
}
