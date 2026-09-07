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
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
 * Type-aware improve / add-location wizard. Details come from the catalog registry.
 *
 * @see docs/specs/moderation-and-contribution.md §1.4
 *
 * @api
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

        $details = $builder->create('details', FormType::class, ['label' => false, 'required' => false]);
        // Add: required name (docs/specs/moderation-and-contribution.md §1.1). Edit: optional; empty means leave unchanged.
        $details->add(Item::NAME_FIELD, TextType::class, [
            'label' => 'form.label_name',
            'required' => $addMode,
            'data' => $current[Item::NAME_FIELD] ?? null,
            'constraints' => $addMode
                ? [
                    new NotBlank(message: 'contribute.error.name_required'),
                    new Length(max: 120, maxMessage: 'contribute.error.field_too_long'),
                ]
                : [new Length(max: 120, maxMessage: 'contribute.error.field_too_long')],
        ]);
        foreach ($fieldSet->fields as $field) {
            if (Item::NAME_FIELD === $field->name) {
                continue;
            }
            if ($field->derived) {
                continue;
            }
            $this->addCatalogField($details, $field, $current);
        }
        $builder->add($details);

        $extras = $builder->create('extras', FormType::class, ['label' => false, 'required' => false]);
        foreach ($fieldSet->addFields as $field) {
            if ($field->derived) {
                continue;
            }
            $this->addCatalogField($extras, $field, $current);
        }
        $builder->add($extras);

        $builder
            ->add('subject', HiddenType::class, ['required' => false])
            // Upload uuids (docs/specs/photo-uploads.md §4); intake re-validates ownership.
            ->add('mediaIds', HiddenType::class, ['required' => false])
            // Descriptions keyed by upload uuid, the copy that survives a Next
            // pressed before the live save landed (photo-uploads.md §5e).
            ->add('mediaAlts', HiddenType::class, ['required' => false])
            ->add('lat', HiddenType::class, ['required' => false])
            ->add('lng', HiddenType::class, ['required' => false])
            ->add('place', HiddenType::class, ['required' => false])
            ->add('mode', HiddenType::class, ['required' => false])
            // A curator's optional "Mark it confirmed" on their own applied edit (moderation-and-contribution.md §1.6).
            ->add('confirmNow', CheckboxType::class, ['required' => false, 'label' => false])
        ;

        if (ItemType::Climbs === $type) {
            $builder
                ->add('route', HiddenType::class, ['label' => false, 'required' => false])
                ->add('grad', HiddenType::class, ['label' => false, 'required' => false])
                ->add('steep', HiddenType::class, ['label' => false, 'required' => false])
                ->add('avg', HiddenType::class, ['label' => false, 'required' => false])
                ->add('steepPoint', HiddenType::class, ['label' => false, 'required' => false])
            ;
        }

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
                'choices' => [] === $field->choiceLabels ? array_combine($field->choices, $field->choices) : array_flip($field->choiceLabels),
                'data' => $data,
            ]),
            FieldKind::MultiSelect => $builder->add($field->name, ChoiceType::class, [
                'label' => $field->label,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine($field->choices, $field->choices),
                'data' => \is_array($current[$field->name] ?? null) ? $current[$field->name] : [],
            ]),
            FieldKind::Links => $builder->add($field->name, HiddenType::class, [
                'label' => $field->label,
                'required' => false,
                'data' => \is_array($current[$field->name] ?? null)
                    ? json_encode($current[$field->name], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                    : '',
                'attr' => ['data-links-editor' => $field->label],
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
            'service_kind' => null,
            'add_mode' => false,
        ]);
        $resolver->setAllowedTypes('catalog_type', ItemType::class);
        $resolver->setAllowedTypes('current', 'array');
        $resolver->setAllowedTypes('service_kind', ['null', ServiceKind::class]);
        $resolver->setAllowedTypes('add_mode', 'bool');
    }
}
