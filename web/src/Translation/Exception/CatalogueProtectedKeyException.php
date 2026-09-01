<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * CatalogueWriter was asked to write a key {@see \App\Translation\ProtectedKeys}
 * holds back.
 *
 * Both protected keys are consent contracts whose exact wording is hashed
 * into the consent ledger under a VERSION (translations.md §4). Reword one
 * without bumping that VERSION and every stored consent record covers words
 * its rider never saw, with nothing asking them again. A machine paraphrase
 * of a binding licence sentence is exactly the case this refusal exists for.
 *
 * `ProposalService::submit()` already refuses the rider path with its own
 * {@see ProtectedKeyException}; this is the same rule on the catalogue write
 * path, which reaches the shipped file with no curator in between.
 *
 * @see docs/specs/translations.md §4, §7.3
 *
 * @api
 */
final class CatalogueProtectedKeyException extends \RuntimeException
{
}
