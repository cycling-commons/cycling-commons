<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CuratorApplicationException;
use App\Community\CuratorApplicationService;
use App\Community\CuratorApplicationStatus;
use App\Community\OsmUserVerifier;
use App\Community\PublicNoteFilter;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Service\AdminActionLogger;
use App\Service\UserAdminService;
use App\World\CuratorScopes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * moderation-and-contribution.md.
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
        return $this->seedRegionAtLevel($cc, $slug, 2);
    }

    private function seedRegionAtLevel(string $cc, string $slug, int $adminLevel): int
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 1000, ?, ?, ?, 'test', NOW(), NOW())",
            [$slug, strtoupper($slug), $cc, $cc, $adminLevel],
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
            $c->get(CuratorScopes::class),
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

    /**
     * map-and-search.md §4.5: the L2 "infrastructure"
     * country outline must never be assignable as a curator's requested
     * scope, exactly as a region from the wrong country is silently ignored
     * rather than assigned. The country carries two rows here — an
     * operational L4 (deepest level, so operational by the rule) and the L2
     * outline — so this exercises the operational filter, not just
     * "the only row happens to be L2" (which stays operational, per LU).
     */
    public function testRequestingTheInfrastructureL2RegionIsIgnoredLikeAWrongCountryRegion(): void
    {
        self::bootKernel();
        $this->seedRegionAtLevel('FI', 'finland-region-test', 4);
        $l2 = $this->seedRegionAtLevel('FI', 'finland-test', 2);
        $svc = self::getContainer()->get(CuratorApplicationService::class);

        $app = $svc->submit($this->user('curator-l2-request@example.test'), 'FI', $l2, null, 'about me');

        self::assertNull($app->getRequestedRegionId(), 'the infrastructure L2 row must not be assignable as a requested scope');
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

    /**
     * osm_username is varchar(64) and the template's maxlength="64" is
     * client-side only, so a crafted/hand-edited POST past that limit must
     * be rejected here, not reach flush() and 500 on a column-width violation.
     */
    public function testAnOsmHandleLongerThan64CharsIsRejectedCleanly(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('LT', 'longhandle-test');
        $svc = self::getContainer()->get(CuratorApplicationService::class);
        $u = $this->user('curator-longhandle@example.test');
        $handle = str_repeat('a', 65);

        $threw = false;
        try {
            $svc->submit($u, 'LT', null, $handle, 'about me');
        } catch (CuratorApplicationException $e) {
            $threw = true;
            self::assertSame('osm_handle_too_long', $e->reason);
        }
        self::assertTrue($threw, 'a 65-char OSM handle must be rejected server-side, not reach flush() and 500');

        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT * FROM curator_application WHERE user_id = ?', [$u->getId()]);
        self::assertFalse($row, 'no application row is created for a rejected submission');
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

    public function testSocialUrlIsStoredAndSchemeLessInputIsNormalized(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('CH', 'suisse-test');
        $svc = self::getContainer()->get(CuratorApplicationService::class);

        $app = $svc->submit($this->user('curator-soc1@example.test'), 'CH', null, null, 'about me', 'https://instagram.com/some.rider');
        self::assertSame('https://instagram.com/some.rider', $app->getSocialUrl());

        $app = $svc->submit($this->user('curator-soc2@example.test'), 'CH', null, null, 'about me', 'instagram.com/some.rider');
        self::assertSame('https://instagram.com/some.rider', $app->getSocialUrl(), 'people paste links without a scheme');

        $app = $svc->submit($this->user('curator-soc3@example.test'), 'CH', null, null, 'about me', '   ');
        self::assertNull($app->getSocialUrl(), 'the field is optional');

        $app = $svc->submit($this->user('curator-soc4@example.test'), 'CH', null, null, 'about me');
        self::assertNull($app->getSocialUrl(), 'omitting the argument stays valid');
    }

    public function testSocialUrlRejectsNonWebSchemesAndGarbage(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('AT', 'austria-test');
        $svc = self::getContainer()->get(CuratorApplicationService::class);

        foreach (['javascript:alert(1)', 'not a url at all', 'https://'.str_repeat('a', 250).'.example'] as $bad) {
            try {
                $svc->submit($this->user('curator-soc-bad-'.md5($bad).'@example.test'), 'AT', null, null, 'about me', $bad);
                self::fail(sprintf('"%s" was accepted', $bad));
            } catch (CuratorApplicationException $e) {
                self::assertSame('social_url_invalid', $e->reason);
            }
        }
    }
}
