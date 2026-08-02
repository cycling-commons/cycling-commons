<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Settings;

/**
 * One runtime-editable setting: what it is called, what it falls back to, what
 * an admin may move it to, and where it renders.
 *
 * The definition — not the database row — is the authority. A stored value that
 * no longer fits (because a later release tightened the bound) is ignored in
 * favour of the default, so a legal-at-the-time value can never outlive the
 * rule that made it legal.
 *
 * ## Two types, deliberately not `mixed`
 *
 * Settings began integer-only, and the registry said the first non-integer one
 * would be a real schema change rather than something that sneaks in behind a
 * generic value. It arrived: the addresses operational alerts go to
 * (docs/specs/photo-uploads.md §6d), which have to be editable when the usual
 * person is away. So there are exactly **two** types, each with its own
 * validation and its own typed accessor on SystemSettings — `int` for
 * thresholds, `string` for text. A third means doing this again, on purpose.
 *
 * @see docs/specs/system-configuration.md §2
 *
 * @api Built by SettingsRegistry; read by SystemSettings and the admin page.
 */
final readonly class SettingDefinition
{
    public const string TYPE_INT = 'int';
    public const string TYPE_STRING = 'string';

    /**
     * @param string                  $key       also the container-parameter name the default comes from
     * @param int|string              $default   the YAML/env value this key falls back to
     * @param ?int                    $min       int settings only
     * @param ?int                    $max       int settings only
     * @param ?int                    $maxLength string settings only
     * @param ?\Closure(string): bool $validator string settings only — the shape check a range cannot express
     * @param string                  $group     a SettingsRegistry::GROUP_* constant; the admin page renders one card per group
     * @param string                  $labelKey  translation key for the field label
     * @param string                  $helpKey   translation key for the sentence under it
     */
    public function __construct(
        public string $key,
        public string $type,
        public int|string $default,
        public ?int $min,
        public ?int $max,
        public ?int $maxLength,
        public ?\Closure $validator,
        public string $group,
        public string $labelKey,
        public string $helpKey,
    ) {
    }

    public function isString(): bool
    {
        return self::TYPE_STRING === $this->type;
    }

    /** True when this value may be stored for this key. A wrong-typed value is never accepted. */
    public function accepts(int|string $value): bool
    {
        if ($this->isString()) {
            return \is_string($value)
                && mb_strlen($value) <= ($this->maxLength ?? 0)
                && (null === $this->validator || ($this->validator)($value));
        }

        return \is_int($value)
            && $value >= ($this->min ?? \PHP_INT_MIN)
            && $value <= ($this->max ?? \PHP_INT_MAX);
    }

    /**
     * Reads a stored (always textual) value back into this key's type, or null
     * when the row cannot be that type at all — a corrupted or hand-edited row
     * falls back to the default rather than propagating nonsense.
     */
    public function fromStorage(string $raw): int|string|null
    {
        if ($this->isString()) {
            return $raw;
        }

        return 1 === preg_match('/^-?\d+$/', $raw) ? (int) $raw : null;
    }
}
