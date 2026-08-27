<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Give the "your token was rejected" message a catalogue key, on every form.
 *
 * Symfony's default `csrf_message` is an English sentence, and it ships a
 * translation for it in the **validators** domain. This app deliberately points
 * validation at the `messages` domain instead (`config/packages/validator.yaml`),
 * because its own constraint messages are keys there. The side effect is that
 * Symfony's shipped translation is never found: the lookup happens in
 * `messages`, misses, and the English source string is what a Dutch rider sees.
 *
 * That went unnoticed until 2026-08-27, because no page rendered form-level
 * errors at all (`partials/_form_errors.html.twig`). The moment they became
 * visible, this one had to exist in five languages like any other copy.
 *
 * A type extension rather than a `csrf_message` option on each form: there are
 * ten forms, the answer is the same for all of them, and a new form should get
 * it without anyone remembering.
 *
 * The negative priority is load-bearing. Symfony's own `FormTypeCsrfExtension`
 * sets a default for the same option, and with OptionsResolver the last writer
 * wins. Tagged extensions run highest-priority first, so this one has to sort
 * last to have any effect at all; at the default priority the English sentence
 * simply overwrites the key again, silently.
 *
 * @api
 */
#[AutoconfigureTag('form.type_extension', ['priority' => -1000])]
final class CsrfMessageExtension extends AbstractTypeExtension
{
    #[\Override]
    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('csrf_message', 'form.error_csrf');
    }
}
