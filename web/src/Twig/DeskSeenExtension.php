<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Moderation\DeskSeen;
use App\Moderation\SeenSubject;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_unseen(kind, ids)`: which of a list's rows the signed-in curator has not
 * opened, as a set keyed by id, one query per list. A desk template asks it
 * once and gives each row in the set the unseen bar:
 *
 *     {% set unseen = cc_unseen('bug_report', reports|map(b => b.id)) %}
 *     <div class="bugrow {{ unseen[b.id] is defined ? 'is-unseen' }}">
 *
 * Nobody signed in, or an unknown kind: an empty set, so no row is marked.
 *
 * @see docs/specs/moderation-and-contribution.md §5.2f
 *
 * @api
 */
final class DeskSeenExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly DeskSeen $seen,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_unseen', $this->unseen(...)),
        ];
    }

    /**
     * @param iterable<int|string> $ids
     *
     * @return array<int|string, true>
     */
    public function unseen(string $kind, iterable $ids): array
    {
        $user = $this->security->getUser();
        $subject = SeenSubject::tryFrom($kind);
        if (!$user instanceof User || null === $user->getId() || null === $subject) {
            return [];
        }

        return $this->seen->unseenAmong((int) $user->getId(), $subject, $ids);
    }
}
