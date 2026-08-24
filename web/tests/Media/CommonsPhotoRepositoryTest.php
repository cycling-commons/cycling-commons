<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsPhotoState;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommonsPhotoRepositoryTest extends KernelTestCase
{
    private CommonsPhotoRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var CommonsPhotoRepository $repo */
        $repo = $container->get(CommonsPhotoRepository::class);
        $this->repo = $repo;
        /** @var Connection $db */
        $db = $container->get('doctrine.dbal.default_connection');
        $db->executeStatement("DELETE FROM commons_photo WHERE file LIKE 'Test %'");
    }

    public function testClaimIsWonExactlyOnce(): void
    {
        self::assertTrue($this->repo->claim('Test one.jpg'), 'the first caller creates the row');
        self::assertFalse($this->repo->claim('Test one.jpg'), 'a second reader must not queue a second download');

        $row = $this->repo->find('Test one.jpg');
        self::assertNotNull($row);
        self::assertSame(CommonsPhotoState::Pending->value, $row['state']);
    }

    public function testReadyCarriesCreditAndStorage(): void
    {
        $this->repo->claim('Test two.jpg');
        $this->repo->markReady('Test two.jpg', 'cc-media-eu-01', 'published/abc/r1', 'Jean-Pol GRANDMONT', 'Jean-Pol_GRANDMONT', 'CC BY-SA 3.0', 1400, 933);

        $row = $this->repo->find('Test two.jpg');
        self::assertNotNull($row);
        self::assertSame(CommonsPhotoState::Ready->value, $row['state']);
        self::assertSame('cc-media-eu-01', $row['storage_bucket']);
        self::assertSame('published/abc/r1', $row['storage_prefix']);
        self::assertSame('CC BY-SA 3.0', $row['license']);
        self::assertSame(1400, $row['width']);
    }

    public function testUnusableIsTerminalAndNeverReclaimed(): void
    {
        $this->repo->claim('Test three.jpg');
        $this->repo->markUnusable('Test three.jpg', 'no_free_licence');

        $row = $this->repo->find('Test three.jpg');
        self::assertNotNull($row);
        self::assertSame(CommonsPhotoState::Unusable->value, $row['state']);
        self::assertFalse($this->repo->claim('Test three.jpg'), 'a settled row is never re-claimed');
    }

    public function testFailedIsAboutUsAndStaysDistinct(): void
    {
        $this->repo->claim('Test four.jpg');
        $this->repo->markFailed('Test four.jpg', 'commons_unavailable');

        $row = $this->repo->find('Test four.jpg');
        self::assertNotNull($row);
        self::assertSame(CommonsPhotoState::Failed->value, $row['state']);
    }

    public function testAFailedRowIsHandedBackToTheQueue(): void
    {
        $this->repo->claim('Test five.jpg');
        $this->repo->markFailed('Test five.jpg', 'download_failed');

        self::assertFalse($this->repo->claim('Test five.jpg'), 'claim alone would leave it stuck');
        self::assertTrue($this->repo->retry('Test five.jpg', 3), 'so retry is what re-queues it');

        $row = $this->repo->find('Test five.jpg');
        self::assertNotNull($row);
        self::assertSame(CommonsPhotoState::Pending->value, $row['state']);
    }

    public function testRetryGivesUpAtTheCap(): void
    {
        $this->repo->claim('Test six.jpg');
        for ($i = 0; $i < 3; ++$i) {
            $this->repo->markFailed('Test six.jpg', 'download_failed');
        }
        self::assertFalse($this->repo->retry('Test six.jpg', 3), 'three attempts is enough');
    }

    public function testAnUnusableRowIsNeverRetried(): void
    {
        $this->repo->claim('Test seven.jpg');
        $this->repo->markUnusable('Test seven.jpg', 'no_free_licence');

        self::assertFalse($this->repo->retry('Test seven.jpg', 3),
            'a verdict about the file does not change by asking again');
    }

    public function testALostJobLeavesAStalePendingRowThatIsRequeued(): void
    {
        $this->repo->claim('Test eight.jpg');
        // A message that never arrived: the row says pending, no job exists.
        self::assertFalse($this->repo->retry('Test eight.jpg', 3), 'a fresh claim is not stale yet');

        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $db->executeStatement("UPDATE commons_photo SET requested_at = NOW() - INTERVAL '1 hour' WHERE file = 'Test eight.jpg'");

        self::assertTrue($this->repo->retry('Test eight.jpg', 3),
            'an abandoned claim must be picked up, or that POI spins forever for everyone');
    }

    public function testUnknownFileHasNoRow(): void
    {
        self::assertNull($this->repo->find('Test nothing.jpg'));
    }
}
