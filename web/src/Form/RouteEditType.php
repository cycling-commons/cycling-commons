<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Form;

use App\Catalog\RouteMetadata;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The curator's metadata form on a route's desk page: every editorial field a
 * route carries, from the same definitions the rider's proposal form uses
 * ({@see RouteMetadataFields}).
 *
 * Each field is offered empty and may be sent back empty; an empty field
 * clears the attribute rather than storing a blank
 * ({@see \App\Moderation\RouteModerationService::editMetadata()}), and every
 * change lands in `route_change_history` with the curator as its author, the
 * way the note already did.
 *
 * @see docs/specs/edit-items/R-quality-rides.md
 * @see docs/specs/route-domain.md §5.1
 */
final class RouteEditType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('route_id', HiddenType::class);
        RouteMetadataFields::add(
            $builder,
            [RouteMetadata::NAME_FIELD, ...RouteMetadata::ATTRIBUTE_FIELDS],
            required: false,
        );
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'route_edit',
        ]);
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'route_edit';
    }
}
