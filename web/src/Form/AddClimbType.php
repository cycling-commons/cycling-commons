<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Server-side form for the add-climb wizard.
 *
 * Maps the eleven input ids from atlas/demo/add-climb.html onto Symfony form
 * types. The geocoded location (lat/lng/place) is filled by client-side JS and
 * carried as hidden/text fields. CSRF protection is provided automatically.
 */
final class AddClimbType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fName', TextType::class, [
                'label' => false,
                'constraints' => [
                    new NotBlank(message: 'add_climb.error.name_required'),
                    new Length(max: 200, maxMessage: 'add_climb.error.name_too_long'),
                ],
            ])
            ->add('fOsm', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'Unknown' => 'Unknown',
                    'Yes' => 'Yes',
                    'No' => 'No',
                ],
                'required' => false,
            ])
            ->add('fLen', NumberType::class, [
                'label' => false,
                'required' => false,
                'scale' => 1,
                'constraints' => [
                    new PositiveOrZero(message: 'add_climb.error.length_negative'),
                ],
            ])
            ->add('fGain', NumberType::class, [
                'label' => false,
                'required' => false,
                'scale' => 0,
                'constraints' => [
                    new PositiveOrZero(message: 'add_climb.error.gain_negative'),
                ],
            ])
            ->add('fAvg', TextType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('fMax', NumberType::class, [
                'label' => false,
                'required' => false,
                'scale' => 1,
                'constraints' => [
                    new Range(
                        min: 0,
                        max: 100,
                        notInRangeMessage: 'add_climb.error.max_gradient_range',
                    ),
                ],
            ])
            ->add('fSurface', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'Asphalt' => 'Asphalt',
                    'Concrete' => 'Concrete',
                    'Gravel' => 'Gravel',
                    'Cobbles' => 'Cobbles',
                    'Mixed' => 'Mixed',
                ],
            ])
            ->add('fSurfaceQ', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'Smooth' => 'Smooth',
                    'Good' => 'Good',
                    'Worn' => 'Worn',
                    'Rough' => 'Rough',
                    'Broken / loose' => 'Broken / loose',
                ],
            ])
            ->add('fTraffic', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'Traffic-free' => 'Traffic-free',
                    'Quiet' => 'Quiet',
                    'Moderate' => 'Moderate',
                    'Busy' => 'Busy',
                ],
            ])
            ->add('fNote', TextareaType::class, [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'add_climb.error.note_too_long'),
                ],
            ])
            // Geocoded location carried as hidden fields (filled by client JS)
            ->add('lat', HiddenType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('lng', HiddenType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('place', HiddenType::class, [
                'label' => false,
                'required' => false,
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
