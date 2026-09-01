<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * A value headed straight for a catalogue file does not carry the same
 * `%placeholder%` set as its English source.
 *
 * Catalogue strings interpolate at render (`%date%`, `%locales%`, `%tag%`).
 * A placeholder that was translated, spaced out, renamed or dropped does not
 * fail: it renders as literal text in the middle of a sentence, on every page
 * that string appears on, for as long as nobody notices. Machine output is
 * where this actually happens, because DeepL is handed a sentence and has no
 * idea one of its words is a variable.
 *
 * {@see \App\Translation\TranslationMarkup} checks tags, not placeholders,
 * and the parity gate compares key sets, not values, so nothing else on the
 * dev write path would catch it.
 *
 * @see docs/specs/translations.md §7.3
 *
 * @api
 */
final class PlaceholderMismatchException extends \RuntimeException
{
}
