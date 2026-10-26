<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Geofabrik extracts of regions planned for a live country. app:country:apply moves them to country_extract, so the harvest reads them only once their regions exist.
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'country_plan_extract')]
class CountryPlanExtract
{
    /** Geofabrik region path, e.g. north-america/us/arizona. */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 100)]
    private string $slug = '';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 2, options: ['fixed' => true])]
    private string $countryCode = '';

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }
}
