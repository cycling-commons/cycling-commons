<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Coverage;

use Doctrine\DBAL\Connection;

/**
 * Test-side equivalent of the pipeline's ensure_tracker_schema()
 * (pipeline/coverage/tracker.py) — the two tracker tables are pipeline-owned
 * DDL, excluded from Doctrine's schema_filter, so migrations never create them
 * and the test database never runs the pipeline that would.
 * Built inside the DAMA transaction, like {@see CoverageSchema}.
 *
 * @see docs/specs/coverage-runs-admin.md §4
 */
trait CoverageRunSchema
{
    private static function ensureCoverageRunSchema(Connection $db): void
    {
        $db->executeStatement(
            'CREATE TABLE IF NOT EXISTS coverage_run (
                id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                started_at        timestamptz NOT NULL DEFAULT now(),
                finished_at       timestamptz,
                trigger           text NOT NULL,
                status            text NOT NULL,
                regions_requested int,
                regions_loaded    int,
                published_url     text
            )'
        );
        $db->executeStatement(
            'CREATE TABLE IF NOT EXISTS coverage_run_step (
                id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                run_id     bigint NOT NULL REFERENCES coverage_run(id) ON DELETE CASCADE,
                region     text,
                step       text NOT NULL,
                started_at timestamptz NOT NULL,
                seconds    double precision NOT NULL,
                bytes      bigint,
                rows       bigint,
                status     text NOT NULL,
                detail     text
            )'
        );
        $db->executeStatement('DELETE FROM coverage_run');
    }

    /**
     * Seed one run row and return its id. `started`/`finished` are SQL
     * interval expressions relative to now(), as the pipeline writes them.
     *
     * @param array<string, mixed> $overrides trigger|status|started|finished|requested|loaded|url
     */
    private static function insertCoverageRun(Connection $db, array $overrides = []): int
    {
        $row = $overrides + [
            'trigger' => 'dispatcher',
            'status' => 'ok',
            'started' => '2 hours',
            'finished' => '1 hour',
            'requested' => 2,
            'loaded' => 2,
            'url' => 'be,nl',
        ];

        return (int) $db->fetchOne(
            'INSERT INTO coverage_run (started_at, finished_at, trigger, status,
                                       regions_requested, regions_loaded, published_url)
             VALUES (now() - :started::interval,
                     CASE WHEN :finished::text IS NULL THEN NULL ELSE now() - :finished::interval END,
                     :trigger, :status, :requested, :loaded, :url)
             RETURNING id',
            $row,
        );
    }

    /** @param array<string, mixed> $overrides region|step|seconds|bytes|rows|status|detail */
    private static function insertCoverageRunStep(Connection $db, int $runId, array $overrides = []): void
    {
        $row = $overrides + [
            'region' => 'europe/france',
            'step' => 'load',
            'seconds' => 655.0,
            'bytes' => null,
            'rows' => null,
            'status' => 'ok',
            'detail' => null,
        ];
        $db->executeStatement(
            'INSERT INTO coverage_run_step (run_id, region, step, started_at, seconds, bytes, rows, status, detail)
             VALUES (:run, :region, :step, now() - make_interval(secs => :seconds), :seconds,
                     :bytes, :rows, :status, :detail)',
            ['run' => $runId] + $row,
        );
    }
}
