<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Pagination\Pager;
use App\Routing\LocalePrefix;
use App\Support\ContactStatus;
use App\Support\ContactTopic;
use App\Support\Entity\ContactMessage;
use App\Support\SupportMailer;
use App\Support\SupportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The inbox: everything a person wrote to us through the contact form.
 *
 * **Unscoped, like takedowns.** A moderator's region assignment
 * ({@see \App\Moderation\ModerationScopeProvider}) shares out editorial work by
 * geography, which is the right way to split "is this water tap real". It is
 * the wrong way to split "I want my data deleted": a GDPR request has no
 * region, and a message that only the curator for Wallonia can see is a message
 * that goes unanswered whenever that curator is on holiday.
 *
 * **Not a ticket system, and it must not become one.** There are four statuses
 * and one internal note. The reply itself goes by mail, from a person, because
 * a support tool that grows threading and templates and canned answers is a
 * tool a small project stops using within a month.
 *
 * **The clock is the point.** The privacy page promises an answer within one
 * month; DSA Article 16 expects timely and non-arbitrary handling of a report.
 * The desk sorts by that deadline, not by arrival, and shows what is overdue in
 * a way that cannot be scrolled past.
 *
 * @see docs/specs/contact-and-support.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateInboxController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'inbox-handle';
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupportRepository $repository,
        private readonly SupportMailer $mailer,
    ) {
    }

    #[Route('/moderate/inbox', name: 'moderate_inbox', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = ContactStatus::tryFrom((string) $request->query->get('status', ''));
        $topic = ContactTopic::tryFrom((string) $request->query->get('topic', ''));

        // No filter at all means "what needs me", not "everything ever". A desk
        // whose default view is the full archive is a desk where the thing that
        // arrived this morning is on page four.
        $showing = null === $status && null === $topic ? ContactStatus::New : $status;

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $this->repository->countMessages($showing, $topic),
            self::PER_PAGE,
        );

        return $this->render('moderate/inbox.html.twig', [
            'page_title' => 'support.inbox.title',
            'page_description' => 'support.inbox.title',
            'nav_active' => '',
            'active' => 'moderate_inbox',
            'messages' => $this->repository->messages($showing, $topic, $pager['perPage'], $pager['offset']),
            'counts' => $this->repository->messageCountsByStatus(),
            'overdue' => $this->repository->overdueMessageCount(new \DateTimeImmutable()),
            'statuses' => ContactStatus::all(),
            'topics' => ContactTopic::all(),
            'filter_status' => $showing?->value,
            'filter_topic' => $topic?->value,
            'pager' => $pager,
            'now' => new \DateTimeImmutable(),
        ]);
    }

    /**
     * Move one message, and optionally leave a note about what was done.
     *
     * The note is internal and always optional except when closing without an
     * answer: "closed" with no reason is indistinguishable from "forgotten"
     * three months later, and this desk is the evidence that a legal deadline
     * was met.
     */
    #[Route('/moderate/inbox/handle', name: 'moderate_inbox_handle', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('notice', 'flash.invalid_token');

            return $this->redirectToRoute('moderate_inbox');
        }

        $message = $this->em->find(ContactMessage::class, $request->request->getInt('id'));
        if (!$message instanceof ContactMessage) {
            $this->addFlash('notice', 'support.inbox.flash_gone');

            return $this->redirectToRoute('moderate_inbox');
        }

        $status = ContactStatus::tryFrom((string) $request->request->get('status', ''));
        if (null === $status) {
            $this->addFlash('notice', 'support.inbox.flash_bad_status');

            return $this->redirectToRoute('moderate_inbox');
        }

        $note = trim((string) $request->request->get('note', ''));
        if (ContactStatus::Closed === $status && '' === $note && null === $message->getHandlingNote()) {
            $this->addFlash('notice', 'support.inbox.flash_close_needs_note');

            return $this->redirectToRoute('moderate_inbox', ['status' => $message->getStatus()->value]);
        }

        if ('' !== $note) {
            $message->setHandlingNote($note);
        }
        $message->setStatus($status);

        $curator = $this->getUser();
        if ($curator instanceof User) {
            $message->setHandledByUserId($curator->getId());
        }

        $this->em->flush();
        $this->addFlash('notice', 'support.inbox.flash_saved');

        return $this->redirectToRoute('moderate_inbox', ['status' => $request->request->get('return_to') ?: null]);
    }

    /**
     * Read one message on its own page.
     *
     * Separate from the list because a message can be long, and because the
     * reference number in the acknowledgement has to lead somewhere a curator
     * can be sent by a link.
     */
    #[Route('/moderate/inbox/{id}', name: 'moderate_inbox_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $message = $this->em->find(ContactMessage::class, $id);
        if (!$message instanceof ContactMessage) {
            throw $this->createNotFoundException();
        }

        return $this->render('moderate/inbox_detail.html.twig', [
            'page_title' => 'support.inbox.title',
            'page_description' => 'support.inbox.title',
            'nav_active' => '',
            'active' => 'moderate_inbox',
            'message' => $message,
            'reference' => $this->mailer->contactReference($message),
            'statuses' => ContactStatus::all(),
            'now' => new \DateTimeImmutable(),
        ]);
    }
}
