<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\Submission;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Which text a translation was made from (moderation-and-contribution.md
 * §3.1e). A town card text, a region's geography text and its history text
 * each live once per language; a writer may say theirs translates another
 * language's text. The translation then keeps `basedOn = {lang, at, sub}`:
 * the original's language, the moment that original was written and the
 * submission that wrote it (two changes within one second differ by their
 * submission), so a reader can be told when the original changed since, and
 * the translator is told when it does.
 *
 * Only a local text can be an original (one a person wrote here, not a
 * fetched Wikipedia paragraph): its stamp is what a later change moves.
 *
 * @api
 */
final readonly class TextLineage
{
    public function __construct(
        private Connection $db,
        private MessageService $messages,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Every local text of a target, by language: when it was written, who
     * wrote it, and what it translates.
     *
     * @return array<string, array{at: ?string, sub: ?int, userId: ?int, basedOn: ?array{lang: string, at: ?string, sub: ?int}}>
     */
    public function locals(string $target, string $ref): array
    {
        if (PlaceText::TOWN === $target) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT lang, edited_at, edited_by, submission_id, based_on_lang, based_on_at, based_on_submission FROM town_summary WHERE osm_ref = :r AND edited_at IS NOT NULL',
                ['r' => $ref],
            );
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row['lang']] = [
                    'at' => self::stamp($row['edited_at']),
                    'sub' => null === $row['submission_id'] ? null : (int) $row['submission_id'],
                    'userId' => null === $row['edited_by'] ? null : (int) $row['edited_by'],
                    'basedOn' => null === $row['based_on_lang'] ? null : [
                        'lang' => (string) $row['based_on_lang'],
                        'at' => self::stamp($row['based_on_at']),
                        'sub' => null === $row['based_on_submission'] ? null : (int) $row['based_on_submission'],
                    ],
                ];
            }

            return $out;
        }

        $column = PlaceText::STORY === $target ? 'story' : 'context_curated';
        $entries = PlaceText::decode($this->db->fetchOne(sprintf('SELECT %s FROM region WHERE id = :id', $column), ['id' => (int) $ref])) ?? [];
        $out = [];
        foreach ($entries as $lang => $entry) {
            if (!\is_array($entry) || !\is_string($entry['text'] ?? null) || '' === trim($entry['text'])) {
                continue;
            }
            $out[(string) $lang] = [
                'at' => \is_string($entry['at'] ?? null) ? $entry['at'] : null,
                'sub' => \is_int($entry['submissionId'] ?? null) ? $entry['submissionId'] : null,
                'userId' => \is_int($entry['userId'] ?? null) ? $entry['userId'] : null,
                'basedOn' => self::basedOnOf($entry),
            ];
        }

        return $out;
    }

    /**
     * What a new text in `$lang` translates, as the payload keeps it: the
     * original's language and stamp now. Null when `$from` is empty.
     *
     * @return array{lang: string, at: ?string, sub: ?int}|null
     *
     * @throws PlaceTextRefused when `$from` names no local text, or the text's own language
     */
    public function basedOn(string $target, string $ref, string $lang, string $from): ?array
    {
        $from = strtolower(trim($from));
        if ('' === $from) {
            return null;
        }
        $original = $this->locals($target, $ref)[$from] ?? null;
        if ($from === $lang || null === $original) {
            throw new PlaceTextRefused('place_text.error.based_on');
        }

        return ['lang' => $from, 'at' => $original['at'], 'sub' => $original['sub']];
    }

    /**
     * The original a stored entry names, as `{lang, at}`, or null.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{lang: string, at: ?string, sub: ?int}|null
     */
    public static function basedOnOf(array $entry): ?array
    {
        $based = $entry['basedOn'] ?? null;
        if (!\is_array($based) || !\is_string($based['lang'] ?? null)) {
            return null;
        }

        return [
            'lang' => $based['lang'],
            'at' => \is_string($based['at'] ?? null) ? $based['at'] : null,
            'sub' => \is_int($based['sub'] ?? null) ? $based['sub'] : null,
        ];
    }

    /**
     * Whether a translation's original changed after it was translated:
     * the original's stamp moved, or the original is gone.
     *
     * @param array{lang: string, at: ?string, sub: ?int}|null                                                                  $basedOn
     * @param array<string, array{at: ?string, sub: ?int, userId: ?int, basedOn: ?array{lang: string, at: ?string, sub: ?int}}> $locals
     */
    public static function outdated(?array $basedOn, array $locals): bool
    {
        if (null === $basedOn) {
            return false;
        }
        $original = $locals[$basedOn['lang']] ?? null;

        return null === $original || $original['at'] !== $basedOn['at'] || $original['sub'] !== $basedOn['sub'];
    }

    /**
     * After a new text in a language is approved: everyone whose
     * translation was made from that language is told the original changed,
     * with the way to their own form. Not the writer of the change, and not
     * an account that is gone.
     *
     * @param array{target: string, ref: string, lang: string, text: string, derived: bool} $proposal
     */
    public function tellTranslators(Submission $submission, array $proposal, ?string $slug, ?string $townName): int
    {
        $told = 0;
        foreach ($this->locals($proposal['target'], $proposal['ref']) as $lang => $local) {
            if ($lang === $proposal['lang'] || $proposal['lang'] !== ($local['basedOn']['lang'] ?? null)) {
                continue;
            }
            $userId = $local['userId'];
            if (null === $userId || $userId === $submission->getUserId()) {
                continue;
            }
            $url = $this->formUrl($proposal['target'], $proposal['ref'], $lang, $slug, $townName);
            if (null === $url) {
                continue;
            }
            $this->messages->sendSystem(
                $userId,
                UserMessageKind::TextOriginalChanged,
                'text',
                (int) $submission->getId(),
                $submission->getTitle(),
                'messages.body.'.UserMessageKind::TextOriginalChanged->value,
                ['%title%' => $submission->getTitle(), '%from%' => strtoupper($proposal['lang']), '%to%' => strtoupper($lang), '%url%' => $url],
            );
            ++$told;
        }

        return $told;
    }

    /** The form a translator updates their text on, in their language. */
    private function formUrl(string $target, string $ref, string $lang, ?string $slug, ?string $townName): ?string
    {
        if (PlaceText::TOWN === $target) {
            if (1 !== preg_match('~^(node|way|relation)/(\d+)$~', $ref, $m)) {
                return null;
            }

            return $this->urls->generate('town_text', array_filter(['osmType' => $m[1], 'osmId' => $m[2], 'lang' => $lang, 'name' => $townName]));
        }
        if (null === $slug) {
            return null;
        }

        return $this->urls->generate(PlaceText::STORY === $target ? 'region_story' : 'region_text', ['slug' => $slug, 'lang' => $lang]);
    }

    /** A timestamp as one comparable string, whatever shape the column gives it. */
    private static function stamp(mixed $raw): ?string
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($raw))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return $raw;
        }
    }
}
