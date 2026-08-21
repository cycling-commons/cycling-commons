<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Per-type field schemas for the improve wizard.
 *
 * @see docs/specs/edit-items/README.md
 *
 * @api
 */
final class CatalogFormRegistry
{
    private const array UNKNOWN_YES_NO = ['Unknown', 'Yes', 'No'];

    /**
     * Shared condition vocabulary. No default: silence means nobody has said.
     *
     * @see docs/specs/catalog-data-model.md §7
     */
    private const array CONDITION = ['As mapped', 'Out of order', 'Closed', 'Not there anymore'];

    /** Same values as CONDITION minus 'Out of order'. Keep in step with `CC_BREAKABLE`. */
    private const array CONDITION_NO_PARTS = ['As mapped', 'Closed', 'Not there anymore'];

    /** Not free text: we only record what stays true (24/7 or "see website"). */
    private const array OPENING_HOURS = ['Unknown', '24/7', 'See website'];

    public function for(ItemType $type, ?ServiceKind $serviceKind = null): ItemFieldSet
    {
        return match ($type) {
            ItemType::RoadSurface => new ItemFieldSet(
                fields: [
                    CatalogField::select('surface', 'Surface', SurfaceVocabulary::DECLARABLE),
                    // Rider words, not OSM `highway=` — RoadType owns the mapping.
                    CatalogField::select('roadType', 'Road type', RoadType::DECLARABLE),
                    CatalogField::select('smoothness', 'Smoothness', ['Excellent', 'Good', 'Intermediate', 'Bad', 'Very bad']),
                    CatalogField::select('traffic', 'Traffic', ['Quiet', 'Moderate', 'Busy', 'Car-free']),
                    CatalogField::select('segregated', 'Segregated from cars?', self::UNKNOWN_YES_NO),
                ],
                addFields: [
                    CatalogField::select('lit', 'Lit at night?', self::UNKNOWN_YES_NO),
                    CatalogField::select('seasonalClosure', 'Seasonal closure?', ['None', 'Winter', 'Forestry work']),
                    CatalogField::text('width', 'Width (m)'),
                    CatalogField::textarea('note', 'Note', 'e.g. resurfaced in 2025, or pavé through the village'),
                ],
            ),

            ItemType::Climbs => new ItemFieldSet(
                fields: [
                    CatalogField::text('name', 'Name', display: false),
                    CatalogField::select('surface', 'Surface', ['Smooth asphalt', 'Asphalt', 'Worn asphalt', 'Cobbles', 'Gravel']),
                    CatalogField::select('sq', 'Road quality', ['Smooth', 'Good', 'Worn', 'Rough', 'Broken / loose']),
                    CatalogField::select('tr', 'Traffic', ['Traffic-free', 'Quiet', 'Moderate', 'Busy']),
                    // docs/specs/climb-elevation.md §4 — measured, never typed.
                    CatalogField::derivedText('gain', 'Ascent'),
                    CatalogField::derivedText('avgGradient', 'Average gradient (%)'),
                    // Steepest sustained stretch, not a point maximum. Window lives on the value (`steepWindowM`), not the label.
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
                    CatalogField::url('web', 'Website', placeholder: 'https://… (the place’s own site)'),
                ],
            ),

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
                    CatalogField::url('web', 'Website', placeholder: 'https://… (the shop’s own site)'),
                    CatalogField::select('pumpValve', 'Pump valve', ['Presta + Schrader', 'Presta only', 'Schrader only', 'No pump']),
                    // docs/specs/osm-data-architecture.md §5 — unmanned station/pump defaults to 24/7; a shop stays Unknown.
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
                    // Keyed `web`, not `website` — same slot the drawer and harvest already use.
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
                    // Multi: a stay is routinely several of these; nothing ticked means "not stated".
                    CatalogField::multiselect('accessibility', 'Accessibility', ['Step-free access', 'Handbike-friendly', 'Wheelchair-accessible']),
                ],
            ),

            ItemType::Hazards => new ItemFieldSet(
                fields: [
                    // docs/specs/edit-items/F-hazards.md (Closures expire themselves) — Scout NOTICE types too.
                    CatalogField::select('hazardType', 'Hazard type', ['Crosswind / fog', 'Ice / frost', 'Loose surface / gravel', 'Potholes', 'Junction / crossing', 'Bad corner', 'Flooding', 'Roadworks', 'Road closed', 'Other']),
                    // Only when hazardType is 'Road closed'.
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
                    // docs/specs/catalog-data-model.md §7 — official site is `web`, never a `links` "Official site" entry.
                    CatalogField::url('web', 'Official site', placeholder: 'https://… (the place’s own site)'),
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
                    CatalogField::url('web', 'Official site', placeholder: 'https://… (the place’s own site)'),
                    CatalogField::links('links', 'Other pages about this place'),
                ],
            ),

            ItemType::QualityRides => new ItemFieldSet(
                fields: [
                    CatalogField::text('rideName', 'Ride name', placeholder: 'e.g. Spa · Sankt Vith', display: false),
                    CatalogField::select('difficulty', 'Difficulty', array_values(DifficultyVocabulary::LABELS)),
                    // Keyed `season` so CatalogProvider and map.js see it. All four selected is the retired 'Any'.
                    CatalogField::multiselect('season', 'Best season', ['Spring', 'Summer', 'Autumn', 'Winter']),
                    CatalogField::select('dominantSurface', 'Dominant surface', SurfaceVocabulary::DECLARABLE),
                    CatalogField::textarea('note', 'Note for riders', 'What is this loop like?'),
                ],
                addFields: [
                    CatalogField::select('quietness', 'Quietness rating (1–5)', ['1', '2', '3', '4', '5']),
                    CatalogField::select('scenic', 'Scenic rating (1–5)', ['1', '2', '3', '4', '5']),
                    CatalogField::select('friendliness', 'Cycling-friendliness (1–5)', ['1', '2', '3', '4', '5']),
                    CatalogField::multiselect('bikeTypes', 'Suitable bike types', BikeType::values()),
                    CatalogField::select('gradientLimited', 'Gradient-limited?', ['No', '≤6%', '≤9%']),
                    CatalogField::select('bestDirection', 'Best direction', ['Clockwise', 'Counter-clockwise', 'Either']),
                ],
            ),
        };
    }
}
