<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\TranslationDecisionType;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\MissingQuestionException;
use App\Routing\LocalePrefix;
use App\Translation\CatalogueBrowser;
use App\Translation\DecisionService;
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
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateTranslationsController extends AbstractController
{
    public function __construct(
        private readonly DecisionService $decisions,
        private readonly CatalogueBrowser $browser,
    ) {
    }

    #[Route('/moderate/translations', name: 'moderate_translations', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(TranslationDecisionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{proposal_id: string|int, decision: string, note: ?string} $data */
            $data = $form->getData();
            /** @var User $curator */
            $curator = $this->getUser();

            try {
                $this->decisions->decide(
                    (int) $data['proposal_id'],
                    (string) $data['decision'],
                    $curator,
                    $data['note'] ?? null,
                );
                $this->addFlash('success', 'moderate.translation.flash.'.$data['decision']);
            } catch (MissingQuestionException) {
                $this->addFlash('danger', 'moderate.error.needs_info_note_required');
            } catch (SelfReviewException) {
                $this->addFlash('danger', 'moderate.translation.error.self_review');
            } catch (AlreadyDecidedException) {
                $this->addFlash('danger', 'moderate.translation.error.already_decided');
            } catch (UnknownProposalException) {
                $this->addFlash('danger', 'moderate.translation.error.unknown');
            }

            return $this->redirectToRoute('moderate_translations');
        }

        $cards = [];
        foreach ($this->decisions->openQueue() as $proposal) {
            $entry = $proposal->getEntry();
            $live = $this->browser->liveFor($entry, $proposal->getLocale());
            $englishNow = $entry->getEnglish();
            $cards[] = [
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

        return $this->render('moderate/translations.html.twig', [
            'page_title' => 'meta.moderate_translations_title',
            'page_description' => 'meta.moderate_translations_description',
            'cards' => $cards,
        ]);
    }
}
