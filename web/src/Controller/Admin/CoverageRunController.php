<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * What the nightly harvest did, read back from the two tables the pipeline
 * writes as it goes (pipeline/coverage/tracker.py). Read-only: nothing here
 * measures anything new, and nothing here writes.
 *
 * Plain DBAL on purpose. coverage_run and coverage_run_step have no entity and
 * never will — the pipeline owns their shape and creates them itself, outside
 * Doctrine migrations (coverage-provider.md §2).
 *
 * @see docs/specs/coverage-runs-admin.md
 *
 * @api
 */
#[IsGranted('ROLE_ADMIN')]
final class CoverageRunController extends AbstractController
{
    /** A run row still 'running' this long after it started was killed, not finished. */
    private const string ABANDONED_AFTER = '12 hours';

    /** Runs are ~1 a night and never pruned; this keeps the page one screenful of work. */
    private const int LIST_LIMIT = 200;

    public function __construct(private readonly Connection $db)
    {
    }

    #[AdminRoute('/coverage-runs', 'coverage_runs', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        $runs = $this->db->fetchAllAssociative(
            \sprintf(<<<'SQL'
                SELECT r.id, r.started_at, r.finished_at, r.trigger, r.family,
                       CASE WHEN r.status = 'running' AND r.started_at < now() - INTERVAL '%s'
                            THEN 'abandoned' ELSE r.status END AS status,
                       r.regions_requested, r.regions_loaded, r.published_url,
                       EXTRACT(EPOCH FROM (r.finished_at - r.started_at)) AS seconds
                FROM coverage_run r
                ORDER BY r.started_at DESC
                LIMIT %d
                SQL, self::ABANDONED_AFTER, self::LIST_LIMIT),
        );

        return $this->render('admin/coverage_runs/index.html.twig', ['runs' => $runs]);
    }

    #[AdminRoute('/coverage-runs/{id}', 'coverage_run', options: ['methods' => ['GET'], 'requirements' => ['id' => '\d+']])]
    public function show(int $id): Response
    {
        $run = $this->db->fetchAssociative(
            \sprintf(<<<'SQL'
                SELECT r.id, r.started_at, r.finished_at, r.trigger,
                       CASE WHEN r.status = 'running' AND r.started_at < now() - INTERVAL '%s'
                            THEN 'abandoned' ELSE r.status END AS status,
                       r.regions_requested, r.regions_loaded, r.published_url,
                       EXTRACT(EPOCH FROM (r.finished_at - r.started_at)) AS seconds
                FROM coverage_run r
                WHERE r.id = :id
                SQL, self::ABANDONED_AFTER),
            ['id' => $id],
        );
        if (false === $run) {
            throw $this->createNotFoundException();
        }

        // Region groups keep their steps together even though `parse` is
        // recorded after `load` with a started_at inside load's own window.
        $steps = $this->db->fetchAllAssociative(
            <<<'SQL'
            SELECT s.region, s.step, s.seconds, s.bytes, s.rows, s.status, s.detail
            FROM coverage_run_step s
            WHERE s.run_id = :id
            ORDER BY (s.region IS NULL), MIN(s.started_at) OVER (PARTITION BY s.region), s.started_at, s.id
            SQL,
            ['id' => $id],
        );

        return $this->render('admin/coverage_runs/show.html.twig', [
            'run' => $run,
            'groups' => $this->groupByRegion($steps),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return list<array{region: string|null, steps: list<array<string, mixed>>}>
     */
    private function groupByRegion(array $steps): array
    {
        $groups = [];
        foreach ($steps as $step) {
            /** @var string|null $region */
            $region = $step['region'];
            $step['load'] = 'load' === $step['step'] ? $this->readLoadDetail($step['detail']) : null;
            $groups[$region ?? ''] ??= ['region' => $region, 'steps' => []];
            $groups[$region ?? '']['steps'][] = $step;
        }

        return array_values($groups);
    }

    /**
     * The load step's detail is JSON since coverage-runs-admin.md §3. A row
     * written before that change is plain text ("previous 1402118") and is
     * shown as the text it is.
     *
     * @return array{previous: int|null, dropped: list<array{label: TranslatableMessage, count: int}>, text: string|null}
     */
    private function readLoadDetail(?string $detail): array
    {
        $empty = ['previous' => null, 'dropped' => [], 'text' => null];
        if (null === $detail || '' === $detail) {
            return $empty;
        }

        $decoded = json_decode($detail, true);
        if (!\is_array($decoded) || !\array_key_exists('previous', $decoded)) {
            return ['previous' => null, 'dropped' => [], 'text' => $detail];
        }

        $dropped = [];
        $counts = $decoded['dropped'] ?? [];
        if (\is_array($counts)) {
            foreach ($counts as $rule => $count) {
                if (\is_string($rule) && \is_int($count)) {
                    $dropped[] = ['label' => $this->ruleLabel($rule), 'count' => $count];
                }
            }
        }

        return [
            'previous' => \is_int($decoded['previous']) ? $decoded['previous'] : null,
            'dropped' => $dropped,
            'text' => null,
        ];
    }

    /** Rule labels are written by the pipeline: `name:P`, `exclude:Q:memorial`, `near_way`. */
    private function ruleLabel(string $rule): TranslatableMessage
    {
        $parts = explode(':', $rule);

        return match ($parts[0]) {
            'name' => new TranslatableMessage('admin.coverage_runs.rule.name', ['%letter%' => $parts[1] ?? '?']),
            'exclude' => new TranslatableMessage('admin.coverage_runs.rule.exclude', ['%letter%' => $parts[1] ?? '?', '%tag%' => $parts[2] ?? '?']),
            'near_way' => new TranslatableMessage('admin.coverage_runs.rule.near_way'),
            default => new TranslatableMessage('admin.coverage_runs.rule.other', ['%rule%' => $rule]),
        };
    }
}
