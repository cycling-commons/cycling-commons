<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Form;

use App\Account\DateFormat;
use App\Account\DistanceUnit;
use App\Account\ElevationUnit;
use App\Account\RowsPerPage;
use App\Account\TimeFormat;
use App\Catalog\BikeType;
use App\Catalog\MapTheme;
use App\Catalog\MapViewMode;
use App\Catalog\RidingStyle;
use App\Entity\User;
use App\World\Entity\Country;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

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
                // Only the "a human must supply one" half lives here. What a
                // name may LOOK like (length ceiling, no confusables, no
                // invisibles, plain spacing) is on the User entity, so admin
                // CRUD and console paths are held to it too — see
                // account-and-auth.md §9.
                'constraints' => [
                    new NotBlank(message: 'Please enter a display name.'),
                    new Length(min: 2, minMessage: 'Display name must be at least {{ limit }} characters.'),
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
            // Base location (map-and-search.md §4.5): town pick + radius,
            // unmapped — the controller reads these raw and calls
            // BaseLocationService::apply()/clear() before flush. baseLat/baseLng/
            // basePlace are filled by base-location.js from a Photon pick; the
            // pin-drop path lives on the map page, not here.
            ->add('baseQuery', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'form.label_base_location',
                'help' => 'form.help_base_location',
                'attr' => [
                    'autocomplete' => 'off',
                    'data-base-query' => '1',
                    'placeholder' => 'form.placeholder_base_location',
                ],
            ])
            ->add('baseLat', HiddenType::class, ['mapped' => false, 'required' => false])
            ->add('baseLng', HiddenType::class, ['mapped' => false, 'required' => false])
            ->add('basePlace', HiddenType::class, ['mapped' => false, 'required' => false])
            ->add('baseRadiusKm', RangeType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'form.label_base_radius',
                'attr' => [
                    'min' => User::BASE_RADIUS_MIN,
                    'max' => User::BASE_RADIUS_MAX,
                    'step' => 5,
                    'data-base-radius' => '1',
                ],
            ])
            ->add('baseClear', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'form.label_base_clear',
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'form.label_language',
                'required' => false,
                'placeholder' => 'form.ph_language',
                // Endonyms: the same in every locale, so keep them out of the translator.
                'choices' => [
                    'English' => 'en',
                    'Français' => 'fr',
                    'Nederlands' => 'nl',
                    'Deutsch' => 'de',
                    'Español' => 'es',
                ],
                'choice_translation_domain' => false,
            ])
            // Its own field rather than a consequence of `locale`: reading the
            // site in English says nothing about wanting 2026-08-01 over
            // 01-08-2026 (account-and-auth.md §9).
            ->add('dateFormat', EnumType::class, [
                'class' => DateFormat::class,
                'label' => 'form.label_date_format',
                'help' => 'form.help_date_format',
                'required' => true,
                'choice_label' => static fn (DateFormat $f): string => $f->labelKey(),
            ])
            ->add('timeFormat', EnumType::class, [
                'class' => TimeFormat::class,
                'label' => 'form.label_time_format',
                'help' => 'form.help_time_format',
                'required' => true,
                'choice_label' => static fn (TimeFormat $f): string => $f->labelKey(),
            ])
            // Two dropdowns rather than one metric/imperial switch: miles with
            // metres of climbing is a real combination, and asking those riders
            // to accept feet to get miles is the kind of tidy reasoning that is
            // wrong about actual people (same argument as date vs time above).
            ->add('distanceUnit', EnumType::class, [
                'class' => DistanceUnit::class,
                'label' => 'form.label_distance_unit',
                'help' => 'form.help_distance_unit',
                'required' => true,
                'choice_label' => static fn (DistanceUnit $u): string => $u->labelKey(),
            ])
            ->add('elevationUnit', EnumType::class, [
                'class' => ElevationUnit::class,
                'label' => 'form.label_elevation_unit',
                'help' => 'form.help_elevation_unit',
                'required' => true,
                'choice_label' => static fn (ElevationUnit $u): string => $u->labelKey(),
            ])
            // One preference for every paged list in the application. `Auto`
            // is not a single number: each list keeps the size it was designed
            // around, because a message is a card and a wall row is one line.
            ->add('rowsPerPage', EnumType::class, [
                'class' => RowsPerPage::class,
                'label' => 'form.label_rows_per_page',
                'help' => 'form.help_rows_per_page',
                'required' => true,
                'choice_label' => static fn (RowsPerPage $r): string => $r->labelKey(),
            ])
            // Rider preferences (account-and-auth.md §9). EnumType hands the
            // entity setters real enum instances. Bike-type labels reuse the
            // map's existing vocabulary keys (already translated in all 4 locales).
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
            ->add('defaultMapMode', EnumType::class, [
                'class' => MapViewMode::class,
                'label' => 'form.label_map_mode',
                'help' => 'form.help_map_mode',
                'required' => true,
                'choice_label' => static fn (MapViewMode $m): string => match ($m) {
                    MapViewMode::Auto => 'form.map_mode_auto',
                    MapViewMode::Everything => 'form.map_mode_everything',
                    MapViewMode::Confirmed => 'form.map_mode_confirmed',
                    MapViewMode::Curated => 'form.map_mode_curated',
                },
            ])
            ->add('mapTheme', EnumType::class, [
                'class' => MapTheme::class,
                'label' => 'form.label_map_theme',
                'help' => 'form.help_map_theme',
                'required' => true,
                'choice_label' => static fn (MapTheme $t): string => match ($t) {
                    MapTheme::Dark => 'form.map_theme_dark',
                    MapTheme::Light => 'form.map_theme_light',
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
