<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Settings;

/**
 * One runtime-editable setting. The definition, not the DB row, is the authority.
 *
 * @see docs/specs/system-configuration.md §2
 *
 * @api
 */
final readonly class SettingDefinition
{
    public const string TYPE_INT = 'int';
    public const string TYPE_STRING = 'string';

    /**
     * @param ?\Closure(string): bool $validator
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
        /**
         * May a curator clear this field?
         *
         * False for every threshold and for the alert list, because an empty
         * one silently disables something. True where empty is a MEANING rather
         * than an omission: the support list, where empty means "fall back to
         * the alert list" (SupportRecipients). The desk hard-coded "required"
         * before this existed, which made such a setting unsaveable.
         */
        public bool $allowsEmpty = false,
    ) {
    }

    public function isString(): bool
    {
        return self::TYPE_STRING === $this->type;
    }

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

    public function fromStorage(string $raw): int|string|null
    {
        if ($this->isString()) {
            return $raw;
        }

        return 1 === preg_match('/^-?\d+$/', $raw) ? (int) $raw : null;
    }
}
