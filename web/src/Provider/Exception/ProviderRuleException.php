<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Provider\Exception;

/**
 * A desk edit the registry refuses, carrying the catalogue key that says why.
 *
 * The message IS the key, not prose: the refusal is shown to a curator in
 * their own language, and a rule that composed an English sentence here would
 * be a rule the site could only explain in English.
 *
 * @see docs/specs/data-provider-hierarchy.md §8
 */
final class ProviderRuleException extends \RuntimeException
{
}
