<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Curator decision on a translation proposal.
 *
 * @see docs/specs/translations.md §5
 */
final class TranslationDecisionType extends AbstractType
{
    /** @param array<array-key, mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('proposal_id', HiddenType::class, [
                'label' => false,
                'constraints' => [
                    new NotBlank(message: 'moderate.error.submission_id_required'),
                ],
            ])
            ->add('decision', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'Approve' => 'approve',
                    'Reject' => 'reject',
                    'Needs info' => 'needs_info',
                ],
                'constraints' => [
                    new NotBlank(message: 'moderate.error.decision_required'),
                ],
            ])
            ->add('note', TextareaType::class, [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'moderate.error.note_too_long'),
                ],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_token_id' => 'moderate_translation',
        ]);
    }
}
