<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * The kinds of desk item a curator can have opened, as stored in
 * moderation_seen.subject_type. Each value names what its subject_id holds.
 *
 * @see docs/specs/moderation-and-contribution.md §5.2f
 */
enum SeenSubject: string
{
    /** submission.id: the Submissions queue and History. */
    case Submission = 'submission';
    /** recommended_route.id: a proposal, and a live route on the Routes desk. */
    case Route = 'route';
    /** route_suggestion.id: a correction on the Routes desk. */
    case RouteSuggestion = 'route_suggestion';
    /** content_report.id (a UUID): the Reports desk. */
    case ContentReport = 'content_report';
    /** bug_report.id: the Bugs desk. */
    case BugReport = 'bug_report';
    /** translation_proposal.id: the Translations queue and history. */
    case TranslationProposal = 'translation_proposal';
    /**
     * `<locale>:<translation_entry.id>:<english_version>`: one key gone stale
     * in one locale against one English wording. A new English wording is a
     * new stale row, so it arrives unopened again.
     */
    case TranslationStale = 'translation_stale';
    /** catalog_finding.id: the Data desk. */
    case CatalogFinding = 'catalog_finding';
    /** media_upload.id (a UUID) of the photo a removal request is about: the Takedowns desk. */
    case Takedown = 'takedown';

    /** The id a stale translation row is stored under. */
    public static function staleId(string $locale, int $entryId, int $englishVersion): string
    {
        return $locale.':'.$entryId.':'.$englishVersion;
    }
}
