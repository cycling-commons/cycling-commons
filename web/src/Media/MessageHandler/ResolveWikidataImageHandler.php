<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsFile;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsUnavailable;
use App\Media\Commons\WikidataImageRepository;
use App\Media\Message\FetchCommonsPhoto;
use App\Media\Message\ResolveWikidataImage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The second hop: Wikidata names the file, then Commons is asked for it.
 *
 * It is a separate hop because Wikidata has to be asked before Commons can be,
 * and because a QID with no P18 is an answer worth caching. Measured on
 * 2026-08-24, a 600-item sample of our own scenic rows: 196 have a P18, so
 * 32.7 percent give or take 3.8 points. Against 58,497 letter-P rows carrying a
 * `wikidata` tag that is roughly 19,100 photos, against 1,384 from tags alone.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
#[AsMessageHandler]
final readonly class ResolveWikidataImageHandler
{
    public function __construct(
        private CommonsApi $api,
        private WikidataImageRepository $images,
        private CommonsPhotoRepository $photos,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResolveWikidataImage $message): void
    {
        $known = $this->images->find($message->qid);
        if (null !== $known && $known['answered']) {
            return;   // a redelivery of a settled lookup changes nothing
        }

        try {
            $raw = $this->api->wikidataImage($message->qid);
        } catch (CommonsUnavailable $e) {
            // Our problem, so let go of the claim rather than leaving a row
            // nobody will ever fulfil.
            $this->images->release($message->qid);
            $this->logger->warning('Wikidata P18 lookup failed', ['qid' => $message->qid, 'why' => $e->getMessage()]);

            return;
        }

        // P18 gives a bare filename. Put it through the same gate an OSM tag
        // goes through, so a PDF or a video named by P18 is refused here rather
        // than downloaded and rejected later.
        $file = null === $raw ? null : CommonsFile::fromTags(['wikimedia_commons' => 'File:'.$raw]);
        $this->images->record($message->qid, $file);

        if (null !== $file && $this->photos->claim($file)) {
            $this->bus->dispatch(new FetchCommonsPhoto($file, $message->continent));
        }
    }
}
