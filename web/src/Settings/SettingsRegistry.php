<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Settings;

use App\Catalog\CuratedReadiness;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The single source of truth for what is runtime-configurable: which keys
 * exist, what each one may be set to, and how it renders in the admin.
 *
 * ## The keys are the parameter names
 *
 * A setting's key IS its container-parameter name, so the YAML files under
 * `config/packages/` keep owning the defaults and keep their explanatory
 * comments. Nothing here duplicates a number: the default is read out of the
 * parameter bag at construction, which means a fresh database behaves exactly
 * as the pre-settings code did, and a missing parameter fails at boot rather
 * than silently defaulting to zero.
 *
 * ## Why only editorial thresholds
 *
 * `coverage.tiles_enabled`, `coverage.manifest_url` and `coverage.csp_host`
 * are deliberately NOT here (owner decision, 2026-07-29). They are
 * infrastructure — a feature flag, a bucket URL and a Content-Security-Policy
 * host — and a web form that rewrites a CSP host is an XSS-relaxation surface,
 * while a writable manifest URL is an SSRF/exfiltration surface. Both stay
 * env-backed, where changing them needs deploy access rather than a session
 * cookie. What lives here is the editorial dial an admin should be able to
 * turn at 22:00 without a release.
 *
 * ## Why every setting is an integer
 *
 * All six are counts or thresholds, so the type system is one type and the
 * validation is one range check. When the first non-integer setting arrives it
 * needs a type discriminator here, a wider column in `system_setting`, and a
 * matching input in the template — that is deliberately a schema change, not
 * something that sneaks in behind a generic `mixed`.
 *
 * @see docs/specs/system-configuration.md §2
 *
 * @api Autowired; consumed by SystemSettings, SystemSettingsWriter and the admin page.
 */
final class SettingsRegistry
{
    public const string MAP_CURATED_THRESHOLD = 'map.curated_default_threshold';
    public const string MAP_CURATED_MIN_BLOCKS = 'map.curated_default_min_blocks';
    public const string MAP_CURATED_MIN_PER_BLOCK = 'map.curated_default_min_per_block';
    public const string ROUTE_REGION_ACTIVE_CAP = 'route.region_active_cap';
    public const string ROUTE_RIDE_VERIFY_THRESHOLD = 'route.ride_verify_threshold';
    public const string MODERATION_RETENTION_MONTHS = 'moderation.retention_months';
    public const string MEDIA_URGENT_BREAKER_HOURLY = 'media.urgent_breaker_hourly';
    public const string MEDIA_URGENT_BREAKER_DAILY = 'media.urgent_breaker_daily';

    public const string GROUP_MAP = 'map';
    public const string GROUP_ROUTES = 'routes';
    public const string GROUP_MODERATION = 'moderation';
    public const string GROUP_MEDIA = 'media';

    /** @var array<string, SettingDefinition> keyed by setting key, in render order */
    private array $definitions = [];

    public function __construct(ParameterBagInterface $params)
    {
        // [key, min, max, group]. Ranges are guardrails against a fat finger,
        // not editorial opinion: they bound what cannot be meant, and leave the
        // judgement to the admin.
        $table = [
            // A region cannot need fewer than one curated item, and a four-digit
            // total would gate every region in the world out of Curated forever.
            [self::MAP_CURATED_THRESHOLD, 1, 1000, self::GROUP_MAP],
            // 1 reverts the gate to a pure total; the ceiling is the number of
            // blocks that exist, so the gate can never demand an eighth block.
            [self::MAP_CURATED_MIN_BLOCKS, 1, \count(CuratedReadiness::BLOCKS), self::GROUP_MAP],
            [self::MAP_CURATED_MIN_PER_BLOCK, 1, 100, self::GROUP_MAP],
            // 0 would freeze every region's queue outright, which is a job for
            // disabling proposals, not for the cap.
            [self::ROUTE_REGION_ACTIVE_CAP, 1, 1000, self::GROUP_ROUTES],
            // 1 means a single rider's confirmation verifies a route — thin, but
            // a legitimate setting for a young region. 0 would verify on nothing.
            [self::ROUTE_RIDE_VERIFY_THRESHOLD, 1, 100, self::GROUP_ROUTES],
            // Months, so the floor is "one month" and the ceiling ten years;
            // 0 would delete decided rows the moment they were decided.
            [self::MODERATION_RETENTION_MONTHS, 1, 120, self::GROUP_MODERATION],
            // The auto-withhold circuit breaker (photo-uploads.md §6c). 0 is
            // allowed here and means something real, unlike everywhere else in
            // this table: it turns the lever off entirely, so anonymous reports
            // never take a photo down on their own. That is a legitimate
            // setting during a sustained attack, and it is the one dial an
            // owner may need at 03:00. The ceilings are "more than any genuine
            // day could produce" — past them the budget is not a budget.
            [self::MEDIA_URGENT_BREAKER_HOURLY, 0, 500, self::GROUP_MEDIA],
            [self::MEDIA_URGENT_BREAKER_DAILY, 0, 2000, self::GROUP_MEDIA],
        ];

        foreach ($table as [$key, $min, $max, $group]) {
            $raw = $params->get($key);
            if (!is_numeric($raw)) {
                // The parameter is the default. A non-numeric one means the
                // YAML and this table have drifted, and the honest moment to
                // find that out is boot, not the first admin who opens the page.
                throw new \LogicException(sprintf('Setting "%s" needs an integer container parameter of the same name.', $key));
            }
            $slug = str_replace('.', '_', $key);
            $this->definitions[$key] = new SettingDefinition(
                key: $key,
                default: (int) $raw,
                min: $min,
                max: $max,
                group: $group,
                labelKey: 'admin.settings.field.'.$slug.'.label',
                helpKey: 'admin.settings.field.'.$slug.'.help',
            );
        }
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
     * The definitions bucketed for the admin page, groups in declaration order.
     *
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
