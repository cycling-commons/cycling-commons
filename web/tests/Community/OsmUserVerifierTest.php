<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\OsmUserVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * 2026-07-29-country-requests-and-curator-signup-design.md §6.
 */
final class OsmUserVerifierTest extends TestCase
{
    public function testRejectsMalformedUsernamesBeforeAnyRequest(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('a malformed username must never reach the network');
        });
        $v = new OsmUserVerifier($client);

        foreach (['', str_repeat('a', 300), "bad\nname", 'name?with=query', 'has/slash'] as $bad) {
            self::assertFalse($v->isWellFormed($bad), sprintf('"%s" is not a usable OSM display name', $bad));
        }
        self::assertTrue($v->isWellFormed('Some Rider_42'));
    }

    public function testCountsChangesetsForAnExistingUser(): void
    {
        $xml = '<?xml version="1.0"?><osm><changeset id="1"/><changeset id="2"/><changeset id="3"/></osm>';
        $v = new OsmUserVerifier(new MockHttpClient([new MockResponse($xml, ['http_code' => 200])]));

        $result = $v->verify('Some Rider');

        self::assertTrue($result->reachable);
        self::assertTrue($result->exists);
        self::assertSame(3, $result->changesets);
    }

    public function testAnUnknownUserIsReachableButAbsent(): void
    {
        $v = new OsmUserVerifier(new MockHttpClient([new MockResponse('', ['http_code' => 404])]));

        $result = $v->verify('nobody-here');

        self::assertTrue($result->reachable);
        self::assertFalse($result->exists);
        self::assertNull($result->changesets);
    }

    public function testATransportFailureIsUnverifiedNotAbsent(): void
    {
        // The distinction matters: "OSM was down" must never be shown to the
        // reviewer as "this person does not exist".
        $v = new OsmUserVerifier(new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'timeout']);
        }));

        $result = $v->verify('Some Rider');

        self::assertFalse($result->reachable, 'unreachable, so the reviewer sees "unverified"');
        self::assertFalse($result->exists);
        self::assertNull($result->changesets);
    }

    public function testResolvesPaginationCapViaUserDetailsLookup(): void
    {
        // Build 100 changesets with uid attribute on the first one
        $changesets = str_repeat('<changeset id="1"/>', 99).'<changeset id="100" uid="12345"/>';
        $changesetsXml = "<?xml version=\"1.0\"?><osm>{$changesets}</osm>";
        $userDetailsXml = '<?xml version="1.0"?><osm><user id="12345"><changesets count="1234"/></user></osm>';

        $v = new OsmUserVerifier(new MockHttpClient([
            new MockResponse($changesetsXml, ['http_code' => 200]),
            new MockResponse($userDetailsXml, ['http_code' => 200]),
        ]));

        $result = $v->verify('prolific_mapper');

        self::assertTrue($result->reachable);
        self::assertTrue($result->exists);
        self::assertSame(1234, $result->changesets);
    }

    public function testFallsBackToPageCountWhenUserDetailsRequestFails(): void
    {
        // Build 100 changesets with uid attribute
        $changesets = str_repeat('<changeset id="1"/>', 99).'<changeset id="100" uid="12345"/>';
        $changesetsXml = "<?xml version=\"1.0\"?><osm>{$changesets}</osm>";

        $v = new OsmUserVerifier(new MockHttpClient([
            new MockResponse($changesetsXml, ['http_code' => 200]),
            new MockResponse('', ['http_code' => 500]), // Follow-up fails
        ]));

        $result = $v->verify('another_mapper');

        // reachable/exists remain true from the first successful request
        self::assertTrue($result->reachable);
        self::assertTrue($result->exists);
        // Falls back to page count when follow-up fails
        self::assertSame(100, $result->changesets);
    }
}
