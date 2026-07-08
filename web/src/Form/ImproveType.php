<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use App\Catalog\CatalogField;
use App\Catalog\CatalogFormRegistry;
use App\Catalog\FieldKind;
use App\Catalog\ItemType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
 * The `current` option (`array<string, scalar|null>`, keyed by registry field
 * name) prefills Text/Textarea/Select fields with the bound item's current
 * name + attribute values — the edit-bridge acceptance criterion (spec §8):
 * `/improve?item=<id>` opens pre-filled, never blank/default.
 *
 * @api Instantiated by Symfony's form factory — `@api` tells Psalm the
 *      constructor is a live entry point, not dead code.
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
        $fieldSet = $this->registry->for($type);
        /** @var array<string, scalar|null> $current */
        $current = $options['current'];

        // ── Type-specific Details step: two panes as nested sub-forms ────────
        $details = $builder->create('details', FormType::class, ['label' => false, 'required' => false]);
        foreach ($fieldSet->fields as $field) {
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
            // filled by JS from ?item= — distinct from the catalog type.
            ->add('subject', HiddenType::class, ['required' => false])
            ->add('photoUrl', UrlType::class, [
                'label' => false,
                'required' => false,
                // Don't let FixUrlProtocolListener turn an empty optional field
                // into the bare string "http://".
                'default_protocol' => null,
            ])
            ->add('videoUrl', UrlType::class, [
                'label' => false,
                'required' => false,
                'default_protocol' => null,
            ])
            ->add('lat', HiddenType::class, ['required' => false])
            ->add('lng', HiddenType::class, ['required' => false])
            ->add('place', HiddenType::class, ['required' => false])
            ->add('mode', HiddenType::class, ['required' => false])
        ;

        // Climb shape drawn by the three-point editor (client JS), carried as
        // JSON — top-level (not nested under details/extras) so it lands at
        // $payload['route'|'grad'|'steep'] for App\Contribution\ClimbGeometry
        // to decode, exactly like AddClimbType (Task 4). Only climbs get
        // these — other catalog types have no geometry to edit.
        if (ItemType::Climbs === $type) {
            $builder
                ->add('route', HiddenType::class, ['label' => false, 'required' => false])
                ->add('grad', HiddenType::class, ['label' => false, 'required' => false])
                ->add('steep', HiddenType::class, ['label' => false, 'required' => false])
            ;
        }
    }

    /** @param array<string, scalar|null> $current */
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
            // P2-D2: same choice universe as Select, but multiple/expanded so the
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
            // registry emits — see CatalogFieldConstraints (critical #3).
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

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'catalog_type' => ItemType::default(),
            'current' => [],
        ]);
        $resolver->setAllowedTypes('catalog_type', ItemType::class);
        $resolver->setAllowedTypes('current', 'array');
    }
}
