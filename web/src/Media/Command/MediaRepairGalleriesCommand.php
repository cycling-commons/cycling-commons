<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Command;

use App\Catalog\Entity\Item;
use App\Media\Entity\MediaUpload;
use App\Media\MediaDecisionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Re-point item galleries at where their objects actually are.
 *
 * `photos[]` freezes an absolute URL at approval. The address has since moved
 * twice - `photos/<uuid>/` became `published/<uuid>/<rev>/`, and bucket names
 * gained their `-<cc>-<nn>` suffix - and neither move rewrote the frozen
 * strings. Entries therefore point at deleted keys and render nothing.
 *
 * Only the address is rebuilt. `credit` is a policy field: it is frozen when a
 * rider departs and blanked on disposal, so recomputing it here would quietly
 * undo those decisions. Same for `license` and `takenAt`.
 *
 * @see docs/specs/media-storage-architecture.md §4.1
 * @see docs/specs/photo-uploads.md §6b
 *
 * @api
 */
#[AsCommand(
    name: 'app:media:repair-galleries',
    description: 'Re-point item photos[] entries at their current object keys',
)]
final class MediaRepairGalleriesCommand extends Command
{
    /** Both key layouts carry the upload uuid, so one pattern recovers either. */
    private const string UUID_IN_URL = '~([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})~i';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly MediaDecisionService $decisions,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing.')
            ->addOption(
                'detach-deleted',
                null,
                InputOption::VALUE_NONE,
                'Also remove entries whose objects were deleted. These are granted takedowns that '
                .'failed to detach because the URL match missed - removing them completes a decision '
                .'already made, so it is opt-in rather than automatic.',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $detachDeleted = (bool) $input->getOption('detach-deleted');

        $itemIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $this->db->fetchFirstColumn("SELECT id FROM item WHERE jsonb_exists(attributes, 'photos') ORDER BY id"),
        );

        if ([] === $itemIds) {
            $io->success('No item carries a gallery.');

            return Command::SUCCESS;
        }

        $repaired = 0;
        $alreadyCorrect = 0;
        $detached = 0;
        $unresolved = 0;

        foreach ($itemIds as $itemId) {
            $item = $this->em->find(Item::class, $itemId);
            if (!$item instanceof Item) {
                continue;
            }

            $attributes = $item->getAttributes();
            $photos = $attributes['photos'] ?? null;
            if (!\is_array($photos)) {
                continue;
            }

            $rebuilt = [];
            $changed = false;

            foreach ($photos as $photo) {
                if (!\is_array($photo)) {
                    $rebuilt[] = $photo;
                    continue;
                }

                $upload = $this->resolve($photo, $itemId);
                if (null === $upload) {
                    $io->writeln(\sprintf(
                        '  item %d: no upload row behind %s - left alone',
                        $itemId,
                        (string) ($photo['sm'] ?? '(no sm)'),
                    ));
                    ++$unresolved;
                    $rebuilt[] = $photo;
                    continue;
                }

                if (null !== $upload->getObjectsDeletedAt()) {
                    if ($detachDeleted) {
                        $io->writeln(\sprintf('  item %d: detaching %s - its objects are gone', $itemId, $upload->getId()->toRfc4122()));
                        ++$detached;
                        $changed = true;
                        continue;
                    }
                    $io->writeln(\sprintf(
                        '  item %d: %s has no objects (takedown or disposal) - pass --detach-deleted to remove it',
                        $itemId,
                        $upload->getId()->toRfc4122(),
                    ));
                    ++$unresolved;
                    $rebuilt[] = $photo;
                    continue;
                }

                // `+` keeps the left-hand values, so the address is rebuilt while
                // every policy field the entry carried survives - and the key
                // order comes out matching describe(), keeping payloads stable.
                $current = $this->decisions->describe($upload);
                $fixed = ['id' => $current['id'], 'sm' => $current['sm'], 'lg' => $current['lg']] + $photo;

                if ($fixed === $photo) {
                    ++$alreadyCorrect;
                    $rebuilt[] = $photo;
                    continue;
                }

                $io->writeln(\sprintf('  item %d: %s', $itemId, (string) ($photo['sm'] ?? '(no sm)')));
                $io->writeln(\sprintf('        -> %s', $current['sm']));
                ++$repaired;
                $changed = true;
                $rebuilt[] = $fixed;
            }

            if (!$changed || $dryRun) {
                continue;
            }

            if ([] === $rebuilt) {
                // map.js reads `f.photos || [f.photo]`, and [] is truthy.
                unset($attributes['photos']);
            } else {
                $attributes['photos'] = $rebuilt;
            }
            $item->setAttributes($attributes);
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(\sprintf(
            '%d entr(ies) %s, %d already correct, %d detached, %d left alone.',
            $repaired,
            $dryRun ? 'would be repaired' : 'repaired',
            $alreadyCorrect,
            $detached,
            $unresolved,
        ));

        return Command::SUCCESS;
    }

    /**
     * The upload behind a gallery entry: its recorded id, else the uuid inside its URL.
     *
     * @param array<string, mixed> $photo
     */
    private function resolve(array $photo, int $itemId): ?MediaUpload
    {
        $id = $photo['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            $url = (string) ($photo['sm'] ?? $photo['lg'] ?? '');
            $id = 1 === preg_match(self::UUID_IN_URL, $url, $m) ? $m[1] : null;
        }

        if (!\is_string($id) || !Uuid::isValid($id)) {
            return null;
        }

        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($id));

        // Never re-point one item's gallery at another item's photo: the uuid
        // came out of a string, and a wrong match would publish the wrong image.
        return $upload instanceof MediaUpload && $upload->getItemId() === $itemId ? $upload : null;
    }
}
