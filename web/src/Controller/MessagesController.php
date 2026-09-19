<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Contribution\SubmissionChangeSummary;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaStorage;
use App\Messaging\Entity\UserMessage;
use App\Messaging\MessageCategory;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Pagination\Pager;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rider messages dashboard and the needs-info reply loop.
 *
 * @see docs/specs/moderation-and-contribution.md §7.3
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class MessagesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $mediaStorage,
        private readonly PageSize $pageSize,
    ) {
    }

    #[Route('/account/messages', name: 'messages')]
    public function index(Request $request, MessageService $messages, Connection $db, SubmissionChangeSummary $changes): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        // docs/specs/moderation-and-contribution.md §7.9 — unknown ?cat= shows all.
        $category = MessageCategory::tryFrom($request->query->getString('cat'));
        $unreadOnly = $request->query->getBoolean('unread');

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $messages->countFor($userId, $category, $unreadOnly),
            $this->pageSize->resolve(MessageService::PER_PAGE),
        );

        // Fetch before markRead so this visit can still flag unread rows.
        $heads = $messages->listFor($userId, $pager['offset'], $pager['perPage'], $category, $unreadOnly);

        // Heads only so a question and its answer never split across a page.
        $list = [...$heads, ...$messages->repliesBySender(
            $userId,
            array_values(array_unique(array_map(
                static fn (UserMessage $m): int => $m->getRefId(),
                array_filter($heads, static fn (UserMessage $m): bool => \in_array($m->getChannel(), ['submission', 'correction'], true)),
            ))),
        )];

        // Reply form only while the submission is still needs_info.
        $needsInfoRefIds = array_values(array_unique(array_map(
            static fn (UserMessage $m): int => $m->getRefId(),
            array_filter($list, static fn (UserMessage $m): bool => UserMessageKind::SubmissionNeedsInfo === $m->getKind()),
        )));
        $replyableSubmissionIds = [] !== $needsInfoRefIds
            ? array_map(intval(...), $db->fetchFirstColumn(
                "SELECT id FROM submission WHERE id IN (:ids) AND status = 'needs_info'",
                ['ids' => $needsInfoRefIds],
                ['ids' => ArrayParameterType::INTEGER],
            ))
            : [];

        // docs/specs/moderation-and-contribution.md §7.5a — mark only this page read.
        $messages->markRead($userId, array_map(
            static fn (UserMessage $m): int => (int) $m->getId(),
            $heads,
        ));

        // docs/specs/route-domain.md §7.1: a correction's thread runs both ways,
        // the rider answers the curator who wrote to them, while it is pending.
        $correctionRefIds = array_values(array_unique(array_map(
            static fn (UserMessage $m): int => $m->getRefId(),
            array_filter($list, static fn (UserMessage $m): bool => 'correction' === $m->getChannel()
                && UserMessageKind::CuratorMessage === $m->getKind()),
        )));
        $replyableCorrectionIds = [] !== $correctionRefIds
            ? array_map(intval(...), $db->fetchFirstColumn(
                "SELECT id FROM route_suggestion WHERE id IN (:ids) AND status = 'pending'",
                ['ids' => $correctionRefIds],
                ['ids' => ArrayParameterType::INTEGER],
            ))
            : [];

        $answers = self::answersToQuestions($list, $userId);

        return $this->render('messages/index.html.twig', [
            'page_title' => 'meta.messages_title',
            'page_description' => 'meta.messages_description',
            'nav_active' => '',
            'cc_user' => $user,
            'messages' => array_values(array_filter(
                $list,
                static fn (UserMessage $m): bool => !isset($answers['attached'][(int) $m->getId()]),
            )),
            'answers' => $answers['byQuestion'],
            'replyable_submission_ids' => $replyableSubmissionIds,
            'replyable_correction_ids' => $replyableCorrectionIds,
            'message_photos' => $this->messagePhotos($list),
            'submission_changes' => $this->submissionChanges($list, $userId, $changes),
            'pager' => $pager,
            'pager_params' => array_filter([
                'cat' => $category?->value,
                'unread' => $unreadOnly ? '1' : null,
            ], static fn (?string $v): bool => null !== $v),
            'filter_category' => $category?->value,
            'filter_unread' => $unreadOnly,
            'counts' => $messages->countsFor($userId),
            'categories' => MessageCategory::cases(),
        ]);
    }

    /**
     * Change rows keyed by submission id. `userId` is the access rule — refId is not.
     *
     * @param list<UserMessage> $list
     *
     * @return array<int, list<array{label: string, was: ?string, now: string}>>
     */
    private function submissionChanges(array $list, int $userId, SubmissionChangeSummary $changes): array
    {
        $refIds = array_values(array_unique(array_map(
            static fn (UserMessage $m): int => $m->getRefId(),
            array_filter($list, static fn (UserMessage $m): bool => 'submission' === $m->getChannel()),
        )));
        if ([] === $refIds) {
            return [];
        }

        /** @var list<Submission> $subs */
        $subs = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Submission::class, 's')
            ->where('s.id IN (:ids)')
            ->andWhere('s.userId = :uid')
            ->setParameter('ids', $refIds)
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($subs as $sub) {
            $rows = $changes->rows($sub);
            if ([] !== $rows) {
                $out[(int) $sub->getId()] = $rows;
            }
        }

        return $out;
    }

    /**
     * Pair the reader's own replies with the question they answer.
     *
     * @param list<UserMessage> $list
     *
     * @return array{byQuestion: array<int, list<UserMessage>>, attached: array<int, true>}
     */
    private static function answersToQuestions(array $list, int $userId): array
    {
        // Oldest-first; `$list` mixes newest-first heads with appended replies.
        $chronological = $list;
        usort(
            $chronological,
            static fn (UserMessage $a, UserMessage $b): int => [$a->getCreatedAt(), (int) $a->getId()]
                <=> [$b->getCreatedAt(), (int) $b->getId()],
        );
        $openQuestion = []; // "<channel>:<ref id>" => the message being answered
        $byQuestion = [];
        $attached = [];

        foreach ($chronological as $m) {
            $channel = $m->getChannel();
            if (!\in_array($channel, ['submission', 'correction'], true)) {
                continue;
            }
            $thread = $channel.':'.$m->getRefId();
            // What the rider answers: a needs-info question on a submission, a
            // curator's message on a correction (docs/specs/route-domain.md §7.1).
            $isQuestion = 'submission' === $channel
                ? UserMessageKind::SubmissionNeedsInfo === $m->getKind()
                : UserMessageKind::CuratorMessage === $m->getKind();
            if ($isQuestion) {
                $openQuestion[$thread] = (int) $m->getId();
                continue;
            }
            // Only the reader's own replies fold in.
            if (UserMessageKind::RiderReply === $m->getKind() && $userId === $m->getSenderId()
                && isset($openQuestion[$thread])) {
                $byQuestion[$openQuestion[$thread]][] = $m;
                $attached[(int) $m->getId()] = true;
            }
        }

        return ['byQuestion' => $byQuestion, 'attached' => $attached];
    }

    /**
     * The rider's side of a moderation thread: answering a needs-info question
     * (the submission returns to `pending`), or answering a curator's message
     * about a route correction while that correction is still open.
     *
     * @see docs/specs/moderation-and-contribution.md §7.3
     * @see docs/specs/route-domain.md §7.1
     */
    #[Route('/account/messages/{id}/reply', name: 'messages_reply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reply(int $id, Request $request, EntityManagerInterface $em, MessageService $messages, Connection $db): Response
    {
        if (!$this->isCsrfTokenValid('message-reply', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        $message = $em->find(UserMessage::class, $id);
        if (null === $message || $userId !== $message->getUserId()) {
            throw $this->createNotFoundException();
        }

        // A correction's thread: the rider answers the curator who wrote to
        // them, on the same channel, while the correction is still open. No
        // status to flip, so nothing but the message moves.
        if ('correction' === $message->getChannel() && UserMessageKind::CuratorMessage === $message->getKind()) {
            $suggestionId = $message->getRefId();
            $curatorId = $message->getSenderId();
            $stillOpen = null !== $curatorId
                && false !== $db->fetchOne('SELECT 1 FROM route_suggestion WHERE id = :id AND status = :s', ['id' => $suggestionId, 's' => 'pending'])
                && false !== $db->fetchOne('SELECT 1 FROM users WHERE id = :id', ['id' => $curatorId]);
            if (!$stillOpen) {
                $this->addFlash('danger', 'messages.reply_too_late');

                return $this->redirectToRoute('messages');
            }
            try {
                $messages->sendRiderReply($curatorId, $userId, 'correction', $suggestionId, $message->getRefLabel(), (string) $request->request->get('body', ''));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());

                return $this->redirectToRoute('messages');
            }
            $this->addFlash('success', 'messages.reply_sent');

            return $this->redirectToRoute('messages');
        }

        if (UserMessageKind::SubmissionNeedsInfo !== $message->getKind()) {
            throw $this->createNotFoundException();
        }

        $submissionId = $message->getRefId();
        $submission = $em->find(Submission::class, $submissionId);
        $decidingCuratorId = $submission?->getDecidedBy();

        // Too late: decided again, already answered, or the decider's account is gone.
        if (null === $submission || SubmissionStatus::NeedsInfo !== $submission->getStatus() || null === $decidingCuratorId
            || false === $db->fetchOne('SELECT 1 FROM users WHERE id = :id', ['id' => $decidingCuratorId])) {
            $this->addFlash('danger', 'messages.reply_too_late');

            return $this->redirectToRoute('messages');
        }

        try {
            $em->wrapInTransaction(function () use ($messages, $decidingCuratorId, $userId, $submissionId, $submission, $request): void {
                $messages->sendRiderReply($decidingCuratorId, $userId, 'submission', $submissionId, 'SUB-'.$submissionId, (string) $request->request->get('body', ''));
                $submission->setStatus(SubmissionStatus::Pending);
            });
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('messages');
        }

        $this->addFlash('success', 'messages.reply_sent');

        return $this->redirectToRoute('messages');
    }

    /**
     * Thumbnail URL per referenced photo.
     *
     * @see docs/specs/photo-uploads.md §5b
     *
     * @param list<UserMessage> $messages
     *
     * @return array<string, string>
     */
    private function messagePhotos(array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            $mediaId = $message->getMediaId();
            if (null !== $mediaId) {
                $ids[] = $mediaId;
            }
        }
        if ([] === $ids) {
            return [];
        }

        $urls = [];
        foreach ($this->em->getRepository(MediaUpload::class)->findBy(['id' => $ids]) as $upload) {
            if (!$upload->hasPublishedObjects()) {
                continue;
            }
            $urls[$upload->getId()->toRfc4122()] = $this->mediaStorage->url(
                $upload->getStorageBucket(),
                $upload->getPathPrefix(),
                'sm',
            );
        }

        return $urls;
    }
}
