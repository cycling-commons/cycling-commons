<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Provider;

use App\Entity\User;
use App\Provider\Entity\DataProvider;
use App\Provider\Exception\ProviderRuleException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one writer of `data_provider`, and the one place its rules live.
 *
 * The desk is a form; this is what the form is not allowed to talk it out of.
 * Every rule below is enforced here rather than in the controller, because a
 * rule that lives in a controller is a rule the next caller does not have.
 *
 * Every accepted change writes a `data_provider_change` row and drops the
 * citation cache, so the map and the credits page cannot go on showing what
 * the table no longer says.
 *
 * @see docs/specs/data-provider-hierarchy.md §8
 *
 * @api
 */
final class ProviderRegistry
{
    /**
     * The registry band, which sits ENTIRELY below every rider source.
     *
     * `manual`, `user` and `scout` are not expressible as a registry rank at
     * all: {@see \App\Catalog\ItemSource::dedupeRank()} puts every authority
     * on one rung beneath them, whatever number is stored here. So this cap
     * is not what keeps a curator under a rider; the ladder's shape is. It
     * keeps the number readable, and it keeps a typed 999999999 from looking
     * like a decision somebody made.
     */
    public const int RANK_MAX = 9999;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly ProviderCitations $citations,
    ) {
    }

    /**
     * @return list<DataProvider>
     */
    public function all(): array
    {
        return $this->em->getRepository(DataProvider::class)->findBy([], ['name' => 'ASC']);
    }

    public function find(int $id): ?DataProvider
    {
        return $this->em->getRepository(DataProvider::class)->find($id);
    }

    /**
     * Applies a desk edit, or refuses it with the reason.
     *
     * @param array<string, bool|int|string|null> $fields
     *
     * @throws ProviderRuleException
     */
    public function update(DataProvider $provider, array $fields, User $actor): void
    {
        $before = $this->snapshot($provider);

        if (\array_key_exists('name', $fields)) {
            $provider->setName($this->text($fields['name'], 'name', 120));
        }
        if (\array_key_exists('fullName', $fields)) {
            $provider->setFullName($this->text($fields['fullName'], 'fullName', 255));
        }
        if (\array_key_exists('homepage', $fields)) {
            $provider->setHomepage($this->url($fields['homepage']));
        }
        if (\array_key_exists('licence', $fields)) {
            $provider->setLicence($this->text($fields['licence'], 'licence', 255));
        }
        if (\array_key_exists('licenceCode', $fields)) {
            $provider->setLicenceCode($this->text($fields['licenceCode'], 'licenceCode', 40));
        }
        if (\array_key_exists('attribution', $fields)) {
            $value = trim((string) ($fields['attribution'] ?? ''));
            $provider->setAttribution('' === $value ? null : $value);
        }
        if (\array_key_exists('creator', $fields)) {
            $value = trim((string) ($fields['creator'] ?? ''));
            $provider->setCreator('' === $value ? null : $value);
        }
        if (\array_key_exists('blurb', $fields)) {
            $value = trim((string) ($fields['blurb'] ?? ''));
            $provider->setBlurb('' === $value ? null : $value);
        }
        if (\array_key_exists('matchRadiusM', $fields)) {
            $provider->setMatchRadiusM($this->radius($fields['matchRadiusM']));
        }
        if (\array_key_exists('promoted', $fields)) {
            $provider->setPromoted((bool) $fields['promoted']);
        }
        if (\array_key_exists('rank', $fields)) {
            $this->applyRank($provider, $fields['rank']);
        }
        if (\array_key_exists('enabled', $fields)) {
            $this->applyEnabled($provider, (bool) $fields['enabled']);
        }

        // Last, and after every field is in place: a save that turns a
        // provider on must be judged on the licence and attribution it is
        // being saved WITH, not on the ones it had a moment ago.
        $this->assertMayBeEnabled($provider);

        $this->em->flush();
        $this->record($provider, $before, $actor);
        $this->citations->invalidate();
    }

    /**
     * A system row is the credit behind every raw pin or every Wikidata row
     * on the map. Deleting one would take that credit off the whole map, so
     * nothing on the desk can.
     *
     * @throws ProviderRuleException
     */
    public function delete(DataProvider $provider, User $actor): void
    {
        if ($provider->isSystem()) {
            throw new ProviderRuleException('provider.error.system_undeletable');
        }

        $used = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM item WHERE provider_id = :id',
            ['id' => $provider->getId()],
        );
        if ($used > 0) {
            // Deleting would orphan rows that name this publisher, and the
            // rows are the reason the credit is owed. Disable instead: it
            // keeps the rows and stops the refreshing, which is what
            // "we are done with this source" actually means.
            throw new ProviderRuleException('provider.error.in_use');
        }

        $this->record($provider, ['deleted' => 'no'], $actor, ['deleted' => 'yes']);
        $this->em->remove($provider);
        $this->em->flush();
        $this->citations->invalidate();
    }

    /**
     * @throws ProviderRuleException
     */
    private function applyRank(DataProvider $provider, mixed $raw): void
    {
        if ($provider->isSystem() && (int) $raw !== $provider->getRank()) {
            // OpenStreetMap and Wikidata sit where the ladder has always put
            // them. Moving one silently reorders every duplicate decision on
            // the map, which is not a thing a form should be able to do.
            throw new ProviderRuleException('provider.error.system_rank');
        }

        $rank = (int) $raw;
        if ($rank < 0 || $rank > self::RANK_MAX) {
            throw new ProviderRuleException('provider.error.rank_range');
        }

        $provider->setRank($rank);
    }

    private function applyEnabled(DataProvider $provider, bool $enabled): void
    {
        $provider->setEnabled($enabled);
    }

    /**
     * @throws ProviderRuleException
     */
    private function assertMayBeEnabled(DataProvider $provider): void
    {
        if (!$provider->isEnabled()) {
            return;
        }
        if (!LicenceObligation::requiresAttribution($provider->getLicenceCode())) {
            return;
        }
        if (null === $provider->getAttribution() || '' === trim($provider->getAttribution())) {
            // Serving rows from a dataset whose licence names a notice, while
            // showing no notice, is the licence breach this whole registry
            // exists to make impossible.
            throw new ProviderRuleException('provider.error.attribution_required');
        }
    }

    /**
     * @throws ProviderRuleException
     */
    private function text(mixed $raw, string $field, int $max): string
    {
        $value = trim((string) $raw);
        if ('' === $value) {
            throw new ProviderRuleException('provider.error.required');
        }
        if (mb_strlen($value) > $max) {
            throw new ProviderRuleException('provider.error.too_long');
        }

        return $value;
    }

    /**
     * @throws ProviderRuleException
     */
    private function url(mixed $raw): string
    {
        $value = trim((string) $raw);
        if (1 !== preg_match('~^https?://~i', $value) || false === filter_var($value, \FILTER_VALIDATE_URL)) {
            throw new ProviderRuleException('provider.error.bad_url');
        }

        return $value;
    }

    /**
     * @throws ProviderRuleException
     */
    private function radius(mixed $raw): int
    {
        $value = (int) $raw;
        if ($value < 1 || $value > 5000) {
            throw new ProviderRuleException('provider.error.radius_range');
        }

        return $value;
    }

    /**
     * The fields a curator can move, as strings, for the trail.
     *
     * @return array<string, string>
     */
    private function snapshot(DataProvider $p): array
    {
        return [
            'name' => $p->getName(),
            'fullName' => $p->getFullName(),
            'homepage' => $p->getHomepage(),
            'licence' => $p->getLicence(),
            'licenceCode' => $p->getLicenceCode(),
            'attribution' => (string) $p->getAttribution(),
            'creator' => (string) $p->getCreator(),
            'blurb' => (string) $p->getBlurb(),
            'rank' => (string) $p->getRank(),
            'matchRadiusM' => (string) $p->getMatchRadiusM(),
            'promoted' => $p->isPromoted() ? 'yes' : 'no',
            'enabled' => $p->isEnabled() ? 'yes' : 'no',
        ];
    }

    /**
     * One row per field that actually moved.
     *
     * A save that changed nothing writes nothing: a trail of no-ops is a
     * trail nobody reads.
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     */
    private function record(DataProvider $provider, array $before, User $actor, ?array $after = null): void
    {
        $after ??= $this->snapshot($provider);
        $now = new \DateTimeImmutable();

        foreach ($before as $field => $old) {
            $new = $after[$field] ?? null;
            if ($old === $new) {
                continue;
            }
            $this->db->insert('data_provider_change', [
                'provider_id' => $provider->getId(),
                'field' => $field,
                'old_value' => $old,
                'new_value' => $new,
                'changed_by' => $actor->getId(),
                'changed_at' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * One provider's trail, newest first.
     *
     * @return list<array{field: string, old_value: ?string, new_value: ?string, changed_at: string, actor: ?string}>
     */
    public function history(DataProvider $provider, int $limit = 50): array
    {
        /** @var list<array{field: string, old_value: ?string, new_value: ?string, changed_at: string, actor: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT c.field, c.old_value, c.new_value, c.changed_at, u.display_name AS actor
               FROM data_provider_change c
          LEFT JOIN users u ON u.id = c.changed_by
              WHERE c.provider_id = :id
           ORDER BY c.changed_at DESC, c.id DESC
              LIMIT :limit',
            ['id' => $provider->getId(), 'limit' => $limit],
        );

        return $rows;
    }
}
