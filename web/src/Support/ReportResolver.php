<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Messaging\Entity\UserMessage;
use App\Support\Entity\ContentReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns a report into the three things a curator needs: what it is about, where
 * to go and look, and who wrote it.
 *
 * This exists because {@see ReportTarget} is polymorphic and the entity it
 * points at is a different class with a different author field in every case.
 * Doing that lookup in the controller would put five `match` arms in a method
 * that is already about a form.
 *
 * **It runs at the desk, never at the form.** A report is filed against an id
 * with no lookup at all, on purpose, so that the public form cannot be used to
 * ask whether a given id exists. The first time anything is resolved is when a
 * curator opens it, and by then the report already exists.
 *
 * **A missing target is a normal answer, not an error.** Content gets removed
 * between the report and the decision, and a report about something that is
 * already gone is closed as {@see ReportStatus::Moot}. So every field here is
 * nullable and the desk renders what it got.
 *
 * **No author is also a normal answer.** Map places and region descriptions are
 * not owned by one person: they start as seeded rows and grow through
 * submissions from many riders. There is nobody to send an Article 17 statement
 * to, and inventing one would name the last editor for somebody else's line.
 *
 * @see docs/specs/content-reports.md §8
 *
 * @api
 */
final class ReportResolver
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{label: ?string, author: ?User, exists: bool, link_route: ?string, link_params: array<string, string|int>}
     */
    public function resolve(ContentReport $report): array
    {
        return match ($report->getTargetType()) {
            ReportTarget::Route => $this->route($report->getTargetId()),
            ReportTarget::Item => $this->item($report->getTargetId()),
            ReportTarget::RegionText => $this->region($report->getTargetId()),
            ReportTarget::DisplayName => $this->rider($report->getTargetId()),
            ReportTarget::Message => $this->message($report->getTargetId()),
            ReportTarget::Photo => $this->photo($report->getTargetId()),
        };
    }

    /** @return array{label: ?string, author: ?User, exists: bool, link_route: ?string, link_params: array<string, string|int>} */
    private function route(string $id): array
    {
        $route = $this->em->find(RecommendedRoute::class, (int) $id);
        if (!$route instanceof RecommendedRoute) {
            return self::gone();
        }

        return [
            'label' => $route->getName(),
            'author' => $this->user($route->getProposedBy()),
            'exists' => true,
            // The curator desk, not the public page: it shows the moderation
            // history alongside, which is what somebody deciding needs.
            'link_route' => 'moderate_routes_detail',
            'link_params' => ['id' => (int) $id],
        ];
    }

    /** @return array{label: ?string, author: ?User, exists: bool, link_route: ?string, link_params: array<string, string|int>} */
    private function item(string $id): array
    {
        $item = $this->em->find(Item::class, (int) $id);
        if (!$item instanceof Item) {
            return self::gone();
        }

        return [
            'label' => $item->getName(),
            // Deliberately null. See the class docblock: a place on the map has
            // contributors, not an author.
            'author' => null,
            'exists' => true,
            'link_route' => null,
            'link_params' => [],
        ];
    }

    /** @return array{label: ?string, author: ?User, exists: bool, link_route: ?string, link_params: array<string, string|int>} */
    private function region(string $id): array
    {
        $region = $this->em->find(Region::class, (int) $id);
        if (!$region instanceof Region) {
            return self::gone();
        }

        return [
            'label' => $region->getName(),
            'author' => null,
            'exists' => true,
            'link_route' => 'region_detail',
            'link_params' => ['slug' => $region->getSlug()],
        ];
    }

    /** @return array{label: ?string, author: ?User, exists: bool, link_route: ?string, link_params: array<string, string|int>} */
    private function rider(string $id): array
    {
        if (!Uuid::isValid($id)) {
            return self::gone();
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['uuid' => Uuid::fromString($id)]);
        if (!$user instanceof User) {
            return self::gone();
        }

        return [
            'label' => $user->getDisplayName(),
            // The only case where the author and the target are the same person.
            'author' => $user,
            'exists' => true,
            'link_route' => 'rider_profile',
            'link_params' => ['uuid' => $id],
        ];
    }

    /** @return array{label: ?string, author: ?User, exists: bool, link_route: ?string, link_params: array<string, string|int>} */
    private function message(string $id): array
    {
        $message = $this->em->find(UserMessage::class, (int) $id);
        if (!$message instanceof UserMessage) {
            return self::gone();
        }

        return [
            'label' => $message->getRefLabel(),
            // A system notice has no sender, and there is nobody to answer for it.
            'author' => $this->user($message->getSenderId()),
            'exists' => true,
            'link_route' => null,
            'link_params' => [],
        ];
    }

    /**
     * The last rider who got a submission approved onto this item.
     *
     * Not used to attribute the item, and not called from {@see Item()}: it is
     * here for a curator who asks "who put that word there", which is a lookup
     * they can do, not a claim the system makes.
     */
    public function lastContributor(int $itemId): ?User
    {
        $submission = $this->em->getRepository(Submission::class)->findOneBy(
            ['itemId' => $itemId],
            ['id' => 'DESC'],
        );

        return $submission instanceof Submission ? $this->user($submission->getUserId()) : null;
    }

    private function user(?int $id): ?User
    {
        if (null === $id) {
            return null;
        }

        $user = $this->em->find(User::class, $id);

        return $user instanceof User ? $user : null;
    }

    /**
     * A picture.
     *
     * The uploader IS the author here, unlike a place or a region text: one
     * person pressed upload, and Article 17 owes that person the statement of
     * reasons. The label is the description they wrote, because a photo has no
     * name, and a curator reading the desk needs something other than a uuid.
     *
     * @return array{label: ?string, author: ?User, exists: bool, link_route: ?string, link_params: array<string, string|int>}
     */
    private function photo(string $id): array
    {
        if (!Uuid::isValid($id)) {
            return self::gone();
        }

        $photo = $this->em->find(MediaUpload::class, Uuid::fromString($id));
        if (!$photo instanceof MediaUpload) {
            return self::gone();
        }

        return [
            'label' => $photo->getAltText(),
            'author' => $this->user($photo->getUserId()),
            'exists' => true,
            'link_route' => 'photo_page',
            'link_params' => ['uuid' => $id],
        ];
    }

    /** @return array{label: null, author: null, exists: false, link_route: null, link_params: array<string, string|int>} */
    private static function gone(): array
    {
        return ['label' => null, 'author' => null, 'exists' => false, 'link_route' => null, 'link_params' => []];
    }
}
