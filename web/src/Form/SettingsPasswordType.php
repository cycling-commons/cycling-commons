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

/**
 * Settings password-change form.
 *
 * Unlike ChangePasswordFormType (used in the reset-password flow, which is
 * token-authenticated), this form requires the user to supply their CURRENT
 * password first. The controller verifies it with UserPasswordHasherInterface
 * before applying the change.
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
                    new NotBlank(message: 'Please enter your current password.'),
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
                        new NotBlank(message: 'Please enter a new password.'),
                        new Length(min: 12, minMessage: 'Password must be at least {{ limit }} characters.'),
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
