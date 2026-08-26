<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\RiderPseudonym;
use App\Entity\User;
use App\Form\TranslationDecisionType;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\MissingQuestionException;
use App\Moderation\ModerationScopeProvider;
use App\Pagination\Pager;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use App\Translation\CatalogueBrowser;
use App\Translation\DecisionService;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\EmptyTranslationException;
use App\Translation\Exception\SelfReviewException;
use App\Translation\Exception\TranslationTooLongException;
use App\Translation\Exception\UnknownProposalException;
use App\Translation\TranslationDiff;
use App\Translation\TranslationLimits;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Unscoped translation curator desk (translations.md §5).
 *
 * The queue is a scan. Decisions live on the detail page.
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateTranslationsController extends AbstractController
{
    public function __construct(
        private readonly DecisionService $decisions,
        private readonly CatalogueBrowser $browser,
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly EntityManagerInterface $em,
        private readonly PageSize $pageSize,
    ) {
    }

    #[Route('/moderate/translations', name: 'moderate_translations', methods: ['GET'])]
    public function index(): Response
    {
        $cards = [];
        foreach ($this->decisions->openQueue() as $proposal) {
            $entry = $proposal->getEntry();
            $cards[] = [
                'proposal' => $proposal,
                'id' => (int) $proposal->getId(),
                'message_key' => $entry->getMessageKey(),
                'locale' => $proposal->getLocale(),
                'status' => $proposal->getStatus()->value,
            ];
        }

        /** @var User $curator */
        $curator = $this->getUser();

        return $this->render('moderate/translations.html.twig', [
            'page_title' => 'meta.moderate_translations_title',
            'page_description' => 'meta.moderate_translations_description',
            'cards' => $cards,
            ...$this->chrome($curator),
        ]);
    }

    #[Route('/moderate/translations/history', name: 'moderate_translations_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        $perPage = $this->pageSize->resolve(25);
        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $this->decisions->settledCount(),
            $perPage,
        );
        $cards = [];
        foreach ($this->decisions->settled($pager['offset'], $pager['perPage']) as $proposal) {
            $cards[] = $this->settledCard($proposal);
        }

        /** @var User $curator */
        $curator = $this->getUser();

        return $this->render('moderate/translations_history.html.twig', [
            'page_title' => 'meta.moderate_translations_title',
            'page_description' => 'meta.moderate_translations_description',
            'cards' => $cards,
            'pager' => $pager,
            ...$this->chrome($curator),
        ]);
    }

    #[Route('/moderate/translations/{id}', name: 'moderate_translations_detail', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function detail(int $id, Request $request): Response
    {
        try {
            $proposal = $this->decisions->get($id);
        } catch (UnknownProposalException) {
            throw $this->createNotFoundException();
        }

        $open = $this->isOpen($proposal);
        if (!$open) {
            if ($request->isMethod('POST')) {
                $this->addFlash('danger', 'moderate.translation.error.already_decided');

                return $this->redirectToRoute('moderate_translations_detail', ['id' => $id]);
            }

            /** @var User $curator */
            $curator = $this->getUser();

            return $this->render('moderate/translation_detail.html.twig', [
                'page_title' => 'meta.moderate_translations_title',
                'page_description' => 'meta.moderate_translations_description',
                'card' => $this->detailCard($proposal),
                'proposed_max' => TranslationLimits::PROPOSED_VALUE_MAX,
                ...$this->chrome($curator),
            ]);
        }

        $form = $this->createForm(TranslationDecisionType::class, [
            'proposal_id' => (string) $id,
            'published' => $proposal->getProposedValue(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{proposal_id: string|int, decision: string, note: ?string, published: ?string} $data */
            $data = $form->getData();
            /** @var User $curator */
            $curator = $this->getUser();

            try {
                $this->decisions->decide(
                    $id,
                    (string) $data['decision'],
                    $curator,
                    $data['note'] ?? null,
                    $data['published'] ?? null,
                );
                $this->addFlash('success', 'moderate.translation.flash.'.$data['decision']);

                return $this->redirectToRoute('moderate_translations');
            } catch (MissingQuestionException) {
                $this->addFlash('danger', 'moderate.error.needs_info_note_required');
            } catch (SelfReviewException) {
                $this->addFlash('danger', 'moderate.translation.error.self_review');
            } catch (AlreadyDecidedException) {
                $this->addFlash('danger', 'moderate.translation.error.already_decided');

                return $this->redirectToRoute('moderate_translations');
            } catch (EmptyTranslationException) {
                $this->addFlash('danger', 'translate.error.empty');
            } catch (TranslationTooLongException) {
                $this->addFlash('danger', 'translate.error.too_long');
            } catch (UnknownProposalException) {
                throw $this->createNotFoundException();
            }

            return $this->redirectToRoute('moderate_translations_detail', ['id' => $id]);
        }

        /** @var User $curator */
        $curator = $this->getUser();

        return $this->render('moderate/translation_detail.html.twig', [
            'page_title' => 'meta.moderate_translations_title',
            'page_description' => 'meta.moderate_translations_description',
            'card' => $this->detailCard($proposal),
            'proposed_max' => TranslationLimits::PROPOSED_VALUE_MAX,
            ...$this->chrome($curator),
        ]);
    }

    /**
     * @return array{mod_scope_names: list<string>}
     */
    private function chrome(User $curator): array
    {
        $scope = $this->scopeProvider->scopeFor($curator);

        return [
            'mod_scope_names' => $this->scopeProvider->describe($curator, $scope),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailCard(TranslationProposal $proposal): array
    {
        $entry = $proposal->getEntry();
        $live = $this->browser->liveFor($entry, $proposal->getLocale());
        $open = $this->isOpen($proposal);
        $published = TranslationProposalStatus::Approved === $proposal->getStatus()
            ? $proposal->getPublishedValue()
            : null;
        $proposed = $proposal->getProposedValue();

        $yamlDefault = $live['yaml_default'];
        $story = $this->story($proposal, $yamlDefault);

        return [
            'proposal' => $proposal,
            'open' => $open,
            'message_key' => $entry->getMessageKey(),
            'english' => $story['english'],
            'original' => $story['original'],
            'past' => $story['past'],
            'pending' => $story['pending'],
            'proposed' => $proposed,
            'published' => $published,
            'edited' => null !== $published && $published !== $proposed,
            'note' => $proposal->getReviewerNote(),
            'reviewer' => null !== $proposal->getReviewerId() ? $this->who($proposal->getReviewerId()) : null,
            'locale' => $proposal->getLocale(),
            'status' => $proposal->getStatus()->value,
            'who' => $this->who($proposal->getSubmitterId()),
        ];
    }

    private function isOpen(TranslationProposal $proposal): bool
    {
        return \in_array($proposal->getStatus(), [
            TranslationProposalStatus::Pending,
            TranslationProposalStatus::NeedsInfo,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function settledCard(TranslationProposal $proposal): array
    {
        $status = $proposal->getStatus();
        $proposed = $proposal->getProposedValue();
        $published = TranslationProposalStatus::Approved === $status ? $proposal->getPublishedValue() : null;

        return [
            'id' => (int) $proposal->getId(),
            'message_key' => $proposal->getEntry()->getMessageKey(),
            'locale' => $proposal->getLocale(),
            'status' => $status->value,
            'proposed' => $proposed,
            'published' => $published,
            'edited' => null !== $published && $published !== $proposed,
            'note' => $proposal->getReviewerNote(),
            'when' => $proposal->getCreatedAt(),
            'who' => $this->who($proposal->getSubmitterId()),
        ];
    }

    /**
     * English, YAML original, then each published change oldest-first.
     * A pending or rejected row being reviewed is appended last.
     *
     * @return array{english: string, original: string, past: list<array<string, mixed>>, pending: array<string, mixed>|null}
     */
    private function story(TranslationProposal $current, string $yamlDefault): array
    {
        $approved = $this->decisions->approvedHistory($current->getEntry(), $current->getLocale());
        $english = [] !== $approved
            ? $approved[0]->getEnglishAtSubmit()
            : $current->getEnglishAtSubmit();
        $prev = $yamlDefault;
        $prevEnglish = [] !== $approved ? $approved[0]->getEnglishAtSubmit() : $current->getEnglishAtSubmit();
        $past = [];

        foreach ($approved as $i => $pastProposal) {
            $en = $pastProposal->getEnglishAtSubmit();
            if (0 !== $i && $en !== $prevEnglish) {
                $past[] = [
                    'kind' => 'english',
                    'text' => $en,
                ];
            }
            $prevEnglish = $en;
            // getPublishedValue() already falls back to the proposal, so there
            // is nothing here to coalesce against.
            $published = $pastProposal->getPublishedValue();
            $proposed = $pastProposal->getProposedValue();
            $past[] = $this->changeRow(
                $pastProposal,
                $prev,
                $published,
                open: false,
                when: $pastProposal->getDecidedAt(),
                edit: $published !== $proposed
                    ? [
                        'diff' => TranslationDiff::words($proposed, $published),
                        'who' => $this->who($pastProposal->getReviewerId()),
                    ]
                    : null,
            );
            $prev = $published;
        }

        $pending = null;
        if ($this->isOpen($current) || TranslationProposalStatus::Rejected === $current->getStatus()) {
            $en = $current->getEnglishAtSubmit();
            if ($en !== $prevEnglish) {
                $past[] = [
                    'kind' => 'english',
                    'text' => $en,
                ];
            }
            $pending = $this->changeRow(
                $current,
                $prev,
                $current->getProposedValue(),
                open: $this->isOpen($current),
                when: $current->getCreatedAt(),
                edit: null,
            );
        }

        return [
            'english' => $english,
            'original' => $yamlDefault,
            'past' => $past,
            'pending' => $pending,
        ];
    }

    /**
     * @param array{diff: list<array{type: string, text: string}>, who: array{anonymous: bool, handle: string, name: ?string, profile_url: ?string}}|null $edit
     *
     * @return array<string, mixed>
     */
    private function changeRow(
        TranslationProposal $proposal,
        string $from,
        string $to,
        bool $open,
        ?\DateTimeImmutable $when,
        ?array $edit,
    ): array {
        return [
            'kind' => 'change',
            'when' => $when,
            'who' => $this->who($proposal->getSubmitterId()),
            'diff' => TranslationDiff::words($from, $to),
            'open' => $open,
            'status' => $proposal->getStatus()->value,
            'edit' => $edit,
            'note' => $proposal->getReviewerNote(),
        ];
    }

    /**
     * @return array{anonymous: bool, handle: string, name: ?string, profile_url: ?string}
     */
    private function who(?int $userId): array
    {
        $anon = ['anonymous' => true, 'handle' => '', 'name' => null, 'profile_url' => null];
        if (null === $userId) {
            return $anon;
        }
        $user = $this->em->find(User::class, $userId);
        if (!$user instanceof User) {
            return $anon;
        }
        $uuid = $user->isPublicProfile() ? $user->getUuid()?->toRfc4122() : null;

        return [
            'anonymous' => false,
            'handle' => RiderPseudonym::for((int) $user->getId()),
            'name' => null !== $uuid ? $user->getDisplayName() : null,
            'profile_url' => null !== $uuid ? $this->generateUrl('rider_profile', ['uuid' => $uuid]) : null,
        ];
    }
}
