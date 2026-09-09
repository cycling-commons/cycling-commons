<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Settings;

use App\Catalog\CuratedReadiness;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Runtime-configurable keys, bounds, and admin render metadata.
 * Keys are container parameter names. Coverage CSP/manifest stay env-backed.
 *
 * @see docs/specs/system-configuration.md §2
 *
 * @api
 */
final class SettingsRegistry
{
    public const string MAP_CONFIRMED_THRESHOLD = 'map.confirmed_default_threshold';
    public const string MAP_CURATED_THRESHOLD = 'map.curated_default_threshold';
    public const string MAP_CURATED_MIN_BLOCKS = 'map.curated_default_min_blocks';
    public const string MAP_CURATED_MIN_PER_BLOCK = 'map.curated_default_min_per_block';
    public const string MAP_CONFIRMATION_STALE_MONTHS = 'map.confirmation_stale_months';
    /**
     * Independent riders who must confirm a place before it verifies itself.
     *
     * The counterpart of {@see self::ROUTE_RIDE_VERIFY_THRESHOLD} for places,
     * and deliberately lower: "I rode this whole route" is a bigger claim than
     * "this tap is here", and a region thin enough that three riders never
     * stand at the same fountain would otherwise keep a `?` on it forever.
     */
    public const string MAP_ITEM_VERIFY_THRESHOLD = 'map.item_verify_threshold';
    public const string ROUTE_REGION_ACTIVE_CAP = 'route.region_active_cap';
    public const string ROUTE_RIDE_VERIFY_THRESHOLD = 'route.ride_verify_threshold';
    public const string MODERATION_RETENTION_MONTHS = 'moderation.retention_months';
    public const string MEDIA_URGENT_BREAKER_HOURLY = 'media.urgent_breaker_hourly';
    public const string MEDIA_URGENT_BREAKER_DAILY = 'media.urgent_breaker_daily';
    public const string ALERT_EMAILS = 'app.alert_emails';
    /**
     * Where the contact form and bug reports are announced.
     *
     * Separate from ALERT_EMAILS so the front door and the pager can point at
     * different people. Empty is allowed and means "use the alert list", which
     * is why this one has no non-empty validator (contact-and-support.md §7).
     */
    public const string SUPPORT_EMAILS = 'app.support_emails';
    public const string COMMUNITY_VOTING_LIVE = 'community.voting_live';

    public const string GROUP_MAP = 'map';
    public const string GROUP_ROUTES = 'routes';
    public const string GROUP_MODERATION = 'moderation';
    public const string GROUP_MEDIA = 'media';
    public const string GROUP_ALERTS = 'alerts';
    public const string GROUP_COMMUNITY = 'community';

    /** @var array<string, SettingDefinition> keyed by setting key, in render order */
    private array $definitions = [];

