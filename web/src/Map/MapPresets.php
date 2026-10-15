<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Map;

use App\Entity\User;
use App\Map\Entity\MapPreset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The map presets a rider keeps (docs/specs/map-presets.md section 2 and
 * 5): save, find, list, use for a tool, delete and count, every one for one
 * rider. Another rider's preset is a missing preset here; nothing answers
 * that it exists. At most MAX_PRESETS per rider. Which preset each tool uses
 * is `map_preset_use`, one row per rider and tool: using a preset for a tool
 * moves the tool off the preset that had it.
 *
 * @api
 */
final class MapPresets
{
    /** Owner decision, map-presets.md section 1. */
    public const int MAX_PRESETS = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
    }

    /**
     * Inserts, or updates when the input names a preset of this rider.
     *
     * @throws PresetRefused
     */
    public function save(User $rider, PresetInput $input): MapPreset
    {
        if (null !== $input->id) {
            $preset = $this->find($rider, $input->id) ?? throw new PresetRefused(PresetRefused::NOT_FOUND);
        } else {
            if ($this->count($rider) >= self::MAX_PRESETS) {
                throw new PresetRefused(PresetRefused::TOO_MANY);
            }
            $preset = new MapPreset((int) $rider->getId());
        }
        $preset->fill($input->name, $input->settings);
        $this->em->persist($preset);
        $this->em->flush();
        if (null !== $input->uses) {
            $this->db->executeStatement('DELETE FROM map_preset_use WHERE user_id = ? AND preset_id = ?', [(int) $rider->getId(), (int) $preset->getId()]);
            foreach ($input->uses as $context) {
                $this->assign($rider, (int) $preset->getId(), $context);
            }
        }

        return $preset;
    }

    /** The rider's preset, or null when it is missing or somebody else's. */
    public function find(User $rider, int $id): ?MapPreset
    {
        return $this->em->getRepository(MapPreset::class)->findOneBy(['id' => $id, 'userId' => (int) $rider->getId()]);
    }

    /** @return list<MapPreset> in the order saved */
    public function listFor(User $rider): array
    {
        return $this->em->getRepository(MapPreset::class)->findBy(
            ['userId' => (int) $rider->getId()],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    /** Use this preset of the rider for a tool; whether the preset was the rider's. */
    public function useFor(User $rider, int $id, string $context): bool
    {
        if (null === $this->find($rider, $id)) {
            return false;
        }
        $this->assign($rider, $id, $context);

        return true;
    }

    /**
     * The tools each of the rider's presets is used for.
     *
     * @return array<int, list<string>> preset id => contexts, in PresetInput::CONTEXTS order
     */
    public function usesFor(User $rider): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative('SELECT preset_id, context FROM map_preset_use WHERE user_id = ?', [(int) $rider->getId()]) as $row) {
            $out[(int) $row['preset_id']][] = (string) $row['context'];
        }

        return array_map(static fn (array $c): array => array_values(array_intersect(PresetInput::CONTEXTS, $c)), $out);
    }

    /**
     * The rider's presets as the map and the settings page read them.
     *
     * @return list<array{id: int, name: string, uses: list<string>, settings: array<string, mixed>}>
     */
    public function rowsFor(User $rider): array
    {
        $uses = $this->usesFor($rider);

        return array_map(static fn (MapPreset $p): array => [
            'id' => (int) $p->getId(), 'name' => $p->getName(), 'uses' => $uses[(int) $p->getId()] ?? [], 'settings' => $p->getSettings(),
        ], $this->listFor($rider));
    }

    /** Whether a preset of this rider was there to delete. */
    public function delete(User $rider, int $id): bool
    {
        $preset = $this->find($rider, $id);
        if (null === $preset) {
            return false;
        }
        $this->em->remove($preset);
        $this->em->flush();

        return true;
    }

    public function count(User $rider): int
    {
        return $this->em->getRepository(MapPreset::class)->count(['userId' => (int) $rider->getId()]);
    }

    /** One row per rider and tool: the tool moves to this preset. */
    private function assign(User $rider, int $presetId, string $context): void
    {
        $this->db->executeStatement(
            'INSERT INTO map_preset_use (user_id, context, preset_id) VALUES (:user, :context, :preset)
             ON CONFLICT (user_id, context) DO UPDATE SET preset_id = EXCLUDED.preset_id',
            ['user' => (int) $rider->getId(), 'context' => $context, 'preset' => $presetId],
        );
    }
}
