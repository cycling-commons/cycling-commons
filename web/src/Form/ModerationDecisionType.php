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
 * Curator decision: approve / reject / needs_info.
 */
final class ModerationDecisionType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submission_id', HiddenType::class, [
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
            ->add('and_confirm', HiddenType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('note', TextareaType::class, [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'moderate.error.note_too_long'),
                ],
            ])
            // Photo uuids the curator unticked (docs/specs/photo-uploads.md §5).
            ->add('media_reject', HiddenType::class, ['required' => false])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
