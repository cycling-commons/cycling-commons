<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class RegistrationFormType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'form.label_email',
                'attr' => ['autocomplete' => 'email', 'placeholder' => 'form.ph_email'],
            ])
            ->add('displayName', TextType::class, [
                'label' => 'form.label_display_name',
                'attr' => ['autocomplete' => 'nickname', 'placeholder' => 'form.ph_display_name_register'],
                'constraints' => [
                    new NotBlank(message: 'Please enter a display name.'),
                    new Length(min: 2, max: 100, minMessage: 'Display name must be at least {{ limit }} characters.', maxMessage: 'contribute.error.field_too_long'),
                    // Same invisible-character guard applied to every user text
                    // field (a lone U+200B etc. passes NoSuspiciousCharacters).
                    CatalogFieldConstraints::noSuspiciousCharacters(),
                    new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'form.label_password',
                    'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'form.ph_min12'],
                    'constraints' => [
                        new NotBlank(message: 'Please enter a password.'),
                        new Length(min: 12, minMessage: 'Password must be at least {{ limit }} characters.'),
                    ],
                ],
                'second_options' => [
                    'label' => 'form.label_confirm_password',
                    'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'form.ph_repeat_password'],
                ],
                'invalid_message' => 'The password fields must match.',
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'form.label_terms',
                'constraints' => [
                    new IsTrue(message: 'You must agree to the terms of service.'),
                ],
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
