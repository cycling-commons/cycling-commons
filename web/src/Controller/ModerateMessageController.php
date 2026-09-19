<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Messaging\MessageService;
use App\Moderation\ModerationScopeProvider;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Curator → rider personal message from any desk row.
 *
 * Recipient is resolved from the referenced row, never from the request (IDOR).
 *
 * @see docs/specs/moderation-and-contribution.md §7.4
 *
 * @api
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

        // docs/specs/photo-uploads.md §5b — only this submission's photo; drop others.
        $mediaId = null;
        $rawMediaId = (string) $request->request->get('mediaId', '');
        if ('submission' === $channel && '' !== $rawMediaId && Uuid::isValid($rawMediaId)) {
            $upload = $this->em->find(MediaUpload::class, Uuid::fromString($rawMediaId));
            if (null !== $upload && $upload->getSubmissionId() === $id) {
                $mediaId = $upload->getId();
            }
        }

        /** @var User $curator */
        $curator = $this->getUser();

        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($curator), $regionId)) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        }

        try {
            $sent = $this->messages->sendCurator($recipientId, (int) $curator->getId(), $channel, $id, $refLabel, $body, $mediaId);
            if (null === $sent) {
                // Author/proposer is no-FK and may dangle after account deletion.
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
        // Imported routes carry no proposer.
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
     * Redirect-after-POST: `/moderate` path only — forged Referer must not open-redirect.
     *
     * @see docs/specs/moderation-and-contribution.md §5.1
     */
    private function redirectBack(Request $request, string $channel): Response
    {
        $referer = $request->headers->get('referer');
        if (null !== $referer) {
            $path = parse_url($referer, PHP_URL_PATH);
            // Wildcard locale prefix (same as security.yaml); target must still be /moderate.
            if (\is_string($path) && 1 === preg_match('#^(/[a-z]{2})?/moderate(/|$)#', $path)) {
                $query = parse_url($referer, PHP_URL_QUERY);

                return $this->redirect($path.(\is_string($query) ? '?'.$query : ''));
            }
        }

        return $this->redirectToRoute('submission' === $channel ? 'moderate_submissions' : 'moderate_routes');
    }
}
