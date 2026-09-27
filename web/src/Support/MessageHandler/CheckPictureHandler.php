<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support\MessageHandler;

use App\Messaging\Entity\CuratorPostImage;
use App\Support\Entity\BugScreenshot;
use App\Support\Message\CheckPicture;
use App\Support\ScreenshotRejected;
use App\Support\ScreenshotStore;
use App\Support\ScreenshotUnscanned;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The worker's half of a picture a person sent us: scan, draw again, keep.
 *
 * The web host held the raw bytes and queued this; ImageMagick never runs
 * there (owner 2026-09-20: the web hosts are too light for it, and decoding a
 * stranger's file is the riskiest code the site runs). Here the bytes go
 * through {@see ScreenshotStore::render()}: ClamAV first, then the decode and
 * the new drawing.
 *
 * Three outcomes. A drawing: the picture is ready and the raw bytes are gone.
 * A refusal (infected, unreadable, too large): the picture is refused, the
 * bytes are dropped and only the reason stays for the curator. No scanner:
 * {@see ScreenshotUnscanned} is thrown on, so Messenger retries and the bytes
 * stay held and unserved; after the last retry the message waits in the
 * `failed` transport and the picture stays pending until it is retried.
 *
 * @see docs/specs/contact-and-support.md §6
 *
 * @api
 */
#[AsMessageHandler]
final readonly class CheckPictureHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ScreenshotStore $images,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckPicture $message): void
    {
        [$picture, $as, $maxBytes] = match ($message->kind) {
            CheckPicture::BUG => [$this->em->find(BugScreenshot::class, $message->id), 'png', BugScreenshot::MAX_BYTES],
            CheckPicture::ROOM => [$this->em->find(CuratorPostImage::class, $message->id), 'webp', CuratorPostImage::MAX_BYTES],
            default => [null, 'png', 0],
        };
        // Gone (the report or post was deleted), or already settled.
        if (null === $picture || !$picture->isPending()) {
            return;
        }

        try {
            $picture->markReady($this->images->render($picture->getBytes(), $as, $maxBytes));
        } catch (ScreenshotUnscanned $e) {
            // Retried by Messenger; the picture stays pending and unserved.
            throw $e;
        } catch (ScreenshotRejected $e) {
            $this->logger->notice('A held picture was refused on the worker.', [
                'kind' => $message->kind,
                'id' => $message->id,
                'reason' => $e->translationKey(),
            ]);
            $picture->markRefused($e->translationKey());
        }

        $this->em->flush();
    }
}
