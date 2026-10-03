<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * What kind of stay a place to sleep is, drawn as an icon.
 *
 * Read from the row's `t`, the type label every stay carries ("Hotel",
 * "Guest house", "B&B", "Campsite", ...). Five drawings cover them. Shown on
 * the season ballot only for now (owner 2026-10-03: "B first in the ballot,
 * then later if I like the design it will be added to the map"); the map's
 * pin kinds stay in {@see KindIcons} until then.
 *
 * Each drawing is one filled path in a 24-box, drawn in the text colour with
 * the even-odd rule, the same style as the type icons.
 *
 * @api
 */
final class StayKind
{
    /** @var array<string, string> kind => path */
    public const array PATHS = [
        // A building with six windows.
        'hotel' => 'M5 3h14v18h-5v-4h-4v4H5Z M8 6h2v2H8Z M14 6h2v2h-2Z M8 10h2v2H8Z M14 10h2v2h-2Z M8 14h2v2H8Z M14 14h2v2h-2Z',
        // A house with a door: guest house, B&B, gîte, a rental.
        'house' => 'M12 3 2 11.5h3V21h5v-5h4v5h5v-9.5h3Z',
        // A tent with its door open.
        'camp' => 'M12 3 2 21h7l3-6 3 6h7Z',
        // A bunk bed: hostel, budget stay.
        'hostel' => 'M3 4h2v16H3Z M19 4h2v16h-2Z M5 7h14v3H5Z M5 14h14v3H5Z',
        // A cabin with a steep roof and two windows: chalet, mountain hut.
        'chalet' => 'M12 2 2 11h2v10h6v-5h4v5h6V11h2Z M6.5 13h3v1.6h-3Z M14.5 13h3v1.6h-3Z',
        // A house outline with a question mark: a stay whose kind we do not
        // know (owner 2026-10-03), never the category's tent.
        'unknown' => 'M12 3 2 11.5h3V21h14v-9.5h3Z M12 6.4 6.8 10.9V19.2h10.4v-8.3Z M10.1 12.2a1.9 1.9 0 1 1 2.9 1.6c-.6.4-.9.7-.9 1.3v.3h-1.5v-.4c0-.9.4-1.4 1.1-1.9.4-.3.6-.5.6-.8a.6.6 0 0 0-1.2-.1Z M11.2 16.2h1.6v1.5h-1.6Z',
    ];

    public const string UNKNOWN = 'unknown';

    /** @var array<string, string> lower-cased `t` => kind */
    private const array BY_LABEL = [
        'hotel' => 'hotel',
        'guest house' => 'house',
        'b&b' => 'house',
        'gîte' => 'house',
        'gîte / guesthouse' => 'house',
        'furnished rental' => 'house',
        'campsite' => 'camp',
        'hostel' => 'hostel',
        'budget stay' => 'hostel',
        'chalet' => 'chalet',
        'mountain hut' => 'chalet',
    ];

    /** The catalogue key of a kind's written-out name, beside its icon. */
    public static function labelKey(string $kind): string
    {
        return 'vote.stay_kind.'.$kind;
    }

    /** The kind for a type label, or null when the label names none we draw. */
    public static function fromLabel(?string $label): ?string
    {
        if (null === $label) {
            return null;
        }

        return self::BY_LABEL[mb_strtolower(trim($label))] ?? null;
    }
}
