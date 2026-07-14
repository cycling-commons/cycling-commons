<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Form;

use App\Catalog\BikeType;
use App\Catalog\RidingStyle;
use App\Entity\User;
use App\World\Entity\Country;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Settings form for display name and public-profile toggle.
 */
final class SettingsType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'form.label_display_name',
                'attr' => [
                    'autocomplete' => 'name',
                    'placeholder' => 'form.ph_display_name_settings',
                ],
                'constraints' => [
                    new NotBlank(message: 'Please enter a display name.'),
                    new Length(
                        max: 100,
                        maxMessage: 'Display name may not exceed {{ limit }} characters.',
                    ),
                    // Same invisible-character guard applied to every user text
                    // field (a lone U+200B etc. passes NoSuspiciousCharacters).
                    CatalogFieldConstraints::noSuspiciousCharacters(),
                    new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters'),
                ],
            ])
            ->add('country', EntityType::class, [
                'class' => Country::class,
                'required' => false,
                'label' => 'form.label_country',
                'placeholder' => 'form.ph_country',
                'choice_label' => 'name',
                // Group the options under their continent.
                'group_by' => static fn (Country $c): ?string => $c->getContinent()?->getName(),
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('c')
                    ->leftJoin('c.continent', 'cont')->addSelect('cont')
                    ->orderBy('c.name', 'ASC'),
                'attr' => ['autocomplete' => 'country-name'],
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'form.label_language',
                'required' => false,
                'placeholder' => 'form.ph_language',
                // Endonyms — the same in every locale, so keep them out of the translator.
                'choices' => [
                    'English' => 'en',
                    'Français' => 'fr',
                    'Nederlands' => 'nl',
                    'Deutsch' => 'de',
                ],
                'choice_translation_domain' => false,
            ])
            // Rider preferences (spec 2026-07-14). EnumType hands the entity
            // setters real enum instances. Bike-type labels reuse the map's
            // existing vocabulary keys (already translated in all 4 locales).
            ->add('bikeTypes', EnumType::class, [
                'class' => BikeType::class,
                'label' => 'form.label_bike_types',
                'help' => 'form.help_bike_types',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choice_label' => static fn (BikeType $t): string => match ($t) {
                    BikeType::Road => 'map.bike_road',
                    BikeType::Gravel => 'map.bike_gravel',
                    BikeType::Mtb => 'map.bike_mtb',
                    BikeType::Ebike => 'map.bike_ebike',
                    BikeType::Handbike => 'map.bike_handbike',
                    BikeType::Recumbent => 'map.bike_recumbent',
                    BikeType::Trike => 'map.bike_trike',
                    BikeType::Tandem => 'map.bike_tandem',
                },
            ])
            ->add('ridingStyles', EnumType::class, [
                'class' => RidingStyle::class,
                'label' => 'form.label_riding_styles',
                'help' => 'form.help_riding_styles',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choice_label' => static fn (RidingStyle $s): string => match ($s) {
                    RidingStyle::Road => 'form.riding_road',
                    RidingStyle::Gravel => 'form.riding_gravel',
                    RidingStyle::Touring => 'form.riding_touring',
                    RidingStyle::Bikepacking => 'form.riding_bikepacking',
                    RidingStyle::Trail => 'form.riding_trail',
                    RidingStyle::Urban => 'form.riding_urban',
                    RidingStyle::Leisure => 'form.riding_leisure',
                },
            ])
            ->add('publicProfile', CheckboxType::class, [
                'label' => 'form.label_public_profile',
                'required' => false,
                'help' => 'form.help_public_profile',
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
