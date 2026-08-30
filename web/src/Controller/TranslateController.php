<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\RiderPseudonym;
use App\Entity\User;
use App\Form\TranslationProposalType;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use App\Translation\CatalogueBrowser;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\ConsentRequiredException;
use App\Translation\Exception\EmptyTranslationException;
use App\Translation\Exception\EnglishNotTranslatableException;
use App\Translation\Exception\InvalidLocaleException;
use App\Translation\Exception\InvalidMarkupException;
use App\Translation\Exception\KeyNotFoundException;
use App\Translation\Exception\ProtectedKeyException;
use App\Translation\Exception\TranslationConflictException;
use App\Translation\Exception\TranslationTooLongException;
use App\Translation\ProposalService;
use App\Translation\TranslationConsentService;
use App\Translation\TranslationDiff;
use App\Translation\TranslationLimits;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslationConsentService $consent,
        private readonly PageSize $pageSize,
        private readonly EntityManagerInterface $em,
        // The markup errors name a tag, so the message needs a parameter and
        // the flash cannot be a bare catalogue key.
        private readonly TranslatorInterface $translator,
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

    #[Route('/translate/mine', name: 'translate_mine', methods: ['GET'])]
    public function mine(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $status = TranslationProposalStatus::tryFrom($request->query->getString('status'));
        $localeFilter = $this->historyLocale($request);
        $result = $this->proposals->historyFor(
            (int) $user->getId(),
            $status,
            $localeFilter,
            $request->query->getInt('page', 1),
            $this->pageSize->resolve(ProposalService::HISTORY_PER_PAGE),
        );

        $cards = [];
        foreach ($result['rows'] as $proposal) {
            $cards[] = $this->mineCard($proposal);
        }

        return $this->render('translate/mine.html.twig', [
            'page_title' => 'translate.mine.title',
            'page_description' => 'meta.translate_description',
            'nav_active' => 'contribute',
            'cards' => $cards,
            'pager' => $result['pager'],
            'pager_params' => array_filter([
                'status' => $status?->value,
                'locale' => null === $localeFilter ? 'all' : $localeFilter,
            ], static fn (?string $v): bool => null !== $v),
            'status_filter' => $status?->value,
            'status_chips' => $result['statuses'],
            'locale_filter' => $localeFilter,
            'locales' => TranslationLimits::LOCALES,
            'had_any' => $result['had_any'],
        ]);
    }

    #[Route('/translate/mine/{locale}/{id}', name: 'translate_mine_key', requirements: ['locale' => 'fr|nl|de|es', 'id' => '\d+'], methods: ['GET'])]
    public function mineKey(string $locale, int $id): Response
    {
        if (!TranslationLimits::isTranslatableLocale($locale)) {
            throw $this->createNotFoundException();
        }

        $entry = $this->em->find(TranslationEntry::class, $id);
        if (null === $entry || null !== $entry->getAbsentAt()) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $rows = $this->proposals->historyForKey((int) $user->getId(), $locale, $entry);
        if ([] === $rows) {
            throw $this->createNotFoundException();
        }

        $oldestFirst = array_reverse($rows);
        $latest = $rows[0];
        $live = $this->browser->liveFor($entry, $locale);
        $card = $this->storyCard($oldestFirst, $live['yaml_default'], $latest);

        return $this->render('translate/mine_key.html.twig', [
            'page_title' => 'translate.mine.title',
            'page_description' => 'meta.translate_description',
            'nav_active' => 'contribute',
            'card' => $card,
        ]);
    }

    /**
     * Null means every locale (explicit All, or English where none can be proposed).
     */
    private function historyLocale(Request $request): ?string
    {
        $param = $request->query->getString('locale');
        if ('all' === $param) {
            return null;
        }
        if (TranslationLimits::isTranslatableLocale($param)) {
            return $param;
        }
        $current = $request->getLocale();
        if (TranslationLimits::isTranslatableLocale($current)) {
            return $current;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mineCard(TranslationProposal $proposal): array
    {
        $status = $proposal->getStatus();
        $proposed = $proposal->getProposedValue();
        $published = TranslationProposalStatus::Approved === $status ? $proposal->getPublishedValue() : null;
        $open = \in_array($status, [
            TranslationProposalStatus::Pending,
            TranslationProposalStatus::NeedsInfo,
        ], true);

        return [
            'message_key' => $proposal->getEntry()->getMessageKey(),
            'locale' => $proposal->getLocale(),
            'status' => $status->value,
            'proposed' => $proposed,
            'published' => $published,
            'edited' => null !== $published && $published !== $proposed,
            'note' => $proposal->getReviewerNote(),
            'when' => $proposal->getDecidedAt() ?? $proposal->getCreatedAt(),
            'open' => $open,
            'entry_id' => $proposal->getEntry()->getId(),
            'id' => $proposal->getId(),
        ];
    }

    /**
     * English, YAML original, then each of this rider's changes oldest-first.
     * An open or rejected latest row is the last Change, matching the curator story.
     *
     * @param list<TranslationProposal> $versions oldest first
     *
     * @return array<string, mixed>
     */
    private function storyCard(array $versions, string $yamlDefault, TranslationProposal $latest): array
    {
        $first = $versions[0];
        $english = $first->getEnglishAtSubmit();
        $prev = $yamlDefault;
        $prevEnglish = $english;
        $past = [];
        $pending = null;
        $lastIndex = \count($versions) - 1;

        foreach ($versions as $i => $proposal) {
            $en = $proposal->getEnglishAtSubmit();
            if (0 !== $i && $en !== $prevEnglish) {
                $past[] = [
                    'kind' => 'english',
                    'text' => $en,
                ];
            }
            $prevEnglish = $en;
            $open = $this->isOpen($proposal);
            $approved = TranslationProposalStatus::Approved === $proposal->getStatus();
            $published = $approved ? $proposal->getPublishedValue() : null;
            $to = $published ?? $proposal->getProposedValue();
            // No null check: inside $approved, $published is getPublishedValue(),
            // which falls back to the proposal rather than returning null.
            $edit = $approved && $published !== $proposal->getProposedValue()
                ? [
                    'diff' => TranslationDiff::words($proposal->getProposedValue(), $published),
                    'who' => $this->who($proposal->getReviewerId()),
                ]
                : null;
            $row = $this->changeRow(
                $proposal,
                $prev,
                $to,
                $open,
                $open ? $proposal->getCreatedAt() : $proposal->getDecidedAt(),
                $edit,
            );
            if ($i === $lastIndex && ($open || TranslationProposalStatus::Rejected === $proposal->getStatus())) {
                $pending = $row;
            } else {
                $past[] = $row;
                if ($approved) {
                    $prev = $to;
                }
            }
        }

        return [
            'open' => $this->isOpen($latest),
            'message_key' => $latest->getEntry()->getMessageKey(),
            'english' => $english,
            'original' => $yamlDefault,
            'past' => $past,
            'pending' => $pending,
            'locale' => $latest->getLocale(),
            'status' => $latest->getStatus()->value,
            'entry_id' => (int) $latest->getEntry()->getId(),
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

        /** @var User $user */
        $user = $this->getUser();
        $open = $this->proposals->openFor($user, $locale, $entry);
        $live = $this->browser->liveFor($entry, $locale);
        $proposed = $open?->getProposedValue() ?? '';
        $standing = $this->consent->current($user);
        $form = $this->createForm(TranslationProposalType::class, [
            'value' => $proposed,
        ], [
            'standing' => null !== $standing,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{value: string} $data */
            $data = $form->getData();
            $consentTick = $form->has('consent') && (bool) $form->get('consent')->getData();

            try {
                $this->proposals->submit($user, $entry, $locale, (string) $data['value'], $consentTick);
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
            } catch (ProtectedKeyException) {
                $this->addFlash('danger', 'translate.error.protected');
            } catch (InvalidLocaleException) {
                $this->addFlash('danger', 'translate.error.bad_locale');
            } catch (InvalidMarkupException $e) {
                // Name the tag. "The HTML is wrong" to somebody who did not
                // know there was any HTML is not a message they can act on.
                $problem = $e->first();
                $this->addFlash('danger', $this->translator->trans(
                    'translate.error.markup_'.$problem['key'],
                    ['%tag%' => $problem['tag'] ?? ''],
                ));
            } catch (TranslationTooLongException) {
                $this->addFlash('danger', 'translate.error.too_long');
            } catch (TranslationConflictException) {
                $this->addFlash('danger', 'translate.error.conflict');
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('danger', 'translate.error.rate_limited');
            }
        }

        $english = $entry->getEnglish();
        $hasMarkup = 1 === preg_match('/<[a-zA-Z\/]/', $english);

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
            'open' => $open,
            'standing' => $standing,
            'change' => null !== $open ? TranslationDiff::words($live['live'], $proposed) : [],
            'proposed_preview' => $form->isSubmitted()
                ? (string) ($form->get('value')->getData() ?? '')
                : '',
        ]);
    }
}
