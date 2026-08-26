<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Service\ContributionStubInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class CatalogContributionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ContributionStubInterface $service;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(ContributionStubInterface::class);
    }

    private function user(): User
    {
        $user = (new User())->setEmail('contrib@test.test');
        // Mirror the seeding pattern used in existing Auth tests (password not
        // relevant here; check tests/Auth for the minimal required setters).
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function wallonia(): Region
    {
        $region = (new Region())->setSlug('wallonia')->setName('Wallonia')->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $this->em->persist($region);
        $this->em->flush();

        return $region;
    }

    public function testClimbSubmissionCreatesItemAndSubmissionAtomically(): void
    {
        $region = $this->wallonia();
        $receipt = $this->service->submit('climb', [
            'fName' => 'Côte du Test', 'fLen' => 3.1, 'fGain' => 200, 'fAvg' => '6.4',
            'fMax' => 12, 'fSurface' => 'Asphalt', 'fNote' => 'Steady, honest climb.',
            'lat' => '50.47', 'lng' => '5.86', 'place' => 'Testville',
        ], $this->user());

        self::assertTrue($receipt->persisted);
        self::assertNotNull($receipt->submissionId);

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        self::assertSame(SubmissionType::NewItem, $sub->getType());
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus());
        self::assertSame('N', $sub->getLetter());
        self::assertSame('BE', $sub->getCountryCode());
        self::assertSame($region->getId(), $sub->getRegionId());
        self::assertNotNull($sub->getItemId());

        $item = $this->em->find(Item::class, $sub->getItemId());
        self::assertNotNull($item);
        self::assertSame(ItemState::Submitted, $item->getState());
        self::assertSame('sub:'.$sub->getId(), $item->getSourceRef());
        self::assertSame('Côte du Test', $item->getName());
    }

    public function testClimbSubmissionStoresRouteGradSteep(): void
    {
        $receipt = $this->service->submit('climb', [
            'fName' => 'Test Col', 'lat' => 50.51, 'lng' => 5.24,
            'route' => '[[50.51,5.24],[50.52,5.25]]', // [lat,lng]
            'grad' => '[6,9,13]',
            'steep' => '{"at":[50.517,5.247],"pct":"26%","manual":false}',
        ], $this->user());

        self::assertTrue($receipt->persisted);
        self::assertNotNull($receipt->submissionId);

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        self::assertNotNull($sub->getItemId());

        $item = $this->em->find(Item::class, $sub->getItemId());
        self::assertNotNull($item);
        self::assertSame([[50.51, 5.24], [50.52, 5.25]], $item->getAttributes()['route']);
        self::assertSame([6, 9, 13], $item->getAttributes()['grad']);
        self::assertSame('26%', $item->getAttributes()['steep']['pct']);
        self::assertFalse($item->getAttributes()['steep']['manual']);
    }

    public function testVoteStaysUnpersisted(): void
    {
        $receipt = $this->service->submit('vote', ['choice' => 'x'], $this->user());
        self::assertFalse($receipt->persisted);
        self::assertNull($receipt->submissionId);
        self::assertSame(0, $this->em->getRepository(Submission::class)->count([]));
    }

    public function testPointOutsideAnyRegionGetsEmptyCountry(): void
    {
        $receipt = $this->service->submit('climb', [
            'fName' => 'Nowhere climb', 'lat' => '10.0', 'lng' => '10.0',
        ], $this->user());
        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertSame('', $sub->getCountryCode());
        self::assertNull($sub->getRegionId());
    }

    /** @param array<string,mixed> $attributes */
    private function item(string $letter, string $geom, array $attributes = [], string $name = 'Existing'): Item
    {
        $item = (new Item())
            ->setLetter($letter)
            ->setName($name)
            ->setGeom($geom)
            ->setCountryCode('BE')
            ->setRegionId(null)
            ->setState(ItemState::Verified)
            ->setSource(ItemSource::User)
            ->setSourceRef('test')
            ->setAttributes($attributes);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    public function testClimbRejectsNonNumericLatLng(): void
    {
        $this->expectException(ValidationFailedException::class);
        $this->service->submit('climb', [
            'fName' => 'Garbage coords', 'lat' => 'abc', 'lng' => '5.86',
        ], $this->user());
    }

    public function testClimbRejectsMissingLatLng(): void
    {
        $this->expectException(ValidationFailedException::class);
        $this->service->submit('climb', ['fName' => 'No coords'], $this->user());
    }

    public function testImproveOnLineStringItemLocatesAtFirstVertex(): void
    {
        $this->wallonia();
        // A road-surface style LineString item inside Wallonia. Pre-fix this
        // destructured coordinates as a flat pair → lat/lng = 1.0/1.0.
        $item = $this->item('N', '{"type":"LineString","coordinates":[[5.86,50.47],[5.87,50.48]]}');

        $receipt = $this->service->submit('improve', [
            '_item_id' => $item->getId(),
            'details' => ['surface' => 'Gravel'],
        ], $this->user());

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        // Located at the line's first vertex, resolved to Wallonia — not Null/Gulf.
        self::assertSame('BE', $sub->getCountryCode());
        $geom = json_decode((string) $sub->getGeom(), true);
        self::assertEqualsWithDelta(5.86, $geom['coordinates'][0], 1e-9);
        self::assertEqualsWithDelta(50.47, $geom['coordinates'][1], 1e-9);
    }

    /**
     * Resending a climb's geometry unchanged records no phantom change — and
     * with nothing else in the edit either, there is nothing to submit at all.
     *
     * The phantom-change guard (identical JSONB values must compare equal
     * after a real DB round-trip) is what this pins; the refusal is how a
     * changeless edit now ends, rather than as a queue row a curator reads and
     * an approval that applies nothing.
     */
    public function testImproveResendingIdenticalGeometryChangesNothingAndIsRefused(): void
    {
        $item = $this->item('N', '{"type":"Point","coordinates":[5.24,50.51]}', [
            'route' => [[50.51, 5.24], [50.52, 5.25]],
            'grad' => [6, 9, 13],
            'steep' => ['at' => [50.517, 5.247], 'pct' => '26%', 'manual' => false],
        ]);

        // Force a real DB round-trip (the production scenario): the item's
        // attributes are re-hydrated from JSONB, not read from the identity map.
        $id = $item->getId();
        $this->em->clear();

        try {
            $this->service->submit('improve', [
                '_item_id' => $id,
                'route' => '[[50.51,5.24],[50.52,5.25]]',
                'grad' => '[6,9,13]',
                'steep' => '{"at":[50.517,5.247],"pct":"26%","manual":false}',
            ], $this->user());
            self::fail('an edit that changes nothing must not be recorded');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('contribute.error.nothing_changed', (string) $e->getViolations());
        }
    }

    /**
     * The same geometry alongside a real edit: the change list carries the
     * edit and nothing else — the unchanged geometry stays out of it.
     */
    public function testImproveRecordsOnlyTheRealChangeBesideIdenticalGeometry(): void
    {
        $item = $this->item('N', '{"type":"Point","coordinates":[5.24,50.51]}', [
            'route' => [[50.51, 5.24], [50.52, 5.25]],
            'grad' => [6, 9, 13],
            'steep' => ['at' => [50.517, 5.247], 'pct' => '26%', 'manual' => false],
            'surface' => 'Asphalt',
        ]);
        $id = $item->getId();
        $this->em->clear();

        $receipt = $this->service->submit('improve', [
            '_item_id' => $id,
            'route' => '[[50.51,5.24],[50.52,5.25]]',
            'grad' => '[6,9,13]',
            'steep' => '{"at":[50.517,5.247],"pct":"26%","manual":false}',
            'details' => ['surface' => 'Gravel'],
        ], $this->user());

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        self::assertSame(['surface'], array_keys($sub->getChanges()), 'unchanged geometry must not record phantom changes');
    }

    public function testImproveWithNoChangeAtAllIsRefused(): void
    {
        $item = $this->item('N', '{"type":"Point","coordinates":[5.24,50.51]}', ['surface' => 'Asphalt']);

        try {
            $this->service->submit('improve', [
                '_item_id' => $item->getId(),
                'details' => ['surface' => 'Asphalt'],
            ], $this->user());
            self::fail('an edit that changes nothing must not be recorded');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('contribute.error.nothing_changed', (string) $e->getViolations());
        }
    }

    /**
     * A pasted image URL is not a contribution (owner 2026-08-16).
     *
     * It used to be: `photoUrl` counted as "something changed" and was then
     * discarded - no column, no diff, no moderation card, nothing on approve.
     * The rider was told their link had been received and it had not. The
     * field is gone from the form, and this is the third door behind it
     * (CatalogContributionService::REFUSED_KEYS): even handed straight to the
     * service, the key is dropped before anything reads the payload.
     *
     * The replacement is specced in docs/TODO.md as "Photo by Commons link":
     * allowlisted, licence-checked, and FETCHED into the same quarantine and
     * scan every upload goes through.
     */
    public function testAPastedPhotoUrlIsNotAChangeAndIsDroppedFromThePayload(): void
    {
        $item = $this->item('N', '{"type":"Point","coordinates":[5.24,50.51]}', ['surface' => 'Asphalt']);

        try {
            $this->service->submit('improve', [
                '_item_id' => $item->getId(),
                'details' => ['surface' => 'Asphalt'],
                // Hostile on purpose: whatever it is, it must not survive.
                'photoUrl' => 'javascript:alert(document.cookie)',
            ], $this->user());
            self::fail('a pasted photo url must not carry an otherwise-empty edit');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('contribute.error.nothing_changed', (string) $e->getViolations());
        }

        self::assertSame(0, $this->em->getRepository(Submission::class)->count([]),
            'nothing was stored, so nothing can carry the url');
    }

    public function testImproveClearingPrefilledAttributeRecordsRemoval(): void
    {
        $item = $this->item('N', '{"type":"Point","coordinates":[5.24,50.51]}', ['surface' => 'Asphalt']);

        $receipt = $this->service->submit('improve', [
            '_item_id' => $item->getId(),
            'details' => ['surface' => ''],
        ], $this->user());

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        $changes = $sub->getChanges();
        self::assertArrayHasKey('surface', $changes);
        self::assertSame('Asphalt', $changes['surface']['was']);
        self::assertNull($changes['surface']['now']);
    }

    public function testMalformedClimbGeometrySurfacesAsValidationError(): void
    {
        $this->expectException(ValidationFailedException::class);
        $this->service->submit('climb', [
            'fName' => 'Bad shape', 'lat' => '50.47', 'lng' => '5.86',
            'route' => '{"not":"a list of pairs"}',
        ], $this->user());
    }

    /**
     * A run-prefilled segment add carries the way refs it spans
     * (`_ways_spanned`, from the to-do click's run-chaining): valid refs are
     * stored deduped on attributes.waysSpanned, malformed entries are dropped
     * rather than rejected — they only retire red dashes, so a bad entry must
     * never sink the contribution itself.
     */
    public function testAddSegmentStoresValidatedWaysSpanned(): void
    {
        $this->wallonia();
        $receipt = $this->service->submit('add', [
            'type' => 'road-surface',
            'segment' => '{"a":[5.86,50.47],"b":[5.87,50.48]}',
            '_osm_ref' => 'way/111',
            '_ways_spanned' => 'way/111,way/222,way/222,node/333,rubbish,way/444',
            'details' => ['name' => 'Zuiderdijk run', 'surface' => 'Asphalt'],
        ], $this->user());

        $item = $this->em->find(Item::class, $this->em->find(Submission::class, $receipt->submissionId)->getItemId());
        self::assertSame(['way/111', 'way/222', 'way/444'], $item->getAttributes()['waysSpanned']);
    }

    public function testAddSegmentWithoutSpannedRefsStoresNoAttribute(): void
    {
        $this->wallonia();
        $receipt = $this->service->submit('add', [
            'type' => 'road-surface',
            'segment' => '{"a":[5.86,50.47],"b":[5.87,50.48]}',
            '_ways_spanned' => 'node/1,garbage',
            'details' => ['name' => 'Single way', 'surface' => 'Asphalt'],
        ], $this->user());

        $item = $this->em->find(Item::class, $this->em->find(Submission::class, $receipt->submissionId)->getItemId());
        self::assertArrayNotHasKey('waysSpanned', $item->getAttributes());
    }

    /**
     * Once the item is served, EVERY way ref it spans appears in the
     * catalog's `refs` list (CatalogProvider::curatedRefs) — not just the
     * clicked way's source_ref — so all covered red dashes retire.
     */
    public function testWaysSpannedRefsRetireInCatalogRefs(): void
    {
        $this->wallonia();
        $receipt = $this->service->submit('add', [
            'type' => 'road-surface',
            'segment' => '{"a":[5.86,50.47],"b":[5.87,50.48]}',
            '_osm_ref' => 'way/111',
            '_ways_spanned' => 'way/111,way/222,way/444',
            'details' => ['name' => 'Zuiderdijk run', 'surface' => 'Asphalt'],
        ], $this->user());

        $item = $this->em->find(Item::class, $this->em->find(Submission::class, $receipt->submissionId)->getItemId());
        $item->setState(ItemState::Verified);
        $this->em->flush();

        $refs = json_decode(static::getContainer()->get(\App\Catalog\CatalogProvider::class)->json(), true)['refs'];
        self::assertContains('way/111', $refs);
        self::assertContains('way/222', $refs, 'a spanned (non-clicked) way must retire too');
        self::assertContains('way/444', $refs);
    }

    /**
     * A redrawn stretch on an EXISTING surface item is a recorded change —
     * the segment hidden field is merged into the diff like the climb shape
     * is (before this, the wizard said the new line would be recorded while
     * the server silently dropped the field).
     */
    public function testImproveSegmentChangeIsRecorded(): void
    {
        $item = $this->item('A', '{"type":"LineString","coordinates":[[5.86,50.47],[5.87,50.48]]}', [
            'surface' => 'Asphalt',
            'segment' => ['a' => [5.86, 50.47], 'b' => [5.87, 50.48]],
        ]);
        $id = $item->getId();
        $this->em->clear();

        $receipt = $this->service->submit('improve', [
            '_item_id' => $id,
            'segment' => '{"a":[5.86,50.47],"b":[5.88,50.49]}',
            'details' => ['surface' => 'Asphalt'],
        ], $this->user());

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        $changes = $sub->getChanges();
        self::assertArrayHasKey('segment', $changes);
        self::assertSame([5.88, 50.49], $changes['segment']['now']['b']);
    }

    /**
     * Reposting the stored segment untouched records NO phantom geometry
     * change — with nothing else edited, the submission is refused outright.
     */
    public function testImproveResendingIdenticalSegmentChangesNothing(): void
    {
        $item = $this->item('A', '{"type":"LineString","coordinates":[[5.86,50.47],[5.87,50.48]]}', [
            'surface' => 'Asphalt',
            'segment' => ['a' => [5.86, 50.47], 'b' => [5.87, 50.48], 'line' => [[5.86, 50.47], [5.865, 50.475], [5.87, 50.48]]],
        ]);
        $id = $item->getId();
        $this->em->clear();

        try {
            $this->service->submit('improve', [
                '_item_id' => $id,
                'segment' => '{"a":[5.86,50.47],"b":[5.87,50.48],"line":[[5.86,50.47],[5.865,50.475],[5.87,50.48]]}',
                'details' => ['surface' => 'Asphalt'],
            ], $this->user());
            self::fail('an unchanged segment must not be recorded as a change');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('contribute.error.nothing_changed', (string) $e->getViolations());
        }
    }

    public function testSubmittedItemsAreNotServed(): void
    {
        // Spec §8: state=submitted never reaches /map/catalog.json.
        $this->wallonia();
        $this->service->submit('climb', [
            'fName' => 'Côte invisible', 'lat' => '50.47', 'lng' => '5.86',
        ], $this->user());

        $provider = static::getContainer()->get(\App\Catalog\CatalogProvider::class);
        self::assertStringNotContainsString('Côte invisible', $provider->json());
    }
}
