<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use App\Translation\TranslationConsent;
use App\Translation\TranslationLimits;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Rider proposal for one non-English catalogue key.
 *
 * @see docs/specs/translations.md §4
 */
final class TranslationProposalType extends AbstractType
{
    /** @param array<array-key, mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('value', TextareaType::class, [
            'label' => 'translate.form.value',
            'required' => true,
            'constraints' => [
                new NotBlank(message: 'translate.error.empty'),
                new Length(
                    max: TranslationLimits::PROPOSED_VALUE_MAX,
                    maxMessage: 'translate.error.too_long',
                    countUnit: Length::COUNT_BYTES,
                ),
            ],
        ]);

        if ($options['consent'] && true !== $options['standing']) {
            $builder->add('consent', CheckboxType::class, [
                'label' => TranslationConsent::TEXT_KEY,
                'mapped' => false,
                'required' => false,
                'data' => false,
                'constraints' => [
                    new IsTrue(message: 'translate.error.consent_required'),
                ],
            ]);
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'standing' => false,
            // English carries no CC BY-SA consent (translations.md §4.2, §6):
            // it is product copy proposed by a curator, not a creative work
            // licensed in by a rider.
            'consent' => true,
        ]);
        $resolver->setAllowedTypes('standing', 'bool');
        $resolver->setAllowedTypes('consent', 'bool');
    }
}
