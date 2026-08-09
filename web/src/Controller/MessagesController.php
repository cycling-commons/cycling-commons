<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * Renders the authenticated user's messages dashboard: decision outcomes,
 * curator notes, and rider replies, newest first. Also owns the needs-info
 * reply loop (docs/specs/moderation-and-contribution.md §7.3): a rider
 * answering a curator's needs-info request re-queues their submission to
 * `pending`.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
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

    #[Route('/messages', name: 'messages')]
    public function index(Request $request, MessageService $messages, Connection $db, SubmissionChangeSummary $changes): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        // Three shelves and an unread switch (moderation-and-contribution.md
        // §7.9). An unknown ?cat= is treated as no filter rather than as an
        // error: it is a bookmark to a shelf that has been renamed, not an
        // attack, and showing everything is the honest fallback.
        $category = MessageCategory::tryFrom($request->query->getString('cat'));
        $unreadOnly = $request->query->getBoolean('unread');

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $messages->countFor($userId, $category, $unreadOnly),
            $this->pageSize->resolve(MessageService::PER_PAGE),
        );

        // Fetch BEFORE marking read, so the template can still flag which
        // rows were new to this visit via isRead().
        $heads = $messages->listFor($userId, $pager['offset'], $pager['perPage'], $category, $unreadOnly);

        // The reader's own replies, re-attached to the questions on THIS page.
        // listFor() returns heads only so a question and its answer can never
        // be split across a page boundary.
        $list = [...$heads, ...$messages->repliesBySender(
            $userId,
            array_values(array_unique(array_map(
                static fn (UserMessage $m): int => $m->getRefId(),
                array_filter($heads, static fn (UserMessage $m): bool => 'submission' === $m->getChannel()),
            ))),
        )];

        // The reply form only renders for needs-info messages whose
        // submission is STILL `needs_info` — a curator may have decided it
        // again (or the rider already replied) since the message was sent.
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

        // Only what this page actually showed. Marking everything read on a
        // visit would consume the unread state of messages the reader has not
        // reached yet, and the chip would drop to zero over unopened mail.
        $messages->markRead($userId, array_map(
            static fn (UserMessage $m): int => (int) $m->getId(),
            $heads,
        ));

        $answers = self::answersToQuestions($list, $userId);

        return $this->render('messages/index.html.twig', [
            'page_title' => 'meta.messages_title',
            'page_description' => 'meta.messages_description',
            'nav_active' => '',
            'cc_user' => $user,
            // A question and its answer are one exchange, so they render as one
            // card: the replies attached below are dropped from the top level.
            'messages' => array_values(array_filter(
                $list,
                static fn (UserMessage $m): bool => !isset($answers['attached'][(int) $m->getId()]),
            )),
            'answers' => $answers['byQuestion'],
            'replyable_submission_ids' => $replyableSubmissionIds,
            'message_photos' => $this->messagePhotos($list),
            // WHAT the contribution this message is about actually changed.
            // "Your contribution X was approved" names a place and stops; the
            // rider still cannot see which of their own edits it was
            // (owner-reported 2026-08-03).
            'submission_changes' => $this->submissionChanges($list, $userId, $changes),
            'pager' => $pager,
            // The pager must keep the filter it is paging, or page two of
            // "Notices" quietly becomes page two of everything.
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
     * The change rows for every submission these messages refer to, keyed by
     * submission id.
     *
     * **Scoped to the reader's own submissions.** A message is addressed to
     * its recipient, so `refId` should already be theirs — but this reads
     * contribution content out of the database on the strength of an id
     * carried by a row, and "should already be" is not an access rule. The
     * `userId` filter is the access rule.
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
     * Pair each of the reader's own replies with the question it answers.
     *
     * A needs-info question and the answer to it are one exchange; listed as
     * two rows they read as unrelated events, and the answer (newest first)
     * appeared ABOVE the question it belongs to. Pairing is by submission and
     * order: a reply answers the most recent question on the same submission
     * that precedes it, which keeps a second round of ask-and-answer straight.
     *
     * @param list<UserMessage> $list
     *
     * @return array{byQuestion: array<int, list<UserMessage>>, attached: array<int, true>}
     */
    private static function answersToQuestions(array $list, int $userId): array
    {
        // Pairing needs oldest-first, and `$list` is no longer uniformly
        // ordered: it is the page's heads (newest-first) with the reader's own
        // replies appended. Sort rather than reverse — reversing a
        // two-orderings list silently mispairs a second round of ask-and-answer.
        $chronological = $list;
        usort(
            $chronological,
            static fn (UserMessage $a, UserMessage $b): int => [$a->getCreatedAt(), (int) $a->getId()]
                <=> [$b->getCreatedAt(), (int) $b->getId()],
        );
        $openQuestion = [];   // submission id => the question still unanswered
        $byQuestion = [];
        $attached = [];

        foreach ($chronological as $m) {
            if ('submission' !== $m->getChannel()) {
                continue;
            }
            if (UserMessageKind::SubmissionNeedsInfo === $m->getKind()) {
                $openQuestion[$m->getRefId()] = (int) $m->getId();
                continue;
            }
            // Only the reader's OWN replies fold in. A curator reading this
            // page sees the rider's reply as its own row, because to them it
            // is an incoming message, not their half of the exchange.
            if (UserMessageKind::RiderReply === $m->getKind() && $userId === $m->getSenderId()
                && isset($openQuestion[$m->getRefId()])) {
                $byQuestion[$openQuestion[$m->getRefId()]][] = $m;
                $attached[(int) $m->getId()] = true;
            }
        }

        return ['byQuestion' => $byQuestion, 'attached' => $attached];
    }

    /**
     * A rider answers a needs-info request from their own messages page.
     * The reply is delivered to the curator who made the decision, and the
     * submission goes back to `pending` — the queue already lists pending
     * rows, so this alone re-queues it.
     */
    #[Route('/messages/{id}/reply', name: 'messages_reply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reply(int $id, Request $request, EntityManagerInterface $em, MessageService $messages, Connection $db): Response
    {
        if (!$this->isCsrfTokenValid('message-reply', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        $message = $em->find(UserMessage::class, $id);
        if (null === $message || $userId !== $message->getUserId() || UserMessageKind::SubmissionNeedsInfo !== $message->getKind()) {
            throw $this->createNotFoundException();
        }

        $submissionId = $message->getRefId();
        $submission = $em->find(Submission::class, $submissionId);
        $decidingCuratorId = $submission?->getDecidedBy();

        // The submission may have moved on (decided again, or already
        // answered) since the needs-info message was sent — and, in the
        // unlikely case a NeedsInfo submission somehow carries no decider,
        // there is no curator to deliver the reply to either way. Both are
        // the same "too late" outcome for the rider.
        //
        // `submission.decided_by` is also a no-FK column (same family as
        // submission.user_id / route_suggestion.user_id /
        // recommended_route.proposed_by — see MessageService::sendSystem):
        // the curator who asked for more info may have deleted their own
        // account since. There is no other curator to reroute the reply to
        // automatically, and surfacing it nowhere would silently discard the
        // rider's answer, so this is treated as the same "too late" outcome
        // rather than re-queuing with an undeliverable message. Another
        // curator can re-request info if the submission still needs it.
        if (null === $submission || SubmissionStatus::NeedsInfo !== $submission->getStatus() || null === $decidingCuratorId
            || false === $db->fetchOne('SELECT 1 FROM users WHERE id = :id', ['id' => $decidingCuratorId])) {
            $this->addFlash('danger', 'messages.reply_too_late');

            return $this->redirectToRoute('messages');
        }

        try {
            $em->wrapInTransaction(function () use ($messages, $decidingCuratorId, $userId, $submissionId, $submission, $request): void {
                // sendRiderReply flushes internally — fine inside
                // wrapInTransaction, it joins the outer transaction rather
                // than committing early.
                $messages->sendRiderReply($decidingCuratorId, $userId, 'submission', $submissionId, 'SUB-'.$submissionId, (string) $request->request->get('body', ''));
                $submission->setStatus(SubmissionStatus::Pending);
            });
        } catch (\InvalidArgumentException $e) {
            // The message carries a translation key (moderate.error.note_*).
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('messages');
        }

        $this->addFlash('success', 'messages.reply_sent');

        return $this->redirectToRoute('messages');
    }

    /**
     * Thumbnail URL per referenced photo, keyed by uuid string
     * (docs/specs/photo-uploads.md §5b). Built here rather than in the template
     * so the storage router stays the only thing that knows how a photo is
     * addressed. Rows whose photo has since been disposed of simply do not
     * appear, and the message renders without a thumb.
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
            $urls[$upload->getId()->toRfc4122()] = $this->mediaStorage->url(
                $upload->getContinent(),
                $upload->getPathPrefix(),
                'sm',
            );
        }

        return $urls;
    }
}
