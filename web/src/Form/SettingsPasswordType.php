<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

/**
 * Settings password change. Requires current password (unlike the reset-token form).
 *
 * @see docs/specs/account-and-auth.md §8
 */
final class SettingsPasswordType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'form.label_current_password',
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'current-password',
                    'placeholder' => 'form.ph_current_password',
                ],
                'constraints' => [
                    new NotBlank(message: 'form.error_password_current_required'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'form.label_new_password',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'form.ph_min12',
                    ],
                    'constraints' => [
                        new NotBlank(message: 'form.error_password_new_required'),
                        new Length(min: 12, minMessage: 'form.error_password_short'),
                        // docs/specs/account-and-auth.md §2 — HIBP k-anonymity; skipOnError so an outage cannot block a change.
                        new NotCompromisedPassword(skipOnError: true, message: 'form.error_password_breached'),
                    ],
                ],
                'second_options' => [
                    'label' => 'form.label_confirm_new_password',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'form.ph_repeat_new_password',
                    ],
                ],
                'invalid_message' => 'form.error_password_mismatch',
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
