<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
    /**
     * The per-locale field carrying the text for `$locale`.
     *
     * Named by the bare locale code so the dev form reads
     * `translation_proposal[nl]`, next to its own
     * `translation_proposal[save_nl]` tick box.
     */
    public static function valueField(string $locale): string
    {
        return $locale;
    }

    /** The tick box deciding whether `$locale`'s field is written. */
    public static function saveField(string $locale): string
    {
        return 'save_'.$locale;
    }

    /** @param array<array-key, mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $locales */
        $locales = $options['locales'];
        if ([] !== $locales) {
            $this->buildDevForm($builder, $locales);

            return;
        }

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

    /**
     * The dev catalogue form: one field per rider locale, each with the
     * tick box that decides whether it is written (translations.md §7.3).
     *
     * No NotBlank here, unlike the rider field above. A field for a locale
     * whose tick box is off is not being submitted at all, and refusing the
     * whole form over one would block a write the developer did ask for.
     * The controller refuses an empty value on a TICKED locale, where the
     * refusal is about something the developer actually asked to happen.
     *
     * @param list<string> $locales
     */
    private function buildDevForm(FormBuilderInterface $builder, array $locales): void
    {
        foreach ($locales as $locale) {
            $builder->add(self::valueField($locale), TextareaType::class, [
                'label' => 'lang.'.$locale,
                'required' => false,
                'constraints' => [
                    new Length(
                        max: TranslationLimits::PROPOSED_VALUE_MAX,
                        maxMessage: 'translate.error.too_long',
                        countUnit: Length::COUNT_BYTES,
                    ),
                ],
            ]);
            // The template renders these together under one legend, with
            // the language name beside each box, so the field's own label
            // would only repeat it.
            $builder->add(self::saveField($locale), CheckboxType::class, [
                'label' => false,
                'required' => false,
            ]);
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'standing' => false,
            // Non-empty only on the dev catalogue-write path: one text field
            // and one tick box per locale listed, and no single "value"
            // field at all (translations.md §7.3).
            'locales' => [],
            // English carries no CC BY-SA consent (translations.md §4.2, §6):
            // it is product copy proposed by a curator, not a creative work
            // licensed in by a rider.
            'consent' => true,
        ]);
        $resolver->setAllowedTypes('standing', 'bool');
        $resolver->setAllowedTypes('consent', 'bool');
        $resolver->setAllowedTypes('locales', 'string[]');
    }
}
