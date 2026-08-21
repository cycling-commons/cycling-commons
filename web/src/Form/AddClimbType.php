<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use App\Account\UnitFormatter;
use App\Catalog\CatalogFormRegistry;
use App\Catalog\ItemType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
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
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Add-climb wizard form.
 *
 * @see docs/specs/edit-items/B-climbs.md
 *
 * @api
 */
final class AddClimbType extends AbstractType
{
    public function __construct(
        private readonly CatalogFormRegistry $registry,
        private readonly UnitFormatter $units,
    ) {
    }

    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Surface/quality/traffic choices come from the shared Climbs registry.
        $climbFields = [];
        foreach ($this->registry->for(ItemType::Climbs)->all() as $field) {
            $climbFields[$field->name] = array_combine($field->choices, $field->choices);
        }
        /** @var array{surface: array<string,string>, sq: array<string,string>, tr: array<string,string>} $climbChoices */
        $climbChoices = $climbFields;

        $builder
            ->add('fName', TextType::class, [
                'label' => false,
                'constraints' => [
                    new NotBlank(message: 'add_climb.error.name_required'),
                    new Length(max: 200, maxMessage: 'add_climb.error.name_too_long'),
                    CatalogFieldConstraints::noSuspiciousCharacters(),
                    new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters'),
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
                // Text so the editor's "%" round-trips (docs/specs/edit-items/B-climbs.md).
                'constraints' => [
                    new Length(max: 8, maxMessage: 'add_climb.error.avg_gradient_invalid'),
                    new Regex(
                        pattern: '/^\d{1,2}([.,]\d{1,2})?\s*%?$/',
                        message: 'add_climb.error.avg_gradient_invalid',
                    ),
                ],
            ])
            ->add('fSurface', ChoiceType::class, [
                'label' => false,
                'choices' => $climbChoices['surface'],
            ])
            ->add('fSurfaceQ', ChoiceType::class, [
                'label' => false,
                'choices' => $climbChoices['sq'],
            ])
            ->add('fTraffic', ChoiceType::class, [
                'label' => false,
                'choices' => $climbChoices['tr'],
            ])
            ->add('fNote', TextareaType::class, [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'add_climb.error.note_too_long'),
                    CatalogFieldConstraints::noSuspiciousCharacters(),
                    new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters'),
                ],
            ])
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
            ->add('route', HiddenType::class, ['label' => false, 'required' => false])
            ->add('grad', HiddenType::class, ['label' => false, 'required' => false])
            ->add('steep', HiddenType::class, ['label' => false, 'required' => false])
            ->add('avg', HiddenType::class, ['label' => false, 'required' => false])
            ->add('steepPoint', HiddenType::class, ['label' => false, 'required' => false])
        ;

        // Typed length/gain: convert display units back to km/m (docs/specs/account-and-auth.md §9).
        $builder->get('fLen')->addModelTransformer(new CallbackTransformer(
            fn (mixed $km): ?float => is_numeric($km) ? $this->units->distanceValue((float) $km, 1) : null,
            fn (mixed $shown): ?float => is_numeric($shown)
                ? $this->units->distanceUnit()->toKm((float) $shown)
                : null,
        ));
        $builder->get('fGain')->addModelTransformer(new CallbackTransformer(
            fn (mixed $metres): ?float => is_numeric($metres) ? $this->units->elevationValue((float) $metres) : null,
            fn (mixed $shown): ?float => is_numeric($shown)
                ? round($this->units->elevationUnit()->toMetres((float) $shown))
                : null,
        ));
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
