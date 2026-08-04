<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\ItemType;
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
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Server-side form for the add-climb wizard.
 *
 * Maps the eleven input ids from atlas/demo/add-climb.html onto Symfony form
 * types. The geocoded location (lat/lng/place) is filled by client-side JS and
 * carried as hidden/text fields. CSRF protection is provided automatically.
 *
 * @see docs/specs/edit-items/B-climbs.md
 *
 * @api Instantiated by Symfony's form factory.
 */
final class AddClimbType extends AbstractType
{
    public function __construct(private readonly CatalogFormRegistry $registry)
    {
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
                // Average gradient: a bare number, optionally with a decimal
                // and/or trailing '%' (e.g. "6.4" or "8%"). Kept as text (not
                // NumberType) so the editor's "%" affordance round-trips. The
                // Length and Regex constraints below keep the stored value
                // bounded before it reaches the published attributes.
                'constraints' => [
                    new Length(max: 8, maxMessage: 'add_climb.error.avg_gradient_invalid'),
                    new Regex(
                        pattern: '/^\d{1,2}([.,]\d{1,2})?\s*%?$/',
                        message: 'add_climb.error.avg_gradient_invalid',
                    ),
                ],
            ])
            // No max-gradient input: it is read off the steepest-ramp marker the
            // rider places in step 1 (CatalogField::derived). A text box beside
            // that marker is a second source for one fact, and accepts anything.
            // Climb surface/quality/traffic vocabularies come from the ONE
            // registry (Climbs surface/sq/tr fields), so add-climb and the
            // improve form can never store divergent values for the same
            // attribute.
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
            // Climb shape drawn by the three-point editor (client JS), carried
            // as JSON. Validated + decoded server-side by ClimbGeometry.
            ->add('route', HiddenType::class, ['label' => false, 'required' => false])
            ->add('grad', HiddenType::class, ['label' => false, 'required' => false])
            ->add('steep', HiddenType::class, ['label' => false, 'required' => false])
            // Ascent-only average, computed by the editor from the drawn line.
            // Derived, never typed: climb-elevation.md 4.
            ->add('avg', HiddenType::class, ['label' => false, 'required' => false])
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
