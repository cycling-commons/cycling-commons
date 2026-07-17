<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Moderation\ModerationScopeProvider;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One endpoint for a curator to send a rider a free-form personal message
 * from any desk row (moderation-feedback spec M6a) — the pending-submission
 * queue, a route-correction row, and a route's detail page all post here
 * with a `channel` discriminator. The `^/moderate` firewall rule enforces
 * ROLE_CURATOR (security.yaml); the recipient is always resolved
 * server-side from the referenced row, never trusted from the request.
 *
 * Pseudonymity (spec constraint): nothing about the sending curator's
 * identity is exposed here beyond `sender='curator'` — MessageService never
 * receives a display name, only the curator's id for audit purposes.
 *
 * @api Instantiated by Symfony's router.
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateMessageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageService $messages,
        private readonly ModerationScopeProvider $scopeProvider,
    ) {
    }

    #[Route('/moderate/message', name: 'moderate_message', methods: ['POST'])]
    public function send(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('moderate-message', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $channel = (string) $request->request->get('channel');
        $id = (int) $request->request->get('id');
        $body = (string) $request->request->get('body');
        $back = $this->redirectBack($request, $channel);

        $recipient = match ($channel) {
            'submission' => $this->resolveSubmission($id),
            'route' => $this->resolveRoute($id),
            'correction' => $this->resolveCorrection($id),
            default => null,
        };

        if (null === $recipient) {
            $this->addFlash('danger', 'moderate.error.no_recipient');

            return $back;
        }

        [$recipientId, $refLabel, $regionId] = $recipient;

        /** @var User $curator */
        $curator = $this->getUser();

        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($curator), $regionId)) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        }

        try {
            $sent = $this->messages->sendCurator($recipientId, (int) $curator->getId(), $channel, $id, $refLabel, $body);
            if (null === $sent) {
                // The referenced row's author/proposer id is a no-FK column
                // (submission.user_id / route_suggestion.user_id /
                // recommended_route.proposed_by) that can dangle after
                // account deletion — same "nobody to message" outcome as an
                // imported route's null proposer, above.
                $this->addFlash('danger', 'moderate.error.no_recipient');
            } else {
                $this->addFlash('success', 'moderate.msg.sent');
            }
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'moderate.msg.error');
        }

        return $back;
    }

    /** @return array{0: int, 1: string, 2: ?int}|null */
    private function resolveSubmission(int $id): ?array
    {
        $submission = $this->em->find(Submission::class, $id);
        if (null === $submission) {
            return null;
        }

        return [$submission->getUserId(), 'SUB-'.$id, $submission->getRegionId()];
    }

    /** @return array{0: int, 1: string, 2: ?int}|null */
    private function resolveRoute(int $id): ?array
    {
        $route = $this->em->find(RecommendedRoute::class, $id);
        // Imported routes carry no proposer: nobody to message.
        if (null === $route || null === $route->getProposedBy()) {
            return null;
        }

        return [$route->getProposedBy(), $route->getName(), $route->getRegionId()];
    }

    /** @return array{0: int, 1: string, 2: ?int}|null */
    private function resolveCorrection(int $id): ?array
    {
        $suggestion = $this->em->find(RouteSuggestion::class, $id);
        if (null === $suggestion) {
            return null;
        }

        $route = $this->em->find(RecommendedRoute::class, $suggestion->getRouteId());
        $routeName = $route?->getName() ?? sprintf('route-%d', $suggestion->getRouteId());

        return [$suggestion->getUserId(), $routeName, $route?->getRegionId()];
    }

    /**
     * Redirect-after-POST (moderation-and-contribution.md §5.1 pattern): send
     * the curator back to the desk row they messaged from, filters intact.
     * Only the *path* component of the Referer is trusted (host/scheme are
     * discarded), and only when it points back into `/moderate` — this rules
     * out an open redirect via a forged Referer header. Falls back to the
     * channel's own desk route.
     */
    private function redirectBack(Request $request, string $channel): Response
    {
        $referer = $request->headers->get('referer');
        if (null !== $referer) {
            $path = parse_url($referer, PHP_URL_PATH);
            if (\is_string($path) && 1 === preg_match('#^(/(fr|nl|de))?/moderate(/|$)#', $path)) {
                $query = parse_url($referer, PHP_URL_QUERY);

                return $this->redirect($path.(\is_string($query) ? '?'.$query : ''));
            }
        }

        return $this->redirectToRoute('submission' === $channel ? 'moderate' : 'moderate_routes');
    }
}
