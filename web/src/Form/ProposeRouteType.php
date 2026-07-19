<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use App\Catalog\BikeType;
use App\Catalog\DifficultyVocabulary;
use App\Catalog\SurfaceVocabulary;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Rider route-proposal form (route-domain.md §4.1): the GPX file plus the
 * K metadata fields. Deep validation of the file content happens in
 * GpxParser/RouteProposalService. This form only gates size/extension.
 */
final class ProposeRouteType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('gpx', FileType::class, [
                'label' => false,
                'attr' => ['accept' => '.gpx'],
                'constraints' => [
                    new NotBlank(message: 'propose_route.error.gpx_required'),
                    new File(
                        maxSize: '15M',
                        maxSizeMessage: 'propose_route.error.gpx_too_large',
                        extensions: ['gpx' => ['application/gpx+xml', 'application/xml', 'text/xml', 'application/octet-stream', 'text/plain']],
                        extensionsMessage: 'propose_route.error.gpx_type',
                    ),
                ],
            ])
            ->add('rName', TextType::class, [
                'label' => false,
                'constraints' => [
                    new NotBlank(message: 'propose_route.error.name_required'),
                    new Length(max: 200, maxMessage: 'propose_route.error.name_too_long'),
                    CatalogFieldConstraints::noSuspiciousCharacters(),
                    new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters'),
                ],
            ])
            ->add('difficulty', ChoiceType::class, [
                'label' => false,
                'choices' => DifficultyVocabulary::choices(),
            ])
            // Best season is a multi-select (route-domain.md §9): choosing all
            // four means "any" season. Stored as a list<string> in
            // attributes.season; the drawer must still accept the legacy
            // scalar string on older routes.
            ->add('season', ChoiceType::class, [
                'label' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => ['Spring' => 'Spring', 'Summer' => 'Summer', 'Autumn' => 'Autumn', 'Winter' => 'Winter'],
            ])
            // Full surface vocabulary (route-domain.md §9), shared with the
            // A-layer vocabulary. Stored dominantSurface is always one of these
            // (form-validated; no route stores anything else — 'Mixed' is only
            // a coarse SurfaceVocabulary::BUCKETS output, never a stored value).
            ->add('dominantSurface', ChoiceType::class, [
                'label' => false,
                'choices' => array_combine(SurfaceVocabulary::DECLARABLE, SurfaceVocabulary::DECLARABLE),
            ])
            ->add('note', TextareaType::class, [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'propose_route.error.note_too_long'),
                    CatalogFieldConstraints::noSuspiciousCharacters(),
                    new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters'),
                ],
            ])
            ->add('bikeTypes', ChoiceType::class, [
                'label' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine(BikeType::values(), BikeType::values()),
            ])
            ->add('gradientLimited', ChoiceType::class, [
                'label' => false,
                'required' => false,
                // The labels are friendlier text, but the stored values must
                // stay 'No'/'≤6%'/'≤9%' to avoid a data migration. "Whole
                // route ≤ X%" is an accessibility guarantee for riders who
                // need that cap.
                'choices' => ['No cap' => 'No', 'Whole route ≤ 6%' => '≤6%', 'Whole route ≤ 9%' => '≤9%'],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
