<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Form;

use App\Entity\User;
use App\World\Entity\Country;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Settings form for display name and public-profile toggle.
 */
final class SettingsType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Display name',
                'attr' => [
                    'autocomplete' => 'name',
                    'placeholder' => 'Your public display name',
                ],
                'constraints' => [
                    new NotBlank(message: 'Please enter a display name.'),
                    new Length(
                        max: 100,
                        maxMessage: 'Display name may not exceed {{ limit }} characters.',
                    ),
                ],
            ])
            ->add('country', EntityType::class, [
                'class' => Country::class,
                'required' => false,
                'label' => 'Country',
                'placeholder' => 'Select your country (optional)',
                'choice_label' => 'name',
                // Group the options under their continent.
                'group_by' => static fn (Country $c): ?string => $c->getContinent()?->getName(),
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('c')
                    ->leftJoin('c.continent', 'cont')->addSelect('cont')
                    ->orderBy('c.name', 'ASC'),
                'attr' => ['autocomplete' => 'country-name'],
            ])
            ->add('publicProfile', CheckboxType::class, [
                'label' => 'Public profile',
                'required' => false,
                'help' => 'When off, your profile is hidden and contributions stay anonymous.',
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
