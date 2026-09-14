<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Commons;

use App\Media\MediaStorage;
use App\Media\Message\FetchCommonsPhoto;
use App\Media\PhotoFacts;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Given a Commons file we may want for a place, the poll answer: ready with
 * URLs, pending, or none. Shared by the coverage POI photo, the town card, the
 * harvest command and the Wikidata hop, so they never drift on when a fetch is
 * admitted, retried, reopened or given up on.
 *
 * Admitting a file we have never seen is the budgeted act, and so is reopening
 * a file declined for another place: both download. Retrying one already
 * admitted is not: the set of admitted files is already bounded by what the
 * budget let in, so a retry cannot grow the corpus.
 *
 * Every answer goes through PhotoValidator for the place that asked. A ready
 * file that place may not show answers `none`, and a file declined for a
 * scenic view stays declined when the same refusal holds, with no second call
 * to Commons (photo-uploads.md §5h).
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
     * @param callable(): bool $budgetAllows consulted only when a fetch would download a file we do not hold
     *
     * @return array<string, mixed> state ready, pending or none; the URLs and credit ride along when ready
     */
    public function stateFor(string $file, string $continent, PhotoPlace $place, callable $budgetAllows): array
    {
        if ($this->admissible($file, $place)) {
            if (!$budgetAllows()) {
                // No row is created, so a later visit under a fresh budget
                // admits it properly rather than inheriting a dead claim.
                return ['state' => 'none'];
            }
            $this->admit($file, $continent, $place);
        } elseif ($this->photos->retry($file, self::MAX_ATTEMPTS)) {
            $this->bus->dispatch(FetchCommonsPhoto::forPlace($file, $continent, $place));
        }

        $ready = $this->readyPhoto($file);
        if (null !== $ready) {
            // The place that asked, not the file, decides what is shown here.
            return PhotoValidator::verdict(PhotoFacts::fromEntry($ready), $place)->shows() ? $ready : ['state' => 'none'];
        }

        $row = $this->photos->find($file);
        $pending = CommonsPhotoState::Pending->value === ($row['state'] ?? '');

        return ['state' => $pending ? 'pending' : 'none'];
    }

    /**
     * Whether asking for this file for this place would start a download: the
     * file was never asked for, or it was declined for another place and
     * PhotoValidator shows it here on what Commons already said.
     */
    public function admissible(string $file, PhotoPlace $place): bool
    {
        $known = $this->photos->find($file);
        if (null === $known) {
            return true;
        }

        return CommonsPhotoState::Declined->value === $known['state']
            && PhotoValidator::verdict(PhotoFacts::ofCommonsRow($known), $place)->shows();
    }

    /**
     * Queue the fetch when admissible(). True when this caller queued it.
     *
     * The claim (or the reopen) is a database fact, so two callers asking at
     * once dispatch one fetch.
     */
    public function admit(string $file, string $continent, PhotoPlace $place): bool
    {
        if (!$this->admissible($file, $place)) {
            return false;
        }
        if (!$this->photos->claim($file) && !$this->photos->reopen($file)) {
            return false;
        }
        $this->bus->dispatch(FetchCommonsPhoto::forPlace($file, $continent, $place));

        return true;
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

        $photo = [
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
        // Where the camera stood, only when Commons records it. A scenic view
        // shows the photo only when this is near its pin (PhotoValidator), so
        // an absent key is a refusal there and means nothing anywhere else.
        if (null !== $row['camera_lat'] && null !== $row['camera_lng']) {
            $photo['cameraAt'] = [$row['camera_lat'], $row['camera_lng']];
        }

        return $photo;
    }
}
