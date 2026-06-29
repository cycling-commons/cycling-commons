<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Server-side form for the improve / add-location wizard.
 *
 * Maps the wizard's inputs onto Symfony form types. The geocoded location
 * (lat/lng/place) is filled by client-side JS and carried as hidden fields.
 * CSRF protection is provided automatically.
 */
final class ImproveType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('whatChanged', TextareaType::class, [
                'label' => false,
                'constraints' => [
                    new NotBlank(message: 'improve.error.what_changed_required'),
                    new Length(max: 2000, maxMessage: 'improve.error.what_changed_too_long'),
                ],
            ])
            ->add('note', TextareaType::class, [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'improve.error.note_too_long'),
                ],
            ])
            ->add('photoUrl', UrlType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('videoUrl', UrlType::class, [
                'label' => false,
                'required' => false,
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
            ->add('mode', HiddenType::class, [
                'label' => false,
                'required' => false,
            ])
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
