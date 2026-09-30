<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Moderation\DeskSeen;
use App\Moderation\SeenSubject;
use App\Translation\StaleIndex;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records that a curator opened a desk item, from the one table of what
 * counts as opening one (moderation-and-contribution.md §5.2f):
 *
 *   - its own page loads: a report, a bug, a translation proposal, a route
 *     (whose page is also its edit form, and lists the route's open
 *     corrections in full, so those count as opened too);
 *   - its edit form loads: a stale translation's form in that locale, or the
 *     place form a queue card links to (`?sub=<submission id>`);
 *   - it is decided: a submission, a route, a correction, a report, a bug, a
 *     proposal, a Data finding, a removal request.
 *
 * Only after the page or the decision went through (a 2xx or a redirect): a
 * refused, out-of-area or missing item stays unopened. A decision that
 * settles the item takes the bar off for every curator anyway
 * ({@see DeskSeen::unseenAmong()}); one that leaves it waiting (a
 * needs-info, a bug moved to Planned) counts as this curator's opening. Opening a pending
 * submission or a Data finding on the map posts to `moderate_seen` instead
 * ({@see \App\Controller\ModerateSeenController}). No list is in the table,
 * so loading a list marks nothing.
 *
 * @see docs/specs/moderation-and-contribution.md §5.2f
 *
 * @api
 */
final readonly class DeskOpenedSubscriber implements EventSubscriberInterface
{
    /**
     * Route name => [kind, where its id is: `attr:<name>` a route attribute,
     * `query:<name>` a query parameter, `post:<name>` a form field or
     * `post:<form>[<field>]` one inside a named form].
     *
     * @var array<string, array{0: SeenSubject, 1: string}>
     */
    private const array OPENS = [
        'moderate_reports_detail' => [SeenSubject::ContentReport, 'attr:id'],
        'moderate_reports_decide' => [SeenSubject::ContentReport, 'attr:id'],
        'moderate_bugs_detail' => [SeenSubject::BugReport, 'attr:id'],
        'moderate_bugs_decide' => [SeenSubject::BugReport, 'attr:id'],
        'moderate_translations_detail' => [SeenSubject::TranslationProposal, 'attr:id'],
        'moderate_routes_detail' => [SeenSubject::Route, 'attr:id'],
        'moderate_routes_decide' => [SeenSubject::Route, 'post:route_decision[route_id]'],
        'moderate_routes_suggestion' => [SeenSubject::RouteSuggestion, 'post:suggestion_id'],
        'moderate_decide' => [SeenSubject::Submission, 'post:moderation_decision[submission_id]'],
        'moderate_data_decide' => [SeenSubject::CatalogFinding, 'post:finding'],
        'moderate_takedown' => [SeenSubject::Takedown, 'post:media'],
        'moderate_escalate' => [SeenSubject::Takedown, 'post:media'],
        'improve' => [SeenSubject::Submission, 'query:sub'],
        'translate_edit' => [SeenSubject::TranslationStale, 'attr:id'],
    ];

    public function __construct(
        private Security $security,
        private DeskSeen $seen,
        private Connection $db,
        private StaleIndex $stale,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
        if (!\is_string($route) || !isset(self::OPENS[$route])) {
            return;
        }
        $response = $event->getResponse();
        if (!$response->isSuccessful() && !$response->isRedirection()) {
            return;
        }
        [$subject, $where] = self::OPENS[$route];
        $id = self::id($request, $where);
        if (null === $id) {
            return;
        }
        // The item first, the reader second: no other request touches the
        // session here.
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->getId() || !$this->security->isGranted('ROLE_CURATOR')) {
            return;
        }
        $userId = (int) $user->getId();

        match ($route) {
            'moderate_routes_detail' => $this->openRoute($userId, (int) $id),
            'translate_edit' => $this->openStale($userId, (int) $id, $request->getLocale()),
            'improve' => $this->openSubmission($userId, (int) $id),
            default => $this->seen->mark($userId, $subject, $id),
        };
    }

    /** A route's desk page shows the route and every open correction on it. */
    private function openRoute(int $userId, int $routeId): void
    {
        $this->seen->mark($userId, SeenSubject::Route, $routeId);
        $this->seen->markAll($userId, SeenSubject::RouteSuggestion, array_map(
            intval(...),
            $this->db->fetchFirstColumn("SELECT id FROM route_suggestion WHERE route_id = :id AND status = 'pending'", ['id' => $routeId]),
        ));
    }

    /** A key's form in a locale where it is stale opens that stale row. */
    private function openStale(int $userId, int $entryId, string $locale): void
    {
        if (!$this->stale->isStale($entryId, $locale)) {
            return;
        }
        $version = $this->db->fetchOne('SELECT english_version FROM translation_entry WHERE id = :id', ['id' => $entryId]);
        if (false !== $version && null !== $version) {
            $this->seen->mark($userId, SeenSubject::TranslationStale, SeenSubject::staleId($locale, $entryId, (int) $version));
        }
    }

    private function openSubmission(int $userId, int $submissionId): void
    {
        if (false !== $this->db->fetchOne('SELECT 1 FROM submission WHERE id = :id', ['id' => $submissionId])) {
            $this->seen->mark($userId, SeenSubject::Submission, $submissionId);
        }
    }

    /** The item's id where the table says it is, or null when there is none. */
    private static function id(Request $request, string $where): int|string|null
    {
        [$bag, $name] = explode(':', $where, 2);
        if (1 === preg_match('/^(\w+)\[(\w+)\]$/', $name, $m)) {
            $form = $request->request->all()[$m[1]] ?? null;
            $raw = \is_array($form) ? ($form[$m[2]] ?? null) : null;
        } else {
            $raw = match ($bag) {
                'attr' => $request->attributes->get($name),
                'query' => $request->query->all()[$name] ?? null,
                default => $request->request->all()[$name] ?? null,
            };
        }
        if (\is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (!\is_string($raw)) {
            return null;
        }
        if (1 === preg_match('/^[1-9]\d{0,17}$/', $raw)) {
            return (int) $raw;
        }

        // A report or a photo is named by a UUID.
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($raw)) ? strtolower($raw) : null;
    }
}
