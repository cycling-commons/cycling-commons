<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Form;

use App\Catalog\BikeType;
use App\Catalog\DifficultyVocabulary;
use App\Catalog\RouteMetadata;
use App\Catalog\Season;
use App\Catalog\SurfaceVocabulary;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The widgets for a route's editorial metadata, defined once. The rider's
 * proposal form ({@see ProposeRouteType}) and the curator's edit form on the
 * Routes desk ({@see RouteEditType}) both build their fields here, so a value
 * a curator sets carries the same wording, options and validation as one the
 * proposer set.
 *
 * `$required` is the one difference between the two: a proposal asks for a
 * difficulty and a surface up front, while the curator form offers every field
 * empty and takes it back empty, because a route that has never carried a
 * value must be savable without inventing one.
 *
 * @see docs/specs/edit-items/R-quality-rides.md
 * @see docs/specs/route-domain.md §9
 *
 * @api
 */
final class RouteMetadataFields
{
    /**
     * @param list<string> $fields {@see RouteMetadata::NAME_FIELD} and keys from {@see RouteMetadata::ATTRIBUTE_FIELDS}
     */
    public static function add(FormBuilderInterface $builder, array $fields, bool $required): void
    {
        foreach ($fields as $field) {
            [$type, $options] = self::widget($field, $required);
            $builder->add($field, $type, $options);
        }
    }

    /**
     * @return array{class-string, array<string, mixed>}
     */
    private static function widget(string $field, bool $required): array
    {
        return match ($field) {
            // A route keeps a name in either form: there is no nameless route to save.
            RouteMetadata::NAME_FIELD => [TextType::class, [
                'label' => false,
                'constraints' => [
                    new NotBlank(message: 'propose_route.error.name_required'),
                    new Length(max: RouteMetadata::NAME_MAX, maxMessage: 'propose_route.error.name_too_long'),
                    ...self::textSafety(),
                ],
            ]],
            'difficulty' => [ChoiceType::class, [
                'label' => false,
                'required' => $required,
                'choices' => DifficultyVocabulary::choices(),
            ]],
            'season' => [ChoiceType::class, [
                'label' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                // Stored values stay capitalized (route-domain.md §9); labels are the seasons' names.
                'choices' => array_combine(RouteMetadata::seasons(), RouteMetadata::seasons()),
                'choice_label' => static fn (string $s): string => Season::from(strtolower($s))->labelKey(),
            ]],
            'dominantSurface' => [ChoiceType::class, [
                'label' => false,
                'required' => $required,
                'choices' => array_combine(SurfaceVocabulary::DECLARABLE, SurfaceVocabulary::DECLARABLE),
            ]],
            'note' => [TextareaType::class, [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Length(max: RouteMetadata::NOTE_MAX, maxMessage: 'propose_route.error.note_too_long'),
                    ...self::textSafety(),
                ],
            ]],
            'bikeTypes' => [ChoiceType::class, [
                'label' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine(BikeType::values(), BikeType::values()),
                'choice_label' => static fn (string $b): string => BikeType::from($b)->labelKey(),
            ]],
            'gradientLimited' => [ChoiceType::class, [
                'label' => false,
                'required' => false,
                // Stored values stay 'No'/'≤6%'/'≤9%' (docs/specs/route-domain.md §9).
                'choices' => array_combine(array_keys(RouteMetadata::GRADIENT_LABELS), array_keys(RouteMetadata::GRADIENT_LABELS)),
                'choice_label' => static fn (string $g): string => RouteMetadata::GRADIENT_LABELS[$g],
            ]],
            'bestDirection' => [ChoiceType::class, [
                'label' => false,
                'required' => false,
                // The stored value is its own msgid, the way the drawer's schema reads it.
                'choices' => array_combine(RouteMetadata::DIRECTIONS, RouteMetadata::DIRECTIONS),
            ]],
            default => throw new \InvalidArgumentException(sprintf('"%s" is not a route metadata field.', $field)),
        };
    }

    /**
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    private static function textSafety(): array
    {
        return [
            CatalogFieldConstraints::noSuspiciousCharacters(),
            new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters'),
        ];
    }
}
