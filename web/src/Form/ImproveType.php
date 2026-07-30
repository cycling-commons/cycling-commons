<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use App\Catalog\CatalogField;
use App\Catalog\CatalogFormRegistry;
use App\Catalog\Entity\Item;
use App\Catalog\FieldKind;
use App\Catalog\ItemType;
use App\Catalog\LocationMode;
use App\Catalog\ServiceKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The type-aware improve / add-location wizard.
 *
 * Given a `catalog_type` ({@see ItemType}), the Details step is built from the
 * catalog registry: the "Fix details" pane ({@see \App\Catalog\ItemFieldSet::$fields})
 * becomes the `details` sub-form and the "Add missing" pane the `extras` sub-form,
 * so a gîte shows gîte fields and a road segment shows surface fields.
 *
 * Location (lat/lng/place) and media queue are filled by client-side JS and
 * carried as hidden fields. The controller hands the submitted array to
 * {@see \App\Service\ContributionStubInterface} (implemented by
 * {@see \App\Contribution\CatalogContributionService}), which persists the
 * 'improve' kind as an Edit submission (was/now snapshot against the bound
 * item's current attributes).
 *
 * The `current` option (`array<string, scalar|list<string>|null>`, keyed by
 * registry field name) prefills Text/Textarea/Select fields with the bound
 * item's current name and attribute values, so `/improve?item=<id>` opens
 * pre-filled, never blank or default.
 *
 * @see docs/specs/moderation-and-contribution.md §1.4
 *
 * @api Instantiated by Symfony's form factory.
 */
final class ImproveType extends AbstractType
{
    public function __construct(
        private readonly CatalogFormRegistry $registry,
    ) {
    }

    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $type = $options['catalog_type'];
        \assert($type instanceof ItemType);
        $serviceKind = $options['service_kind'];
        \assert(null === $serviceKind || $serviceKind instanceof ServiceKind);
        $fieldSet = $this->registry->for($type, $serviceKind);
        /** @var array<string, scalar|list<string>|null> $current */
        $current = $options['current'];
        $addMode = (bool) $options['add_mode'];

        // ── Type-specific Details step: two panes as nested sub-forms ────────
        $details = $builder->create('details', FormType::class, ['label' => false, 'required' => false]);
        // Add mode ("Add a new place", moderation-and-contribution.md §1.1):
        // a new place must arrive NAMED — the moderation queue and the map
        // both key on it — but several field sets (water & food among them)
        // carry no name field because editing an existing item never needs
        // one. Inject a required name first, and skip any registry-optional
        // duplicate so the requirement cannot be bypassed.
        if ($addMode) {
            $details->add(Item::NAME_FIELD, TextType::class, [
                'label' => 'Name',
                'required' => true,
                // Materialize-on-edit prefills the OSM name via `current`;
                // the bare add flow starts blank.
                'data' => $current[Item::NAME_FIELD] ?? null,
                'constraints' => [
                    new NotBlank(message: 'contribute.error.name_required'),
                    new Length(max: 120, maxMessage: 'contribute.error.field_too_long'),
                ],
            ]);
        }
        foreach ($fieldSet->fields as $field) {
            if ($addMode && Item::NAME_FIELD === $field->name) {
                continue;
            }
            $this->addCatalogField($details, $field, $current);
        }
        $builder->add($details);

        $extras = $builder->create('extras', FormType::class, ['label' => false, 'required' => false]);
        foreach ($fieldSet->addFields as $field) {
            $this->addCatalogField($extras, $field, $current);
        }
        $builder->add($extras);

        // ── Shared fields (all types) ────────────────────────────────────────
        $builder
            // Opaque feature reference (which specific item is being edited),
            // filled by JS from ?item=. This is distinct from the catalog type.
            ->add('subject', HiddenType::class, ['required' => false])
            ->add('photoUrl', UrlType::class, [
                'label' => false,
                'required' => false,
                // Don't let FixUrlProtocolListener turn an empty optional field
                // into the bare string "http://".
                'default_protocol' => null,
                // UrlType is only a widget. Validate server-side so a
                // javascript:/data:/file: scheme or an unbounded string can
                // never reach the submission payload.
                'constraints' => self::mediaUrlConstraints(),
            ])
            ->add('videoUrl', UrlType::class, [
                'label' => false,
                'required' => false,
                'default_protocol' => null,
                'constraints' => self::mediaUrlConstraints(),
            ])
            ->add('lat', HiddenType::class, ['required' => false])
            ->add('lng', HiddenType::class, ['required' => false])
            ->add('place', HiddenType::class, ['required' => false])
            ->add('mode', HiddenType::class, ['required' => false])
        ;

        // Climb shape drawn by the three-point editor (client JS), carried as
        // JSON. These fields stay top-level (not nested under details/extras)
        // so they land at $payload['route'|'grad'|'steep'], where
        // App\Contribution\ClimbGeometry decodes them, exactly like
        // AddClimbType. Only climbs get these fields; other catalog types
        // have no geometry to edit.
        if (ItemType::Climbs === $type) {
            $builder
                ->add('route', HiddenType::class, ['label' => false, 'required' => false])
                ->add('grad', HiddenType::class, ['label' => false, 'required' => false])
                ->add('steep', HiddenType::class, ['label' => false, 'required' => false])
            ;
        }

        // Segment-located types (road surface): the wizard's two drawn
        // endpoints, carried as JSON {"a":[lng,lat],"b":[lng,lat]}. Without
        // this field the drawn segment only lives in client memory and is
        // silently dropped on submit. It is recorded in the submission
        // payload for parity with the point branch's lat/lng. Applying
        // geometry edits on approve is a separate moderation feature for
        // points and segments alike.
        if (LocationMode::Segment === $type->locationMode()) {
            $builder->add('segment', HiddenType::class, ['label' => false, 'required' => false]);
        }
    }

    /** @param array<string, scalar|list<string>|null> $current */
    private function addCatalogField(FormBuilderInterface $builder, CatalogField $field, array $current): void
    {
        $attr = [];
        if ('' !== $field->placeholder) {
            $attr['placeholder'] = $field->placeholder;
        }

        $data = $current[$field->name] ?? ('' !== $field->default ? $field->default : null);

        match ($field->kind) {
            FieldKind::Select => $builder->add($field->name, ChoiceType::class, [
                'label' => $field->label,
                'required' => false,
                'placeholder' => '—',
                'choices' => array_combine($field->choices, $field->choices),
                'data' => $data,
            ]),
            // Same choice universe as Select, but multiple/expanded so the
            // submitted value is a list<string> (BikeTypeVocabulary consumes it).
            FieldKind::MultiSelect => $builder->add($field->name, ChoiceType::class, [
                'label' => $field->label,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine($field->choices, $field->choices),
                'data' => \is_array($current[$field->name] ?? null) ? $current[$field->name] : [],
            ]),
            FieldKind::Textarea => $builder->add($field->name, TextareaType::class, [
                'label' => $field->label,
                'required' => false,
                'data' => $data,
                'attr' => $attr,
                'constraints' => CatalogFieldConstraints::for($field),
            ]),
            FieldKind::Text => $builder->add($field->name, TextType::class, [
                'label' => $field->label,
                'required' => false,
                'data' => $data,
                'attr' => $attr,
                'constraints' => CatalogFieldConstraints::for($field),
            ]),
            // A real http(s) URL field: rendered as <input type="url"> (no
            // protocol auto-prefixing) and validated by the Url constraint the
            // registry emits. See CatalogFieldConstraints.
            FieldKind::Url => $builder->add($field->name, UrlType::class, [
                'label' => $field->label,
                'required' => false,
                'default_protocol' => null,
                'data' => $data,
                'attr' => $attr,
                'constraints' => CatalogFieldConstraints::for($field),
            ]),
        };
    }

    /** @return list<\Symfony\Component\Validator\Constraint> */
    private static function mediaUrlConstraints(): array
    {
        return [
            new \Symfony\Component\Validator\Constraints\Url(
                message: 'contribute.error.invalid_url',
                protocols: ['http', 'https'],
                requireTld: true,
                tldMessage: 'contribute.error.invalid_url',
            ),
            new Length(max: 500, maxMessage: 'contribute.error.field_too_long'),
        ];
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'catalog_type' => ItemType::default(),
            'current' => [],
            // The bound item's D (BikeServices) kind. Null for other types or
            // when the kind is unknown; CatalogFormRegistry::for() then falls
            // back to the default field set, which includes openingHours.
            'service_kind' => null,
            // "Add a new place": no bound item, required name (see buildForm).
            'add_mode' => false,
        ]);
        $resolver->setAllowedTypes('catalog_type', ItemType::class);
        $resolver->setAllowedTypes('current', 'array');
        $resolver->setAllowedTypes('service_kind', ['null', ServiceKind::class]);
        $resolver->setAllowedTypes('add_mode', 'bool');
    }
}
