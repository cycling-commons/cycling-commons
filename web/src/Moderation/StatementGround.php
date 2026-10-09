<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Support\ReportGround;

/**
 * The ground a statement of reasons names (DSA Article 17(3)(d) and (e)): a
 * law, or a rule in our terms the person can go and read.
 *
 * The nine "what gets taken down" rules carry the same values as
 * {@see ReportGround}, so a report's ground becomes the statement's ground
 * with no mapping table. The rest are the rules a curator or an administrator
 * applies without a report: what the catalogue accepts, spam, and what an
 * account may not be used for. Each has its `dsa_statement.ground.*` line in
 * all five languages.
 *
 * @see docs/specs/content-reports.md §7
 *
 * @api
 */
enum StatementGround: string
{
    case Unlawful = 'unlawful';
    case PersonalData = 'personal_data';
    case Untrue = 'untrue';
    case Abuse = 'abuse';
    case Advertising = 'advertising';
    case Generated = 'generated';
    case IntimateOrChild = 'intimate_or_child';
    case PrivateProperty = 'private_property';
    case Copyright = 'copyright';
    /** What the catalogue accepts: accurate, checkable, something the Commons maps. Every rejection names it. */
    case NotAccepted = 'not_accepted';
    /** Another entry describes the same place, and a curator kept that one. */
    case Duplicate = 'duplicate';
    /** A file the upload checks refused: infected, unreadable, or not a format and size we accept. */
    case FileRefused = 'file_refused';
    /** Deceptive high-volume commercial content: the one ground that owes no statement (Article 17(2)). */
    case Spam = 'spam';
    /** Using the service against our terms: scraping it, disrupting it, working around its limits. */
    case Misuse = 'misuse';
    /** Account details that are not accurate or not the holder's own. */
    case FalseIdentity = 'false_identity';
    /** A photo report's old "something else", decided on the takedowns desk: the curator's note says which rule. */
    case TermsOther = 'terms_other';

    /** The catalogue key of the line that names the ground. */
    public function label(): string
    {
        return 'dsa_statement.ground.'.$this->value;
    }

    /**
     * A law rather than a rule of ours, for Article 17(3)(d). The same four
     * as {@see ReportGround::isLegal()}.
     */
    public function isLegal(): bool
    {
        return match ($this) {
            self::Unlawful, self::PersonalData, self::IntimateOrChild, self::Copyright => true,
            default => false,
        };
    }

    /**
     * Article 17(2): the statement is not owed for deceptive high-volume
     * commercial content, which is what Trash as spam means. Every other
     * ground owes one.
     */
    public function owesStatement(): bool
    {
        return self::Spam !== $this;
    }

    /** A report's ground as a statement's ground; null for "Something else", which is not a rule. */
    public static function fromReport(ReportGround $ground): ?self
    {
        return $ground->isRule() ? self::from($ground->value) : null;
    }

    /**
     * The ground behind a stored photo takedown category: a report ground's
     * own value, or one of the old photo form's words.
     */
    public static function fromTakedownCategory(?string $category): self
    {
        return match ($category) {
            'identifiable_self', 'identifiable_other' => self::PersonalData,
            null, 'other' => self::TermsOther,
            default => self::tryFrom($category) ?? self::TermsOther,
        };
    }

    /**
     * What Trash asks for: spam goes without a word, abuse is explained.
     *
     * @return list<self>
     */
    public static function forTrash(): array
    {
        return [self::Spam, self::Abuse];
    }

    /**
     * What an administrator may name when suspending or removing an account.
     *
     * @return list<self>
     */
    public static function forAccounts(): array
    {
        return [self::Abuse, self::Spam, self::Unlawful, self::Misuse, self::FalseIdentity, self::Untrue, self::Advertising, self::PersonalData];
    }
}
