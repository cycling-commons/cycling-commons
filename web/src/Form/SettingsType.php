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
 * Settings: display name, units, map prefs, public profile.
 *
 * @see docs/specs/account-and-auth.md §9
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
                    new NotBlank(message: 'form.error_display_name_required'),
                    new Length(min: 2, minMessage: 'form.error_display_name_short'),
                ],
            ])
            ->add('country', EntityType::class, [
                'class' => Country::class,
                'required' => false,
                'label' => 'form.label_country',
                'placeholder' => 'form.ph_country',
                'choice_label' => 'name',
                'group_by' => static fn (Country $c): ?string => $c->getContinent()?->getName(),
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('c')
                    ->leftJoin('c.continent', 'cont')->addSelect('cont')
                    ->orderBy('c.name', 'ASC'),
                'attr' => ['autocomplete' => 'country-name'],
            ])
            // Base location (docs/specs/map-and-search.md §4.5); controller calls BaseLocationService.
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
                'choices' => [
                    'English' => 'en',
                    'Français' => 'fr',
                    'Nederlands' => 'nl',
                    'Deutsch' => 'de',
                    'Español' => 'es',
                ],
                'choice_translation_domain' => false,
            ])
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
            ->add('rowsPerPage', EnumType::class, [
                'class' => RowsPerPage::class,
                'label' => 'form.label_rows_per_page',
                'help' => 'form.help_rows_per_page',
                'required' => true,
                'choice_label' => static fn (RowsPerPage $r): string => $r->labelKey(),
            ])
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
            ])
            // Release notes only, off unless a rider asks for it, and the
            // consent behind it is recorded rather than assumed.
            // @see \App\Account\UpdatesConsent
            ->add('updatesOptIn', CheckboxType::class, [
                'label' => 'form.label_updates_opt_in',
                'required' => false,
                'help' => 'form.help_updates_opt_in',
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
