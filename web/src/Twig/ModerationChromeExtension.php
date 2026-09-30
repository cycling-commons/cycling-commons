<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Catalog\CatalogFindingRepository;
use App\Entity\User;
use App\Media\MediaTakedownService;
use App\Messaging\CuratorRoom;
use App\Moderation\ModerationScope;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\RouteQueue;
use App\Moderation\SubmissionQueue;
use App\Support\SupportRepository;
use App\Translation\DecisionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Open-queue counts for curator chrome (desk tabs and the account chip).
 *
 * Every desk badge comes from here, on every page: Twig functions rather than
 * controller variables, because these tabs render on pages owned by several
 * controllers, and a badge that is on one page and gone on the next reads as
 * though visiting the desk cleared it. The counts are open work; they go down
 * when the work is decided, never because a page was visited. The scoped
 * desks (submissions, routes, data) count the viewing curator's areas; the
 * rest count everything.
 *
 * Each count is read once per request (the tab strip and the account chip ask
 * for the same numbers) and forgotten between requests ({@see reset()}).
 *
 * @see docs/specs/moderation-and-contribution.md §5.0
 * @see docs/specs/translations.md §5
 * @see docs/specs/moderation-and-contribution.md §13.7
 *
 * @api
 */
final class ModerationChromeExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    private ?ModerationScope $scope = null;

    public function __construct(
        private readonly Security $security,
        private readonly DecisionService $decisions,
        private readonly SubmissionQueue $submissions,
        private readonly ModerationScopeProvider $scopes,
        private readonly CuratorRoom $room,
        private readonly SupportRepository $support,
        private readonly RouteQueue $routes,
        private readonly CatalogFindingRepository $findings,
        private readonly MediaTakedownService $takedowns,
    ) {
    }

    #[\Override]
    public function reset(): void
    {
        $this->counts = [];
        $this->scope = null;
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_translation_count', $this->pendingTranslationCount(...)),
            new TwigFunction('pending_submission_count', $this->pendingSubmissionCount(...)),
            new TwigFunction('pending_route_count', $this->pendingRouteCount(...)),
            new TwigFunction('open_data_count', $this->openDataCount(...)),
            new TwigFunction('pending_takedown_count', $this->pendingTakedownCount(...)),
            new TwigFunction('curator_room_unread', $this->curatorRoomUnread(...)),
            new TwigFunction('open_contact_count', $this->openContactCount(...)),
            new TwigFunction('open_bug_count', $this->openBugCount(...)),
            new TwigFunction('open_report_count', $this->openReportCount(...)),
            new TwigFunction('moderation_scope_names', $this->moderationScopeNames(...)),
        ];
    }

    /**
     * The viewing curator's area names for the moderation bar, [] for a
     * curator who sees every area. Read here, not passed by each page, so the
     * bar shows the same line on every desk (moderation-and-contribution.md §9).
     *
     * @return list<string>
     */
    public function moderationScopeNames(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return [];
        }

        return $this->scopes->describe($user);
    }

    /**
     * Unanswered contact messages (contact-and-support.md §8).
     *
     * Unscoped, like the desk itself: a GDPR request has no region, and a
     * badge shared out by geography would leave one sitting behind whichever
     * curator happens to be away.
     */
    public function openContactCount(): int
    {
        return $this->count('contact', fn (): int => $this->support->openMessageCount());
    }

    /** Bugs still costing somebody something (contact-and-support.md §9). */
    public function openBugCount(): int
    {
        return $this->count('bugs', fn (): int => $this->support->openBugCount());
    }

    /**
     * Reports nobody has answered (content-reports.md §9).
     *
     * Unscoped, like takedowns and the inbox. A DSA Article 16 report has a
     * clock on it and no region, so a badge shared out by geography would leave
     * one waiting behind whichever curator happens to be away.
     */
    public function openReportCount(): int
    {
        return $this->count('reports', fn (): int => $this->support->openReportCount());
    }

    public function pendingTranslationCount(): int
    {
        return $this->count('translations', fn (): int => $this->decisions->pendingCount());
    }

    /** Submissions waiting in this curator's areas, needs-info included. */
    public function pendingSubmissionCount(): int
    {
        return $this->count('submissions', fn (User $user): int => $this->submissions->total($this->scope($user)));
    }

    /** Route proposals and open corrections in this curator's areas. */
    public function pendingRouteCount(): int
    {
        return $this->count('routes', function (User $user): int {
            $scope = $this->scope($user);

            return $this->routes->total($scope) + $this->routes->pendingSuggestionCount($scope);
        });
    }

    /** Open data findings in this curator's areas (catalog-data-model.md §5c). */
    public function openDataCount(): int
    {
        return $this->count('data', fn (User $user): int => $this->findings->openCount($this->scope($user)));
    }

    /**
     * Open photo removal requests, unscoped: these are not shared out by
     * jurisdiction (photo-uploads.md §6b).
     */
    public function pendingTakedownCount(): int
    {
        return $this->count('takedowns', fn (): int => $this->takedowns->pendingCount());
    }

    /**
     * Room posts this curator has not opened (§13.7).
     */
    public function curatorRoomUnread(): int
    {
        return $this->count('room', fn (User $user): int => $this->room->unreadCount((int) $user->getId()));
    }

    /**
     * One count for a signed-in curator, read once per request; 0 for anyone
     * else, without a query.
     *
     * @param \Closure(User): int $read
     */
    private function count(string $key, \Closure $read): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return 0;
        }

        return $this->counts[$key] ??= $read($user);
    }

    private function scope(User $user): ModerationScope
    {
        return $this->scope ??= $this->scopes->scopeFor($user);
    }
}
