<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
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
 * Renders the authenticated user's messages dashboard (moderation-feedback
 * spec M3): decision outcomes, curator notes, and rider replies, newest
 * first. Also owns the needs-info reply loop (spec M6b): a rider answering a
 * curator's needs-info request re-queues their submission to `pending`.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class MessagesController extends AbstractController
{
    #[Route('/messages', name: 'messages')]
    public function index(MessageService $messages, Connection $db): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        // Fetch BEFORE marking read, so the template can still flag which
        // rows were new to this visit via isRead().
        $list = $messages->listFor($userId);

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

        $messages->markAllRead($userId);

        return $this->render('messages/index.html.twig', [
            'page_title' => 'meta.messages_title',
            'page_description' => 'meta.messages_description',
            'nav_active' => '',
            'cc_user' => $user,
            'messages' => $list,
            'replyable_submission_ids' => $replyableSubmissionIds,
        ]);
    }

    /**
     * A rider answers a needs-info request from their own messages page.
     * The reply is delivered to the curator who made the decision, and the
     * submission goes back to `pending` — the queue already lists pending
     * rows, so this alone re-queues it.
     */
    #[Route('/messages/{id}/reply', name: 'messages_reply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reply(int $id, Request $request, EntityManagerInterface $em, MessageService $messages): Response
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
        if (null === $submission || SubmissionStatus::NeedsInfo !== $submission->getStatus() || null === $decidingCuratorId) {
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
}
