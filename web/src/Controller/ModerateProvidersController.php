<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogField;
use App\Catalog\ItemType;
use App\Entity\User;
use App\Provider\Entity\DataProvider;
use App\Provider\Exception\ProviderRuleException;
use App\Provider\LicenceObligation;
use App\Provider\ProviderDefaults;
use App\Provider\ProviderRegistry;
use App\Provider\RefreshCommandLine;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Curator Providers desk: who we take data from, and what we owe them.
 *
 * Until this existed the registry could only be changed by a migration, which
 * meant every new dataset was a deploy. A provider's rank decides which of two
 * records of one place a rider keeps, and its licence decides what the site
 * claims it may republish, so both are moderation acts and both leave a trail.
 *
 * The desk is a form; {@see ProviderRegistry} is the authority on what the
 * form may do. Nothing is validated twice: a rule enforced here and not there
 * is a rule the next caller does not have.
 *
 * **A refresh is not a button yet.** The web hosts cannot start the pipeline,
 * so the desk prints the one command that runs a refresh on the environment
 * it is served from ({@see RefreshCommandLine}); the queued job a button would
 * start is an open item in docs/TODO.md.
 *
 * @see docs/specs/data-provider-hierarchy.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateProvidersController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'provider-edit';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly RefreshCommandLine $refresh,
        private readonly ProviderDefaults $defaults,
    ) {
    }

    #[Route('/moderate/providers', name: 'moderate_providers', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $providers = $this->registry->all();
        $openId = $request->query->getInt('open');
        $open = 0 !== $openId ? $this->registry->find($openId) : null;

        $rows = [];
        foreach ($providers as $p) {
            $rows[] = [
                'id' => $p->getId(),
                'key' => $p->getKey(),
                'name' => $p->getName(),
                'fullName' => $p->getFullName(),
                'homepage' => $p->getHomepage(),
                'licence' => $p->getLicence(),
                'licenceCode' => $p->getLicenceCode(),
                'attribution' => $p->getAttribution(),
                'creator' => $p->getCreator(),
                'blurb' => $p->getBlurb(),
                'blurbKey' => $p->getBlurbKey(),
                'rank' => $p->getRank(),
                'matchRadiusM' => $p->getMatchRadiusM(),
                'mayReclaim' => $p->mayReclaim(),
                'reclaimMarginDays' => $p->getReclaimMarginDays(),
                'surveyDateAttribute' => $p->getSurveyDateAttribute(),
                'promoted' => $p->isPromoted(),
                'enabled' => $p->isEnabled(),
                'system' => $p->isSystem(),
                // Shown beside the attribution field so a curator can see WHY
                // it is refused rather than only that it was.
                'owesNotice' => LicenceObligation::requiresAttribution($p->getLicenceCode()),
                'lastRunAt' => $p->getLastRunAt(),
                'lastCount' => $p->getLastCount(),
                'lastError' => $p->getLastError(),
                // The source, read-only for now (owner 2026-09-05: "show the
                // source and the run command"). Editing it, and running it
                // from here, are the open items in docs/TODO.md.
                'endpoint' => $p->getEndpoint(),
                'endpointKind' => $p->getEndpointKind(),
                'fieldMap' => $p->getFieldMap(),
                'letters' => $p->getLetters(),
                'countryCode' => $p->getCountryCode(),
                'refreshCadence' => $p->getRefreshCadence(),
                'runDry' => $this->refresh->dry($p->getKey()),
                'runWrite' => $this->refresh->write($p->getKey()),
                'defaults' => $p->isSystem() ? [] : $this->defaultsBlock($p),
            ];
        }

        return $this->render('moderate_providers/index.html.twig', [
            'page_title' => 'provider.desk.title',
            'page_description' => 'provider.desk.intro',
            'nav_active' => 'contribute',
            'active' => 'moderate_providers',
            'providers' => $rows,
            'open_id' => null !== $open ? $open->getId() : 0,
            'rank_max' => ProviderRegistry::RANK_MAX,
            'run_on_worker_host' => $this->refresh->onWorkerHost(),
            'csrf' => $this->container->get('security.csrf.token_manager')->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]);
    }

    #[Route('/moderate/providers/{id}', name: 'moderate_providers_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF token.');
        }

        $provider = $this->registry->find($id);
        if (null === $provider) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->registry->update($provider, [
                'name' => $request->request->getString('name'),
                'fullName' => $request->request->getString('fullName'),
                'homepage' => $request->request->getString('homepage'),
                'licence' => $request->request->getString('licence'),
                'licenceCode' => $request->request->getString('licenceCode'),
                'attribution' => $request->request->getString('attribution'),
                'creator' => $request->request->getString('creator'),
                'blurb' => $request->request->getString('blurb'),
                'rank' => $request->request->getInt('rank'),
                'matchRadiusM' => $request->request->getInt('matchRadiusM'),
                'mayReclaim' => $request->request->getBoolean('mayReclaim'),
                'reclaimMarginDays' => $request->request->getInt('reclaimMarginDays'),
                'surveyDateAttribute' => $request->request->getString('surveyDateAttribute'),
                'promoted' => $request->request->getBoolean('promoted'),
                'enabled' => $request->request->getBoolean('enabled'),
                // A row with no Defaults block posts none, and keeps its own.
            ] + ($request->request->has('defaults') ? ['defaults' => $request->request->all('defaults')] : []), $user);
            $this->addFlash('success', 'provider.saved');
        } catch (ProviderRuleException $e) {
            // The message IS the catalogue key, so a refusal reads in the
            // curator's own language.
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('moderate_providers', ['open' => $id]);
    }

    /**
     * The Defaults block: per letter the row fills, the same single-choice
     * fields that letter's edit form shows, with what is set now.
     * `condition` is not among them: only a rider who looked answers it.
     *
     * @return list<array{letter: string, typeKey: string, fields: list<array{name: string, label: string, choices: array<string, string>, current: string}>}>
     */
    private function defaultsBlock(DataProvider $p): array
    {
        $set = $p->getDefaults();
        $out = [];
        foreach ($p->getLetters() as $letter) {
            $type = ItemType::fromLetter($letter);
            $fields = $this->defaults->fieldsFor($letter);
            if (null === $type || [] === $fields) {
                continue;
            }
            $out[] = [
                'letter' => $letter,
                'typeKey' => $type->labelKey(),
                'fields' => array_map(static fn (CatalogField $f): array => [
                    'name' => $f->name,
                    'label' => $f->label,
                    // value => label; a keyed select shows its words, stores its key.
                    'choices' => array_combine($f->choices, array_map(static fn (string $c): string => $f->choiceLabels[$c] ?? $c, $f->choices)),
                    'current' => $set[$letter][$f->name] ?? '',
                ], $fields),
            ];
        }

        return $out;
    }
}
