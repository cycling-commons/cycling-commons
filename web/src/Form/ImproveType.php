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
 * carried as hidden fields. Nothing is persisted — the controller hands the
 * submitted array to {@see \App\Service\ContributionStubService}.
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

        // ── Type-specific Details step: two panes as nested sub-forms ────────
        $details = $builder->create('details', FormType::class, ['label' => false, 'required' => false]);
        foreach ($fieldSet->fields as $field) {
            $this->addCatalogField($details, $field);
        }
        $builder->add($details);

        $extras = $builder->create('extras', FormType::class, ['label' => false, 'required' => false]);
        foreach ($fieldSet->addFields as $field) {
            $this->addCatalogField($extras, $field);
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
    }

    private function addCatalogField(FormBuilderInterface $builder, CatalogField $field): void
    {
        $attr = [];
        if ('' !== $field->placeholder) {
            $attr['placeholder'] = $field->placeholder;
        }

        match ($field->kind) {
            FieldKind::Select => $builder->add($field->name, ChoiceType::class, [
                'label' => $field->label,
                'required' => false,
                'placeholder' => '—',
                'choices' => array_combine($field->choices, $field->choices),
            ]),
            FieldKind::Textarea => $builder->add($field->name, TextareaType::class, [
                'label' => $field->label,
                'required' => false,
                'attr' => $attr,
                'constraints' => CatalogFieldConstraints::for($field),
            ]),
            FieldKind::Text => $builder->add($field->name, TextType::class, [
                'label' => $field->label,
                'required' => false,
                'data' => '' !== $field->default ? $field->default : null,
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
        ]);
        $resolver->setAllowedTypes('catalog_type', ItemType::class);
    }
}