    public function __construct(ParameterBagInterface $params)
    {
        // [key, min, max, group]
        $table = [
            [self::MAP_CONFIRMED_THRESHOLD, 1, 1000, self::GROUP_MAP],
            [self::MAP_CURATED_THRESHOLD, 1, 1000, self::GROUP_MAP],
            [self::MAP_CURATED_MIN_BLOCKS, 1, \count(CuratedReadiness::BLOCKS), self::GROUP_MAP],
            [self::MAP_CURATED_MIN_PER_BLOCK, 1, 100, self::GROUP_MAP],
            [self::MAP_CONFIRMATION_STALE_MONTHS, 1, 60, self::GROUP_MAP],
            [self::MAP_ITEM_VERIFY_THRESHOLD, 1, 20, self::GROUP_MAP],
            [self::ROUTE_REGION_ACTIVE_CAP, 1, 1000, self::GROUP_ROUTES],
            [self::ROUTE_RIDE_VERIFY_THRESHOLD, 1, 100, self::GROUP_ROUTES],
            [self::MODERATION_RETENTION_MONTHS, 1, 120, self::GROUP_MODERATION],
            // 0 disables auto-withhold (docs/specs/photo-uploads.md §6c).
            [self::MEDIA_URGENT_BREAKER_HOURLY, 0, 500, self::GROUP_MEDIA],
            [self::MEDIA_URGENT_BREAKER_DAILY, 0, 2000, self::GROUP_MEDIA],
            [self::COMMUNITY_VOTING_LIVE, 0, 1, self::GROUP_COMMUNITY],
        ];

        foreach ($table as [$key, $min, $max, $group]) {
            $raw = $params->get($key);
            if (!is_numeric($raw)) {
                throw new \LogicException(sprintf('Setting "%s" needs an integer container parameter of the same name.', $key));
            }
            $this->definitions[$key] = $this->define(
                key: $key,
                type: SettingDefinition::TYPE_INT,
                default: (int) $raw,
                min: $min,
                max: $max,
                group: $group,
            );
        }

        $default = $params->get(self::ALERT_EMAILS);
        $this->definitions[self::ALERT_EMAILS] = $this->define(
            key: self::ALERT_EMAILS,
            type: SettingDefinition::TYPE_STRING,
            default: \is_string($default) ? $default : '',
            group: self::GROUP_ALERTS,
            maxLength: 500,
            // At least one real address; empty would silently disable alerts.
            validator: static function (string $value): bool {
                $parts = array_filter(array_map(trim(...), explode(',', $value)), static fn (string $p): bool => '' !== $p);
                if ([] === $parts) {
                    return false;
                }
                foreach ($parts as $part) {
                    if (false === filter_var($part, \FILTER_VALIDATE_EMAIL)) {
                        return false;
                    }
                }

                return true;
            },
        );

        $supportDefault = $params->get(self::SUPPORT_EMAILS);
        $this->definitions[self::SUPPORT_EMAILS] = $this->define(
            key: self::SUPPORT_EMAILS,
            type: SettingDefinition::TYPE_STRING,
            default: \is_string($supportDefault) ? $supportDefault : '',
            group: self::GROUP_ALERTS,
            maxLength: 500,
            // Empty IS valid here, unlike ALERT_EMAILS: it means "fall back to
            // the alert list" (SupportRecipients), so an unset value cannot
            // leave the contact form shouting into nothing.
            validator: static function (string $value): bool {
                foreach (array_filter(array_map(trim(...), explode(',', $value)), static fn (string $p): bool => '' !== $p) as $part) {
                    if (false === filter_var($part, \FILTER_VALIDATE_EMAIL)) {
                        return false;
                    }
                }

                return true;
            },
            allowsEmpty: true,
        );
    }

    /** @param ?\Closure(string): bool $validator */
    private function define(
        string $key,
        string $type,
        int|string $default,
        string $group,
        ?int $min = null,
        ?int $max = null,
        ?int $maxLength = null,
        ?\Closure $validator = null,
        bool $allowsEmpty = false,
    ): SettingDefinition {
        $slug = str_replace('.', '_', $key);

        return new SettingDefinition(
            key: $key,
            type: $type,
            default: $default,
            min: $min,
            max: $max,
            maxLength: $maxLength,
            validator: $validator,
            group: $group,
            labelKey: 'admin.settings.field.'.$slug.'.label',
            helpKey: 'admin.settings.field.'.$slug.'.help',
            allowsEmpty: $allowsEmpty,
        );
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /** @throws \InvalidArgumentException on a key that is not configurable */
    public function get(string $key): SettingDefinition
    {
        return $this->definitions[$key]
            ?? throw new \InvalidArgumentException(sprintf('"%s" is not a configurable setting.', $key));
    }

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<string, list<SettingDefinition>>
     */
    public function grouped(): array
    {
        $out = [];
        foreach ($this->definitions as $def) {
            $out[$def->group][] = $def;
        }

        return $out;
    }
}
