<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Contribution\CatalogContributionService;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaModerationEvent;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Intake claiming (docs/specs/photo-uploads.md §3, §4): the wizard's mediaIds
 * are bound to the submission, the harvested coordinates become a single
 * distance and are then destroyed, and nothing a caller sends is trusted.
 */
final class MediaClaimTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CatalogContributionService $contributions;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->contributions = static::getContainer()->get(CatalogContributionService::class);
    }

    private function rider(string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function upload(User $owner, ?float $lat = null, ?float $lng = null): MediaUpload
    {
        $consent = new ConsentRecord(
            Uuid::v4(), (int) $owner->getId(),
            MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'),
        );
        $this->em->persist($consent);

        $upload = new MediaUpload(
            Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU',
            1200, 900, 4242, new \DateTimeImmutable('2025-10-04 09:12:33'), $lat, $lng, shard: 'EU-01');
        $this->em->persist($upload);
        $this->em->flush();

        return $upload;
    }

    /** @param list<string> $mediaIds */
    private function submitWith(User $by, array $mediaIds): void
    {
        $this->contributions->submit('add', [
            'type' => 'water-food',
            'lat' => 50.47,
            'lng' => 5.86,
            'details' => ['name' => 'Fontaine du test'],
            'mediaIds' => json_encode($mediaIds, \JSON_THROW_ON_ERROR),
        ], $by);
    }

    public function testClaimBindsRowsResolvesTheDistanceAndDestroysTheCoordinates(): void
    {
        $rider = $this->rider('claim-ok@example.test');
        // ~1.1 km north of the submission pin at 50.47 / 5.86.
        $withGps = $this->upload($rider, 50.48, 5.86);
        $withoutGps = $this->upload($rider);

        $this->submitWith($rider, [$withGps->getId()->toRfc4122(), $withoutGps->getId()->toRfc4122()]);
        $this->em->clear();

        $claimed = $this->em->find(MediaUpload::class, $withGps->getId());
        self::assertNotNull($claimed);
        self::assertNotNull($claimed->getSubmissionId(), 'the upload is bound to the submission');
        self::assertNotNull($claimed->getGpsDistanceM());
        self::assertGreaterThan(900, $claimed->getGpsDistanceM());
        self::assertLessThan(1400, $claimed->getGpsDistanceM());
        self::assertNull($claimed->getGpsLat(), 'raw coordinates never survive intake');
        self::assertNull($claimed->getGpsLng());

        $plain = $this->em->find(MediaUpload::class, $withoutGps->getId());
        self::assertNotNull($plain);
        self::assertNotNull($plain->getSubmissionId());
        self::assertNull($plain->getGpsDistanceM(), 'no coordinates, no distance — not a zero');

        $events = $this->em->getRepository(MediaModerationEvent::class)
            ->findBy(['mediaId' => $claimed->getId(), 'action' => MediaAction::Claimed]);
        self::assertCount(1, $events);
    }

    public function testAnotherRidersUploadCannotBeClaimed(): void
    {
        $rider = $this->rider('claim-thief@example.test');
        $stranger = $this->rider('claim-owner@example.test');
        $theirs = $this->upload($stranger);

        $this->expectException(ValidationFailedException::class);
        $this->submitWith($rider, [$theirs->getId()->toRfc4122()]);
    }

    public function testAnAlreadyClaimedUploadCannotBeReused(): void
    {
        $rider = $this->rider('claim-reuse@example.test');
        $upload = $this->upload($rider);
        $this->submitWith($rider, [$upload->getId()->toRfc4122()]);

        $this->expectException(ValidationFailedException::class);
        $this->submitWith($rider, [$upload->getId()->toRfc4122()]);
    }

    public function testAnUnknownIdIsRejected(): void
    {
        $rider = $this->rider('claim-unknown@example.test');

        $this->expectException(ValidationFailedException::class);
        $this->submitWith($rider, [Uuid::v4()->toRfc4122()]);
    }

    public function testMoreThanSixPhotosIsRejected(): void
    {
        $rider = $this->rider('claim-seven@example.test');
        $ids = [];
        for ($i = 0; $i < 7; ++$i) {
            $ids[] = $this->upload($rider)->getId()->toRfc4122();
        }

        $this->expectException(ValidationFailedException::class);
        $this->submitWith($rider, $ids);
    }

    public function testARejectedPhotoListRollsTheWholeSubmissionBack(): void
    {
        $rider = $this->rider('claim-rollback@example.test');
        $submissions = $this->em->getConnection();
        $before = (int) $submissions->fetchOne('SELECT COUNT(*) FROM submission WHERE user_id = ?', [(int) $rider->getId()]);

        try {
            $this->submitWith($rider, [Uuid::v4()->toRfc4122()]);
            self::fail('an unknown upload id must not submit');
        } catch (ValidationFailedException) {
            // expected
        }

        self::assertSame(
            $before,
            (int) $submissions->fetchOne('SELECT COUNT(*) FROM submission WHERE user_id = ?', [(int) $rider->getId()]),
            'photos ride the intake transaction — a bad list leaves no half-attached contribution',
        );
    }

    public function testASubmissionWithoutPhotosIsUnaffected(): void
    {
        $rider = $this->rider('claim-none@example.test');

        $this->contributions->submit('add', [
            'type' => 'water-food',
            'lat' => 50.47,
            'lng' => 5.86,
            'details' => ['name' => 'Fontaine sans photo'],
        ], $rider);

        self::assertCount(0, $this->em->getRepository(MediaUpload::class)->findAll());
    }
}
