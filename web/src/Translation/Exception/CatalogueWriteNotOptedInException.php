<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * CatalogueWriter was asked to write without the explicit developer opt-in
 * `CC_CATALOGUE_WRITE`.
 *
 * The kernel environment alone cannot carry the licence boundary this path
 * depends on. `web/.env` commits `APP_ENV=dev`, and no deployed environment
 * file overrides it inside the repository: every release relies on a
 * server-side override instead. A box that lost that override would still be
 * "dev" to the application, and then every rider translation on every
 * non-English locale would be written into the shipped catalogue file rather
 * than a proposal row, with no consent record and nothing for `/moderate` to
 * show (translations.md §6). Words a rider typed would ship in a release that
 * nobody reviewed and that they were never asked about, and a rollback does
 * not un-ship them.
 *
 * So the write needs a second signal that is genuinely independent of the
 * environment: true on a developer's own checkout because that developer put
 * it in their own gitignored local environment override, and false on any
 * release because the committed `web/.env` leaves it empty. Empty means off,
 * the same convention `SAFE_BROWSING_KEY` and `DEEPL_API_KEY` already use.
 *
 * Deliberately NOT the DeepL key: a developer typing a translation by hand
 * has no DeepL key and must still be able to write (translations.md §7.3).
 *
 * @see docs/specs/translations.md §7.1, §7.3
 *
 * @api
 */
final class CatalogueWriteNotOptedInException extends \RuntimeException
{
}
