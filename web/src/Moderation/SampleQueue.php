<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * Static sample queue for the moderation review surface.
 *
 * TODO(data-api): replace sample queue with real submissions from the data API.
 *
 * @api Read by ModerateController; same data asserted in Moderation/ModerateTest.
 */
final class SampleQueue
{
    /**
     * Returns the sample pending submissions.
     *
     * Each item is a map with:
     *   id       int
     *   type     string  new|edit|hazard|photo
     *   title    string
     *   lat      float
     *   lng      float
     *   who      string  anonymised contributor handle
     *   when     string  human-readable age
     *   body     string  (optional) prose description
     *   was      string  (optional) previous value for edit items
     *   now      string  (optional) new value for edit items
     *
     * @return list<array{id:int,type:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string}>
     */
    public static function items(): array
    {
        return [
            [
                'id' => 1,
                'type' => 'new',
                'title' => 'Côte de la Vecquée',
                'lat' => 50.47,
                'lng' => 5.86,
                'who' => 'rider#4f2a',
                'when' => '2h ago',
                'body' => 'New climb · 3.1 km · avg 6.4% · paved. Submitted with gradient profile.',
                'was' => '',
                'now' => '',
            ],
            [
                'id' => 2,
                'type' => 'hazard',
                'title' => 'Road closure · N66',
                'lat' => 50.42,
                'lng' => 5.80,
                'who' => 'rider#9c01',
                'when' => '3h ago',
                'body' => 'Reported closed for roadworks. Suggested detour via N645.',
                'was' => '',
                'now' => '',
            ],
            [
                'id' => 3,
                'type' => 'edit',
                'title' => 'Repair station · Malmedy',
                'lat' => 50.426,
                'lng' => 6.027,
                'who' => 'rider#1ab8',
                'when' => '5h ago',
                'body' => '',
                'was' => 'Hours: 24/7',
                'now' => 'Hours: closed Sundays',
            ],
            [
                'id' => 4,
                'type' => 'photo',
                'title' => 'Signal de Botrange — viewpoint',
                'lat' => 50.501,
                'lng' => 6.094,
                'who' => 'rider#7d33',
                'when' => '1d ago',
                'body' => 'Photo submitted (CC BY-SA 4.0, own work). Awaiting check for provenance.',
                'was' => '',
                'now' => '',
            ],
            [
                'id' => 5,
                'type' => 'new',
                'title' => 'Gîte des Fagnes',
                'lat' => 50.49,
                'lng' => 6.05,
                'who' => 'rider#2e55',
                'when' => '1d ago',
                'body' => 'New bike-friendly stay · secure storage · drying room · packed breakfast.',
                'was' => '',
                'now' => '',
            ],
            [
                'id' => 6,
                'type' => 'edit',
                'title' => 'Fountain · Spa centre',
                'lat' => 50.4925,
                'lng' => 5.8639,
                'who' => 'rider#88fa',
                'when' => '2d ago',
                'body' => '',
                'was' => 'Potable: yes',
                'now' => 'Potable: seasonal (frost shut-off)',
            ],
        ];
    }
}
