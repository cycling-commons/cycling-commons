<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Commons;

use App\Media\MediaStorage;
use App\Media\Message\FetchCommonsPhoto;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Given a Commons file we may want, the poll answer: ready with URLs, pending,
 * or none. Shared by the coverage POI photo and the town card, so the two never
 * drift on when a fetch is admitted, retried, or given up on.
 *
 * Admitting a file we have never seen is the budgeted act. Retrying one we
 * already hold is not: the set of admitted files is already bounded by what the
 * budget let in, so a retry cannot grow the corpus.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
final readonly class CommonsPhotoAdmission
{
    /** Two retries after the first attempt, then the slot stays empty. */
    public const int MAX_ATTEMPTS = 3;

    public function __construct(
        private CommonsPhotoRepository $photos,
        private MediaStorage $storage,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param callable(): bool $budgetAllows consulted only when a NEW file would be admitted
     *
     * @return array<string, mixed> state ready, pending or none; the URLs and credit ride along when ready
     */
    public function stateFor(string $file, string $continent, callable $budgetAllows): array
    {
        $known = $this->photos->find($file);
        if (null === $known) {
            if (!$budgetAllows()) {
                // No row is created, so a later visit under a fresh budget
                // admits it properly rather than inheriting a dead claim.
                return ['state' => 'none'];
            }
            if ($this->photos->claim($file)) {
                $this->bus->dispatch(new FetchCommonsPhoto($file, $continent));
            }
        } elseif ($this->photos->retry($file, self::MAX_ATTEMPTS)) {
            $this->bus->dispatch(new FetchCommonsPhoto($file, $continent));
        }

        $ready = $this->readyPhoto($file);
        if (null !== $ready) {
            return $ready;
        }

        $row = $this->photos->find($file);
        $pending = CommonsPhotoState::Pending->value === ($row['state'] ?? '');

        return ['state' => $pending ? 'pending' : 'none'];
    }

    /**
     * The published shape of a file we already hold, or null when we do not.
     *
     * Public because the backfill that localises the catalogue's old Commons
     * hotlinks writes exactly this into `item.attributes->photo`
     * (LocaliseCommonsPhotosCommand). Attribution is the reason it is shared
     * rather than copied: credit, the uploader's Commons page and the licence
     * travel with the URLs, and CC BY-SA is only satisfied while they do. Two
     * implementations of this shape would eventually disagree, and the half
     * that lost would be the half nobody was looking at.
     *
     * @return array<string, mixed>|null
     */
    public function readyPhoto(string $file): ?array
    {
        $row = $this->photos->find($file);
        if (null === $row || CommonsPhotoState::Ready->value !== $row['state']) {
            return null;
        }

        /** @var string $bucket */
        $bucket = $row['storage_bucket'];
        /** @var string $prefix */
        $prefix = $row['storage_prefix'];
        $page = str_replace(' ', '_', $file);

        return [
            'state' => 'ready',
            'sm' => $this->storage->url($bucket, $prefix, 'sm'),
            'lg' => $this->storage->url($bucket, $prefix, 'lg'),
            'credit' => $row['credit'],
            'creditUrl' => null === $row['credit_user']
                ? ''
                : 'https://commons.wikimedia.org/wiki/User:'.rawurlencode(str_replace(' ', '_', $row['credit_user'])),
            'license' => $row['license'],
            'source' => 'https://commons.wikimedia.org/wiki/File:'.rawurlencode($page),
        ];
    }
}
