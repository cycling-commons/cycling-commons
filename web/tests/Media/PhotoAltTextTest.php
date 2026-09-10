<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Media;

use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Photo descriptions: the thing that makes a photo exist for a screen reader.
 *
 * WCAG 1.1.1 is the most basic success criterion there is, and until 2026-08-28
 * `/accessibility` named this as a gap in its own words: "Photos have no
 * descriptions." That line is now deleted, which is the point of the item.
 *
 * @see docs/specs/photo-uploads.md §5e
 */
final class PhotoAltTextTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAPhotoStartsWithNoDescription(): void
    {
        self::assertNull($this->upload()->getAltText(), 'optional means absent, not empty string');
    }

    public function testADescriptionIsKept(): void
    {
        $upload = $this->upload();
        $upload->setAltText('A cobbled ramp between two hedges, climbing away from the camera.');

        self::assertSame('A cobbled ramp between two hedges, climbing away from the camera.', $upload->getAltText());
    }

    /**
     * An empty `alt` is not the same as an absent one: absent lets the render
     * side fall back to the item's name, empty would announce nothing at all.
     * Whitespace has to collapse to absent or the fallback never fires.
     */
    public function testWhitespaceCollapsesToAbsentSoTheFallbackStillFires(): void
    {
        $upload = $this->upload();

        foreach (['', '   ', "\n\t "] as $blank) {
            $upload->setAltText($blank);
            self::assertNull($upload->getAltText(), sprintf('%s should become null', var_export($blank, true)));
        }
    }

    public function testSurroundingSpaceIsTrimmed(): void
    {
        $upload = $this->upload();
        $upload->setAltText('  A water tap on a stone wall.  ');

        self::assertSame('A water tap on a stone wall.', $upload->getAltText());
    }

    public function testItSurvivesAReload(): void
    {
        $upload = $this->upload();
        $upload->setAltText('Sunrise over a dyke road.');
        $this->em->flush();
        $id = $upload->getId();
        $this->em->clear();

        $reloaded = $this->em->find(MediaUpload::class, $id);
        self::assertNotNull($reloaded);
        self::assertSame('Sunrise over a dyke road.', $reloaded->getAltText());
    }

    private function upload(): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), 1, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        $upload = new MediaUpload(Uuid::v4(), 1, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $this->em->flush();

        return $upload;
    }
}
