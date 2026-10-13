<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

use App\Entity\User;
use App\Roadmap\Entity\RoadmapProposal;
use App\Roadmap\Entity\RoadmapProposalImage;
use App\Support\Message\CheckPicture;
use App\Support\ScreenshotRejected;
use App\Support\ScreenshotStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Takes a rider's roadmap idea in: checks it, stores it as `pending` for the
 * ideas desk, and hands its picture, if any, to the worker.
 *
 * Only a signed-in rider with a confirmed address sends one, at most five a
 * day. The picture goes the bug screenshot's way (ScreenshotStore::hold() on
 * the web host, CheckPicture on the worker), so a file is never decoded here.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
final class ProposalIntake
{
    public const int TITLE_MIN = 4;
    public const int TEXT_MIN = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScreenshotStore $screenshots,
        private readonly MessageBusInterface $bus,
        private readonly RateLimiterFactory $roadmapProposeLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $form
     *
     * @throws RoadmapRefused with the key of the first thing wrong
     */
    public function submit(User $user, array $form, ?UploadedFile $image, string $locale): RoadmapProposal
    {
        if (!$user->isEmailVerified()) {
            throw new RoadmapRefused('roadmap.propose.error_unverified');
        }
        $title = self::text($form['title'] ?? null);
        $problem = self::text($form['problem'] ?? null);
        $idea = self::text($form['idea'] ?? null);
        $benefit = self::text($form['benefit'] ?? null);

        if (mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > RoadmapProposal::TITLE_MAX) {
            throw new RoadmapRefused('roadmap.propose.error_title');
        }
        if (mb_strlen($problem) < self::TEXT_MIN || mb_strlen($problem) > RoadmapProposal::TEXT_MAX) {
            throw new RoadmapRefused('roadmap.propose.error_problem');
        }
        if (mb_strlen($idea) < self::TEXT_MIN || mb_strlen($idea) > RoadmapProposal::TEXT_MAX) {
            throw new RoadmapRefused('roadmap.propose.error_idea');
        }
        if (mb_strlen($benefit) > RoadmapProposal::BENEFIT_MAX) {
            throw new RoadmapRefused('roadmap.propose.error_benefit');
        }

        // Read the picture before spending the rider's allowance on a file we refuse.
        $raw = null;
        if (null !== $image) {
            if (!$image->isValid() || (int) $image->getSize() > ScreenshotStore::MAX_UPLOAD_BYTES) {
                throw new RoadmapRefused('support.bug.error.shot_too_large');
            }
            try {
                $raw = $this->screenshots->hold((string) file_get_contents($image->getPathname()));
            } catch (ScreenshotRejected $e) {
                throw new RoadmapRefused($e->translationKey());
            }
        }

        if (!$this->roadmapProposeLimiter->create('user-'.(int) $user->getId())->consume()->isAccepted()) {
            throw new RoadmapRefused('roadmap.propose.error_too_many');
        }

        $proposal = new RoadmapProposal((int) $user->getId(), $title, $problem, $idea, '' === $benefit ? null : $benefit, $locale);
        $this->em->persist($proposal);
        $picture = null;
        if (null !== $raw) {
            // The rider's permission to show it with the proposal, if they gave it.
            $picture = RoadmapProposalImage::pending($proposal, $raw, '1' === ($form['image_may_show'] ?? null));
            $this->em->persist($picture);
        }
        $this->em->flush();

        if (null !== $picture) {
            $this->bus->dispatch(new CheckPicture(CheckPicture::ROADMAP, (int) $picture->getId()));
        }

        return $proposal;
    }

    /** Ideas waiting on the desk. */
    public function pendingCount(): int
    {
        return $this->em->getRepository(RoadmapProposal::class)->count(['status' => ProposalStatus::Pending]);
    }

    /** @return list<RoadmapProposal> the rider's own ideas, newest first */
    public function ideasOf(int $userId): array
    {
        return $this->em->getRepository(RoadmapProposal::class)->findBy(['userId' => $userId], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function imageOf(RoadmapProposal $proposal): ?RoadmapProposalImage
    {
        return $this->em->getRepository(RoadmapProposalImage::class)->findOneBy(['proposal' => $proposal]);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
    }
}
