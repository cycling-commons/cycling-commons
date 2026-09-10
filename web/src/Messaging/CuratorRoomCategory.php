<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging;

/**
 * The curator room's shelves.
 *
 * Fixed in code, like {@see MessageCategory}: the room serves one small team,
 * which does not need an admin screen, a merge story or an empty-category rule.
 * A post with no category belongs to the room's root.
 *
 * @see docs/specs/moderation-and-contribution.md §13.4
 *
 * @api
 */
enum CuratorRoomCategory: string
{
    case General = 'general';
    case Ask = 'ask';
    case Escalations = 'escalations';
    case Rules = 'rules';
    case Tools = 'tools';

    /**
     * The value of the "everything I may see" view, which is not a category.
     */
    public const string VIEW_ALL = 'all';

    /**
     * The value of the "posts addressed to or by me" view, which is not a category.
     */
    public const string VIEW_DIRECT = 'direct';

    /**
     * The root, which is not a category either: posts filed nowhere in particular.
     */
    public const string VIEW_ROOT = 'root';

    public function labelKey(): string
    {
        return 'room.cat.'.$this->value;
    }

    public function hintKey(): string
    {
        return 'room.cat_hint.'.$this->value;
    }

    /**
     * The category named by a query value, or null when it names a view or nothing.
     */
    public static function tryFromView(string $view): ?self
    {
        return self::tryFrom($view);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
