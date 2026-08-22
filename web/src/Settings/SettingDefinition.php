<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
