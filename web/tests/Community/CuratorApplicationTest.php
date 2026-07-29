<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CuratorApplicationService;
use App\Community\CuratorApplicationStatus;
use App\Community\OsmUserVerifier;
use App\Community\PublicNoteFilter;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Service\AdminActionLogger;
use App\Service\UserAdminService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * 2026-07-29-country-requests-and-curator-signup-design.md §5.2.
 */
final class CuratorApplicationTest extends KernelTestCase
{
    private function user(string $email): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName('Applicant '.substr(md5($email), 0, 6));
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function seedCountryRegion(string $cc, string $slug): int
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 1000, ?, ?, 2, 'test', NOW(), NOW())",
            [$slug, strtoupper($slug), $cc, $cc],
        );

        return (int) $db->lastInsertId('region_id_seq');
    }

    /**
     * The container-provided CuratorApplicationService wires the real
     * OsmUserVerifier (real HttpClient), which is unsuitable for tests that
     * need to control the OSM response. Building the service by hand with a
     * MockHttpClient-backed verifier is the least invasive way to pin the
     * three OSM outcomes without touching the production DI wiring.
     */
    private function serviceWithVerifier(OsmUserVerifier $osm): CuratorApplicationService
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        return new CuratorApplicationService(
            $em,
            $em->getConnection(),
            new PublicNoteFilter(),
            $osm,
            $c->get(UserAdminService::class),
            $c->get(AdminActionLogger::class),
            $c->get(MessageService::class),
        );
    }

    public function testSubmittingStoresACleanApplication(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('PT', 'portugal-test');
        $svc = self::getContainer()->get(CuratorApplicationService::class);

        $app = $svc->submit($this->user('curator-a@example.test'), 'PT', null, null, '  I live here  ');

        self::assertSame(CuratorApplicationStatus::Pending, $app->getStatus());
        self::assertSame('I live here', $app->getAbout(), 'the note filter trimmed it');
        self::assertNull($app->getRequestedRegionId(), 'null means the whole country');
    }

    public function testASecondPendingApplicationForTheSameCountryIsRefused(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('GR', 'greece-test');
        $svc = self::getContainer()->get(CuratorApplicationService::class);
        $u = $this->user('curator-dup@example.test');

        $svc->submit($u, 'GR', null, null, 'first');

        $this->expectException(\DomainException::class);
        $svc->submit($u, 'GR', null, null, 'second');
    }

    public function testACountryWithNoRegionCannotBeAppliedFor(): void
    {
        // §4: with no region there is nothing to anchor a submission to, so
        // there is no evidence path and nothing to scope a curator to.
        self::bootKernel();
        $svc = self::getContainer()->get(CuratorApplicationService::class);

        $this->expectException(\DomainException::class);
        $svc->submit($this->user('curator-nocountry@example.test'), 'MN', null, null, 'nothing here yet');
    }

    public function testALinkInTheAboutTextIsRejected(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('IE', 'ireland-test');
        $svc = self::getContainer()->get(CuratorApplicationService::class);

        $this->expectException(\App\Community\InvalidNoteException::class);
        $svc->submit($this->user('curator-spam@example.test'), 'IE', null, null, 'buy at https://spam.example');
    }

    public function testAnOsmHandleThatDoesNotExistIsRecordedAsCheckedAndAbsent(): void
    {
        // §6: a 404 IS evidence — the applicant claimed a handle that isn't
        // there. It must render differently from "we never checked".
        self::bootKernel();
        $this->seedCountryRegion('IT', 'italy-test');
        $osm = new OsmUserVerifier(new MockHttpClient([new MockResponse('', ['http_code' => 404])]));
        $svc = $this->serviceWithVerifier($osm);

        $app = $svc->submit($this->user('curator-osm-absent@example.test'), 'IT', null, 'nobody-here', 'about me');

        self::assertNotNull($app->getOsmVerifiedAt(), 'reachable, so we did check');
        self::assertFalse($app->getOsmExists(), 'checked and not found');
        self::assertNull($app->getOsmChangesetCount(), 'a count for a nonexistent user is meaningless');
    }

    public function testAnUnreachableOsmLeavesAllThreeEvidenceFieldsUnset(): void
    {
        // §6: "OSM was down" must never collapse into "not found" — both
        // verifiedAt and exists stay null, distinct from the 404 case above.
        self::bootKernel();
        $this->seedCountryRegion('BE', 'belgium-test');
        $osm = new OsmUserVerifier(new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'timeout']);
        }));
        $svc = $this->serviceWithVerifier($osm);

        $app = $svc->submit($this->user('curator-osm-unreachable@example.test'), 'BE', null, 'Some Rider', 'about me');

        self::assertSame('Some Rider', $app->getOsmUsername(), 'the handle is still recorded even though it is unverified');
        self::assertNull($app->getOsmVerifiedAt(), 'never checked, from the reviewer\'s point of view');
        self::assertNull($app->getOsmExists());
        self::assertNull($app->getOsmChangesetCount());
    }

    public function testAnOsmHandleThatExistsRecordsExistenceAndChangesetCount(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('ES', 'spain-test');
        $xml = '<?xml version="1.0"?><osm><changeset id="1"/><changeset id="2"/></osm>';
        $osm = new OsmUserVerifier(new MockHttpClient([new MockResponse($xml, ['http_code' => 200])]));
        $svc = $this->serviceWithVerifier($osm);

        $app = $svc->submit($this->user('curator-osm-found@example.test'), 'ES', null, 'Some Rider', 'about me');

        self::assertNotNull($app->getOsmVerifiedAt());
        self::assertTrue($app->getOsmExists());
        self::assertSame(2, $app->getOsmChangesetCount());
    }
}
