<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\TranslationProposalType;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use App\Translation\CatalogueBrowser;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Exception\ConsentRequiredException;
use App\Translation\Exception\EmptyTranslationException;
use App\Translation\Exception\EnglishNotTranslatableException;
use App\Translation\Exception\KeyNotFoundException;
use App\Translation\ProposalService;
use App\Translation\TranslationLimits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rider /translate browser and proposal form (translations.md §4).
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class TranslateController extends AbstractController
{
    public function __construct(
        private readonly CatalogueBrowser $browser,
        private readonly ProposalService $proposals,
        private readonly PageSize $pageSize,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/translate', name: 'translate', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $locale = $request->getLocale();

        if (!TranslationLimits::isTranslatableLocale($locale)) {
            return $this->render('translate/index.html.twig', [
                'page_title' => 'meta.translate_title',
                'page_description' => 'meta.translate_description',
                'nav_active' => 'contribute',
                'chooser' => true,
                'locales' => TranslationLimits::LOCALES,
            ]);
        }

        $q = $request->query->getString('q');
        $result = $this->browser->search(
            $q,
            $locale,
            $request->query->getInt('page', 1),
            $this->pageSize->resolve(CatalogueBrowser::PER_PAGE),
        );

        return $this->render('translate/index.html.twig', [
            'page_title' => 'meta.translate_title',
            'page_description' => 'meta.translate_description',
            'nav_active' => 'contribute',
            'chooser' => false,
            'q' => $q,
            'rows' => $result['rows'],
            'pager' => $result['pager'],
            'pager_params' => array_filter([
                'q' => '' !== $q ? $q : null,
            ], static fn (?string $v): bool => null !== $v),
            'locale' => $locale,
        ]);
    }

    #[Route('/translate/{id}', name: 'translate_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $locale = $request->getLocale();
        if (!TranslationLimits::isTranslatableLocale($locale)) {
            return $this->redirectToRoute('translate');
        }

        $entry = $this->em->find(TranslationEntry::class, $id);
        if (null === $entry || null !== $entry->getAbsentAt()) {
            throw $this->createNotFoundException();
        }

        $live = $this->browser->liveFor($entry, $locale);
        $form = $this->createForm(TranslationProposalType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();
            /** @var array{value: string} $data */
            $data = $form->getData();
            $consent = (bool) $form->get('consent')->getData();

            try {
                $this->proposals->submit($user, $entry, $locale, (string) $data['value'], $consent);
                $this->addFlash('success', 'translate.flash.submitted');

                return $this->redirectToRoute('translate');
            } catch (ConsentRequiredException) {
                $this->addFlash('danger', 'translate.error.consent_required');
            } catch (EmptyTranslationException) {
                $this->addFlash('danger', 'translate.error.empty');
            } catch (EnglishNotTranslatableException) {
                $this->addFlash('danger', 'translate.error.english');
            } catch (KeyNotFoundException) {
                $this->addFlash('danger', 'translate.error.key_absent');
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('danger', 'translate.error.rate_limited');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        $english = $entry->getEnglish();
        $hasMarkup = str_contains($english, '<b>')
            || str_contains($english, '<a ')
            || str_contains($english, '<a>');

        return $this->render('translate/edit.html.twig', [
            'page_title' => 'meta.translate_title',
            'page_description' => 'meta.translate_description',
            'nav_active' => 'contribute',
            'entry' => $entry,
            'yaml_default' => $live['yaml_default'],
            'live' => $live['live'],
            'has_markup' => $hasMarkup,
            'form' => $form,
            'locale' => $locale,
            'proposed_preview' => $form->isSubmitted()
                ? (string) ($form->get('value')->getData() ?? '')
                : '',
        ]);
    }
}
