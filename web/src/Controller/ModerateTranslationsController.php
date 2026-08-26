<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\TranslationDecisionType;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\MissingQuestionException;
use App\Moderation\ModerationScopeProvider;
use App\Routing\LocalePrefix;
use App\Translation\CatalogueBrowser;
use App\Translation\DecisionService;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\SelfReviewException;
use App\Translation\Exception\UnknownProposalException;
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

    #[Route('/moderate/translations/{id}', name: 'moderate_translations_detail', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function detail(int $id, Request $request): Response
    {
        try {
            $proposal = $this->decisions->getOpen($id);
        } catch (UnknownProposalException) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(TranslationDecisionType::class, [
            'proposal_id' => (string) $id,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{proposal_id: string|int, decision: string, note: ?string} $data */
            $data = $form->getData();
            /** @var User $curator */
            $curator = $this->getUser();

            try {
                $this->decisions->decide(
                    $id,
                    (string) $data['decision'],
                    $curator,
                    $data['note'] ?? null,
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
     * @return array{
     *     proposal: TranslationProposal,
     *     message_key: string,
     *     english_at_submit: string,
     *     english_now: string,
     *     english_changed: bool,
     *     live: string,
     *     proposed: string,
     *     locale: string,
     *     status: string
     * }
     */
    private function detailCard(TranslationProposal $proposal): array
    {
        $entry = $proposal->getEntry();
        $live = $this->browser->liveFor($entry, $proposal->getLocale());
        $englishNow = $entry->getEnglish();

        return [
            'proposal' => $proposal,
            'message_key' => $entry->getMessageKey(),
            'english_at_submit' => $proposal->getEnglishAtSubmit(),
            'english_now' => $englishNow,
            'english_changed' => $proposal->getEnglishAtSubmit() !== $englishNow,
            'live' => $live['live'],
            'proposed' => $proposal->getProposedValue(),
            'locale' => $proposal->getLocale(),
            'status' => $proposal->getStatus()->value,
        ];
    }
}
