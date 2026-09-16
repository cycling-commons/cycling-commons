<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Form;

use App\Catalog\BikeType;
use App\Catalog\DifficultyVocabulary;
use App\Catalog\Season;
use App\Catalog\SurfaceVocabulary;
use App\Moderation\RouteProposalDetails;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Route-proposal form: GPX plus R metadata plus rider photos. File content is
 * validated later.
 *
 * With `route_photos` it is the same page's form for a live route: photos and
 * a note, nothing else, because riders never edit a route's data
 * (route-domain.md §1).
 *
 * @see docs/specs/route-domain.md §4.1, §4.5
 * @see docs/specs/photo-uploads.md §5i
 */
final class ProposeRouteType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Upload ids from media-upload.js, claimed on submit (photo-uploads.md §4, §5i).
        $builder
            ->add('mediaIds', HiddenType::class, ['required' => false])
            ->add('mediaAlts', HiddenType::class, ['required' => false])
            ->add('note', TextareaType::class, [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'propose_route.error.note_too_long'),
                    CatalogFieldConstraints::noSuspiciousCharacters(),
                    new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters'),
                ],
            ]);
        if (true === $options['route_photos']) {
            return;
        }

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
            ->add('season', ChoiceType::class, [
                'label' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                // Stored values stay capitalized (route-domain.md §9); labels are the seasons' names.
                'choices' => ['Spring' => 'Spring', 'Summer' => 'Summer', 'Autumn' => 'Autumn', 'Winter' => 'Winter'],
                'choice_label' => static fn (string $s): string => Season::from(strtolower($s))->labelKey(),
            ])
            ->add('dominantSurface', ChoiceType::class, [
                'label' => false,
                'choices' => array_combine(SurfaceVocabulary::DECLARABLE, SurfaceVocabulary::DECLARABLE),
            ])
            ->add('bikeTypes', ChoiceType::class, [
                'label' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine(BikeType::values(), BikeType::values()),
                'choice_label' => static fn (string $b): string => BikeType::from($b)->labelKey(),
            ])
            ->add('gradientLimited', ChoiceType::class, [
                'label' => false,
                'required' => false,
                // Stored values stay 'No'/'≤6%'/'≤9%' (docs/specs/route-domain.md §9).
                'choices' => array_combine(array_keys(RouteProposalDetails::GRADIENT_LABELS), array_keys(RouteProposalDetails::GRADIENT_LABELS)),
                'choice_label' => static fn (string $g): string => RouteProposalDetails::GRADIENT_LABELS[$g],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'route_photos' => false,
        ]);
        $resolver->setAllowedTypes('route_photos', 'bool');
    }
}
