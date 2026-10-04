<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * TOTP setup: 6-digit shape only; the controller checks the code against the pending secret.
 *
 * Replacing a second factor that is already on asks for a code from the old
 * one (`current_code`), and a remember-me session asks for the password
 * (`current_password`): the controller checks both, under the shared
 * password_reauth budget.
 *
 * @see docs/specs/account-and-auth.md §4
 */
final class TwoFactorSetupType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['current_password']) {
            $builder->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'form.label_current_password',
                'attr' => [
                    'autocomplete' => 'current-password',
                    'placeholder' => 'form.ph_current_password',
                ],
                'constraints' => [
                    new NotBlank(message: 'form.error_password_current_required'),
                ],
            ]);
        }
        if ($options['current_code']) {
            // A TOTP code or a backup code, so no shape check: the controller
            // tries the stored secret and then the backup codes.
            $builder->add('currentCode', TextType::class, [
                'mapped' => false,
                'label' => 'twofactor.setup.current_code_label',
                'attr' => [
                    'autocomplete' => 'one-time-code',
                    'autocapitalize' => 'off',
                    'spellcheck' => 'false',
                ],
                'constraints' => [
                    new NotBlank(message: 'twofactor.setup.current_code_required'),
                ],
            ]);
        }
        $builder->add('code', TextType::class, [
            'mapped' => false,
            'label' => 'form.label_verification_code',
            'attr' => [
                'autocomplete' => 'one-time-code',
                'inputmode' => 'numeric',
                'pattern' => '[0-9]*',
                'placeholder' => 'form.ph_code',
            ] + ($options['current_password'] || $options['current_code'] ? [] : ['autofocus' => 'autofocus']),
            'constraints' => [
                new NotBlank(message: 'form.error_totp_required'),
                new Regex(pattern: '/^\d{6}$/', message: 'form.error_totp_length'),
            ],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'current_code' => false,
            'current_password' => false,
        ]);
        $resolver->setAllowedTypes('current_code', 'bool');
        $resolver->setAllowedTypes('current_password', 'bool');
    }
}
