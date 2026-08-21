<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * TOTP setup: 6-digit shape only; the controller checks the code against the pending secret.
 *
 * @see docs/specs/account-and-auth.md §4
 */
final class TwoFactorSetupType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'mapped' => false,
            'label' => 'form.label_verification_code',
            'attr' => [
                'autocomplete' => 'one-time-code',
                'inputmode' => 'numeric',
                'pattern' => '[0-9]*',
                'placeholder' => 'form.ph_code',
                'autofocus' => 'autofocus',
            ],
            'constraints' => [
                new NotBlank(message: 'Enter the 6-digit code from your authenticator app.'),
                new Regex(pattern: '/^\d{6}$/', message: 'The code must be exactly 6 digits.'),
            ],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
