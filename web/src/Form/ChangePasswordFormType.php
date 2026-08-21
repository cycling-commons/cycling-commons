<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

final class ChangePasswordFormType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'first_options' => [
                'label' => 'form.label_new_password',
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => 'form.ph_min12',
                ],
                'constraints' => [
                    new NotBlank(message: 'Please enter a new password.'),
                    new Length(min: 12, minMessage: 'Password must be at least {{ limit }} characters.'),
                    // docs/specs/account-and-auth.md §2 — HIBP k-anonymity; skipOnError so an outage cannot block a change.
                    new NotCompromisedPassword(skipOnError: true, message: 'This password appears in a known data breach. Please choose a different one.'),
                ],
            ],
            'second_options' => [
                'label' => 'form.label_confirm_new_password',
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => 'form.ph_repeat_new_password',
                ],
            ],
            'invalid_message' => 'The password fields must match.',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
