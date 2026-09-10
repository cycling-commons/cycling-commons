<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Curator decision on a route proposal (docs/specs/route-domain.md §5). */
final class RouteDecisionType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('route_id', HiddenType::class, ['constraints' => [new NotBlank()]])
            ->add('decision', ChoiceType::class, [
                'choices' => ['Approve' => 'approve', 'Reject' => 'reject', 'Retire' => 'retire'],
                'constraints' => [new NotBlank()],
            ])
            ->add('note', TextareaType::class, ['required' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
