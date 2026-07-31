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
            // The age gate (GDPR Art. 8). Self-declared, and deliberately not a
            // date of birth: one yes/no question does not need a birthday on
            // file, and Art. 8(2) asks for reasonable efforts given available
            // technology — for a service like this one, that is a declaration.
            // Flat 16 for everyone: it is the Art. 8 ceiling, so it never sits
            // below any member state's own floor (owner decision 2026-08-01).
            ->add('confirmAge', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'form.label_confirm_age',
                'constraints' => [
                    new IsTrue(message: 'You must be 16 or older to create an account.'),
                ],
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
