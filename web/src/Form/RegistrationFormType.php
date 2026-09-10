<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

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
                    new NotBlank(message: 'form.error_display_name_required'),
                    new Length(min: 2, minMessage: 'form.error_display_name_short'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'form.label_password',
                    'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'form.ph_min12'],
                    'constraints' => [
                        new NotBlank(message: 'form.error_password_required'),
                        new Length(min: 12, minMessage: 'form.error_password_short'),
                        // docs/specs/account-and-auth.md §2 — HIBP k-anonymity; skipOnError so an outage cannot block signup.
                        new NotCompromisedPassword(skipOnError: true, message: 'form.error_password_breached'),
                    ],
                ],
                'second_options' => [
                    'label' => 'form.label_confirm_password',
                    'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'form.ph_repeat_password'],
                ],
                'invalid_message' => 'form.error_password_mismatch',
            ])
            // Age gate (docs/specs/account-and-auth.md §2): self-declared 16+, not a date of birth.
            ->add('confirmAge', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'form.label_confirm_age',
                'constraints' => [
                    new IsTrue(message: 'form.error_age_16'),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'form.label_terms',
                'constraints' => [
                    new IsTrue(message: 'form.error_terms_required'),
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
