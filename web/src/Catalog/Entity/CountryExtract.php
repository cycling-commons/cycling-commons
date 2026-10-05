<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A Geofabrik extract a country is harvested from, onboarded while its country is seeded or live.
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'country_extract')]
#[ORM\Index(name: 'idx_country_extract_country', columns: ['country_code'])]
class CountryExtract
{
    /** Geofabrik region path, e.g. europe/denmark. */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 100)]
    private string $slug = '';

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
