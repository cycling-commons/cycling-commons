<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Account\DatePreference;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * A date on an admin screen reads the way it does everywhere else: in the
 * viewer's own format. EasyAdmin's own field takes the locale's, which is a
 * second answer to a question the rider already answered in their settings.
 *
 * @see docs/specs/account-and-auth.md §9
 */
trait RiderDatedFields
{
    private DatePreference $riderDates;

    #[Required]
    public function setRiderDates(DatePreference $riderDates): void
    {
        $this->riderDates = $riderDates;
    }

    /** A DateTimeField in the viewer's format, or the locale's when they chose Auto. */
    private function riderDateTime(string $property, ?string $label = null): DateTimeField
    {
        $field = DateTimeField::new($property, $label);
        $pattern = $this->riderDates->dateTimePattern();

        return null === $pattern ? $field : $field->setFormat($pattern);
    }
}
