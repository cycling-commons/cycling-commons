<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\GpsDistance;
use App\Media\PhotoDecision;
use App\Media\PhotoFacts;
use App\Media\PhotoOrigin;
use App\Media\PhotoPlace;
use App\Media\PhotoReason;
use App\Media\PhotoValidator;
use PHPUnit\Framework\TestCase;

/**
 * The one decision about linking a photo to a place, whichever way the photo
 * arrives and whenever it is asked: at link time and at show time.
 *
 * The pin used throughout is a point in the Ardennes; one degree of latitude
 * is about 111 km, so 0.0009 degrees north is about 100 m and 0.0036 about
 * 400 m.
 */
final class PhotoValidatorTest extends TestCase
{
    private const float LAT = 50.4;
    private const float LNG = 5.8;

    private static function scenic(?float $lat = self::LAT, ?float $lng = self::LNG): PhotoPlace
    {
        return new PhotoPlace('P', $lat, $lng);
    }

    private static function castle(): PhotoPlace
    {
        return new PhotoPlace('Q', self::LAT, self::LNG);
    }

    /** @param array<string, mixed> $entry */
    private static function decide(array $entry, PhotoPlace $place): PhotoDecision
    {
        return PhotoValidator::verdict(PhotoFacts::fromEntry($entry), $place)->decision;
    }

    /** @param array<string, mixed> $overrides */
    private static function commons(array $overrides = []): array
    {
        return $overrides + [
            'sm' => 'x',
            'credit' => 'Jean-Pol GRANDMONT',
            'license' => 'CC BY-SA 3.0',
            'source' => 'https://commons.wikimedia.org/wiki/File:Some_view.jpg',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private static function rider(array $overrides = []): array
    {
        return $overrides + ['id' => 'u-1', 'sm' => 'x', 'credit' => '', 'license' => 'CC BY-SA 4.0'];
    }

    public function testAFreeAttributedCommonsPhotoIsShownOnAnyOtherLetter(): void
    {
        $verdict = PhotoValidator::verdict(PhotoFacts::fromEntry(self::commons()), self::castle());

        self::assertSame(PhotoDecision::Show, $verdict->decision);
        self::assertNull($verdict->reason);
        self::assertTrue($verdict->shows());
    }

    public function testALicenceOffTheListIsRefused(): void
    {
        foreach (['CC BY-NC-SA 4.0', 'Attribution', 'Fair use', ''] as $licence) {
            $verdict = PhotoValidator::verdict(PhotoFacts::fromEntry(self::commons(['license' => $licence])), self::castle());
            self::assertSame(PhotoDecision::Refuse, $verdict->decision, $licence);
            self::assertSame(PhotoReason::Licence, $verdict->reason, $licence);
        }
        $none = self::commons();
        unset($none['license']);
        self::assertSame(PhotoReason::Licence, PhotoValidator::verdict(PhotoFacts::fromEntry($none), self::castle())->reason);
    }

    public function testACommonsPhotoWithNoAuthorIsRefused(): void
    {
        foreach (['', '   ', 'Wikimedia Commons', 'No machine-readable author provided. Somebody assumed (based on copyright claims).'] as $credit) {
            $verdict = PhotoValidator::verdict(PhotoFacts::fromEntry(self::commons(['credit' => $credit])), self::castle());
            self::assertSame(PhotoReason::NoAuthor, $verdict->reason, $credit);
        }
    }

    /** The rider licenses their own photo to us and may choose not to be named. */
    public function testARiderPhotoNeedsNoPublicName(): void
    {
        self::assertSame(PhotoDecision::Show, self::decide(self::rider(), self::castle()));
    }

    public function testCommonsNonFreeAndRestrictionFlagsRefuse(): void
    {
        $nonFree = PhotoFacts::commons('CC BY-SA 3.0', 'Jane', nonFree: true, restricted: false, cameraAt: null);
        $restricted = PhotoFacts::commons('CC BY-SA 3.0', 'Jane', nonFree: false, restricted: true, cameraAt: null);

        self::assertSame(PhotoReason::NonFree, PhotoValidator::verdict($nonFree, self::castle())->reason);
        self::assertSame(PhotoReason::Restricted, PhotoValidator::verdict($restricted, self::castle())->reason);
    }

    public function testAPhotoUnderLegalHoldIsRefused(): void
    {
        $held = new PhotoFacts(PhotoOrigin::Rider, 'CC BY-SA 4.0', '', legalHold: true, uploadId: 'u-1', distanceM: 10);

        $verdict = PhotoValidator::verdict($held, self::castle());
        self::assertSame(PhotoDecision::Refuse, $verdict->decision);
        self::assertSame(PhotoReason::LegalHold, $verdict->reason);
    }

    public function testACameraAHundredMetresAwayIsShown(): void
    {
        self::assertSame(PhotoDecision::Show, self::decide(self::commons(['cameraAt' => [self::LAT + 0.0009, self::LNG]]), self::scenic()));
    }

    public function testACameraFourHundredMetresAwayIsRefusedForThatPlaceOnly(): void
    {
        $verdict = PhotoValidator::verdict(PhotoFacts::fromEntry(self::commons(['cameraAt' => [self::LAT + 0.0036, self::LNG]])), self::scenic());

        self::assertSame(PhotoDecision::Refuse, $verdict->decision);
        self::assertSame(PhotoReason::CameraFar, $verdict->reason);
        self::assertTrue($verdict->reason->concernsPlace(), 'another place may still show the same file');
        self::assertFalse(PhotoReason::Licence->concernsPlace());
    }

    public function testAPhotoWithNoKnownCameraIsRefused(): void
    {
        self::assertSame(PhotoReason::CameraUnknown, PhotoValidator::verdict(PhotoFacts::fromEntry(self::commons()), self::scenic())->reason);
        self::assertSame(PhotoReason::CameraUnknown, PhotoValidator::verdict(PhotoFacts::fromEntry(self::commons(['cameraAt' => 'somewhere'])), self::scenic())->reason, 'a malformed camera is no camera');
    }

    public function testAnItemWithNoPinShowsNoCommonsPhoto(): void
    {
        self::assertSame(PhotoDecision::Refuse, self::decide(self::commons(['cameraAt' => [self::LAT, self::LNG]]), self::scenic(null, null)));
    }

    public function testARiderPhotoTakenThirtyMetresFromThePinIsShown(): void
    {
        self::assertSame(PhotoDecision::Show, self::decide(self::rider(['distanceM' => 30]), self::scenic(null, null)));
    }

    /**
     * Linked but not shown: the entry stays on the item so a curator can say
     * "Taken here" (photo-uploads.md §5g).
     */
    public function testARiderPhotoWithNoUsableDistanceIsHidden(): void
    {
        $unknown = PhotoValidator::verdict(PhotoFacts::fromEntry(self::rider(['distanceM' => null])), self::scenic());
        $far = PhotoValidator::verdict(PhotoFacts::fromEntry(self::rider(['distanceM' => 251])), self::scenic());

        self::assertSame(PhotoDecision::Hide, $unknown->decision);
        self::assertSame(PhotoReason::CameraUnknown, $unknown->reason);
        self::assertTrue($unknown->links());
        self::assertFalse($unknown->shows());
        self::assertSame(PhotoDecision::Hide, $far->decision);
        self::assertSame(PhotoReason::CameraFar, $far->reason);
    }

    public function testARiderPhotoACuratorConfirmedIsShown(): void
    {
        self::assertSame(PhotoDecision::Show, self::decide(self::rider(['distanceM' => null, 'locationConfirmed' => true]), self::scenic()));
        self::assertSame(PhotoDecision::Show, self::decide(self::rider(['distanceM' => 900, 'locationConfirmed' => true]), self::scenic(null, null)), 'the confirmation decides over a measured distance');
        self::assertSame(PhotoDecision::Hide, self::decide(self::rider(['distanceM' => null, 'locationConfirmed' => 'yes']), self::scenic()), 'only true confirms');
    }

    /**
     * The owner's rule for a pin that moved after a rider photo was measured
     * (2026-09-15): the camera stood at most (its distance) + (how far the pin
     * now is from the pin it was measured to) from the new pin, and the photo
     * counts only while that sum is within 250 m.
     */
    public function testAPhotoMeasuredToAnEarlierPinCountsTheMoveAsWorstCase(): void
    {
        $measuredHere = [self::LAT, self::LNG];
        // 0.0018 degrees north is about 200 m; 0.00018 about 20 m.
        $movedFar = self::scenic(self::LAT + 0.0018);
        $movedNear = self::scenic(self::LAT + 0.00018);

        $far = PhotoValidator::verdict(PhotoFacts::fromEntry(self::rider(['distanceM' => 100, 'distancePin' => $measuredHere])), $movedFar);
        self::assertSame(PhotoDecision::Hide, $far->decision, '100 m + 200 m is over 250 m');
        self::assertSame(PhotoReason::PinMoved, $far->reason, 'the photo was within reach until the pin moved');
        self::assertSame(301, PhotoValidator::reachM(PhotoFacts::fromEntry(self::rider(['distanceM' => 100, 'distancePin' => $measuredHere])), $movedFar));

        self::assertSame(PhotoDecision::Show, self::decide(self::rider(['distanceM' => 100, 'distancePin' => $measuredHere]), $movedNear), '100 m + 20 m is within reach');
        self::assertSame(121, PhotoValidator::reachM(PhotoFacts::fromEntry(self::rider(['distanceM' => 100, 'distancePin' => $measuredHere])), $movedNear));

        $alreadyFar = PhotoValidator::verdict(PhotoFacts::fromEntry(self::rider(['distanceM' => 300, 'distancePin' => $measuredHere])), $movedNear);
        self::assertSame(PhotoReason::CameraFar, $alreadyFar->reason, 'a photo that was too far before the move is too far, not moved');

        self::assertSame(PhotoDecision::Show, self::decide(self::rider(['distanceM' => 100, 'distancePin' => $measuredHere]), new PhotoPlace('Q', self::LAT + 0.0018, self::LNG)), 'another letter shows it anyway');
    }

    public function testAPinBackWhereItWasMeasuredCountsNoMove(): void
    {
        self::assertSame(PhotoDecision::Show, self::decide(self::rider(['distanceM' => 250, 'distancePin' => [self::LAT, self::LNG]]), self::scenic()));
        // 0.000005 degrees is about half a metre: coordinate rounding, not a move.
        self::assertSame(PhotoDecision::Show, self::decide(self::rider(['distanceM' => 250, 'distancePin' => [self::LAT, self::LNG]]), self::scenic(self::LAT + 0.000005)));
        self::assertSame(250, PhotoValidator::reachM(PhotoFacts::fromEntry(self::rider(['distanceM' => 250, 'distancePin' => [self::LAT, self::LNG]])), self::scenic(self::LAT + 0.000005)));
    }

    public function testAMeasuredPinAndAnUnknownPinProveNothing(): void
    {
        $verdict = PhotoValidator::verdict(PhotoFacts::fromEntry(self::rider(['distanceM' => 30, 'distancePin' => [self::LAT, self::LNG]])), self::scenic(null, null));

        self::assertSame(PhotoDecision::Hide, $verdict->decision);
        self::assertSame(PhotoReason::CameraUnknown, $verdict->reason);
        self::assertNull(PhotoValidator::reachM(PhotoFacts::fromEntry(self::rider(['distanceM' => 30, 'distancePin' => [self::LAT, self::LNG]])), self::scenic(null, null)));
    }

    /**
     * "Taken here" puts the camera at the pin as it stood: 0 m from it. A
     * later move is then measured like a GPS distance, in a straight line from
     * that pin to where the pin is now.
     */
    public function testAConfirmationIsZeroMetresFromThePinItWasMadeAt(): void
    {
        $confirmedHere = ['locationConfirmed' => true, 'confirmedPin' => [self::LAT, self::LNG]];
        $photo = PhotoFacts::fromEntry(self::rider(['distanceM' => null] + $confirmedHere));

        self::assertSame(PhotoDecision::Show, self::decide(self::rider(['distanceM' => null] + $confirmedHere), self::scenic()));
        self::assertSame(0, PhotoValidator::reachM($photo, self::scenic()));

        // About 20 m north: 0 + 20 m is within reach.
        self::assertSame(PhotoDecision::Show, PhotoValidator::verdict($photo, self::scenic(self::LAT + 0.00018))->decision, 'a small move keeps it');
        self::assertSame(21, PhotoValidator::reachM($photo, self::scenic(self::LAT + 0.00018)));

        // About 270 m north: beyond reach, and it says the pin moved.
        $far = PhotoValidator::verdict($photo, self::scenic(self::LAT + 0.00243));
        self::assertSame(PhotoDecision::Hide, $far->decision);
        self::assertSame(PhotoReason::PinMoved, $far->reason);

        // Eight moves of 30 m that end 10 m from the confirmed pin count as 10 m.
        self::assertSame(PhotoDecision::Show, PhotoValidator::verdict($photo, self::scenic(self::LAT + 0.00009))->decision, 'only where the pin is now counts');

        // A measured distance and a confirmation: the nearer answer counts.
        $both = PhotoFacts::fromEntry(self::rider(['distanceM' => 240, 'distancePin' => [self::LAT, self::LNG]] + $confirmedHere));
        self::assertSame(21, PhotoValidator::reachM($both, self::scenic(self::LAT + 0.00018)));

        self::assertSame(PhotoDecision::Hide, self::decide(self::rider(['distanceM' => null] + $confirmedHere), self::scenic(null, null)), 'an unknown pin cannot be measured');
    }

    /** What the person moving a pin is told before saving. */
    public function testMovingAPinCountsTheRiderPhotosItWouldHide(): void
    {
        $here = [self::LAT, self::LNG];
        $attributes = [
            'photo' => self::rider(['id' => 'single', 'distanceM' => 40, 'distancePin' => $here]),
            'photos' => [
                self::rider(['id' => 'near', 'distanceM' => 40, 'distancePin' => $here]),
                self::rider(['id' => 'edge', 'distanceM' => 240, 'distancePin' => $here]),
                self::rider(['id' => 'confirmed', 'distanceM' => null, 'locationConfirmed' => true, 'confirmedPin' => $here]),
                self::rider(['id' => 'already-hidden', 'distanceM' => null]),
                self::commons(['cameraAt' => $here]),
                'not an entry',
            ],
        ];
        $from = self::scenic();

        // About 20 m north: only the edge photo (240 + 20) goes; the confirmation (0 + 20) stays.
        self::assertSame(1, PhotoValidator::hiddenByMove($attributes, $from, self::scenic(self::LAT + 0.00018)));
        // About 400 m north: every rider photo that showed goes; the Commons photo is not a rider's.
        self::assertSame(4, PhotoValidator::hiddenByMove($attributes, $from, self::scenic(self::LAT + 0.0036)));
        self::assertSame(0, PhotoValidator::hiddenByMove($attributes, $from, self::scenic()), 'no move hides nothing');
        self::assertSame(0, PhotoValidator::hiddenByMove($attributes, self::castle(), new PhotoPlace('Q', self::LAT + 0.0036, self::LNG)), 'another letter hides nothing');

        // Why: the farthest a hidden photo may have been taken from the new spot.
        self::assertSame(['hidden' => 1, 'farthestM' => 261], PhotoValidator::moveEffect($attributes, $from, self::scenic(self::LAT + 0.00018)));
        $far = PhotoValidator::moveEffect($attributes, $from, self::scenic(self::LAT + 0.0036));
        self::assertSame(4, $far['hidden']);
        self::assertGreaterThan(600, $far['farthestM']);
        self::assertSame(['hidden' => 0, 'farthestM' => null], PhotoValidator::moveEffect($attributes, $from, self::scenic()));
    }

    public function testADistanceOrConfirmationOnACommonsPhotoCountsForNothing(): void
    {
        self::assertSame(PhotoDecision::Refuse, self::decide(self::commons(['locationConfirmed' => true]), self::scenic()));
        self::assertSame(PhotoDecision::Refuse, self::decide(self::commons(['distanceM' => 10]), self::scenic()));
    }

    public function testAnImportedPhotoFollowsTheCommonsRules(): void
    {
        $entry = ['sm' => '/media/x.jpg', 'credit' => 'Jane', 'license' => 'CC0'];
        self::assertSame(PhotoOrigin::Import, PhotoFacts::fromEntry($entry)->origin);
        self::assertSame(PhotoDecision::Show, self::decide($entry, self::castle()));
        self::assertSame(PhotoDecision::Refuse, self::decide($entry, self::scenic()));
    }

    public function testSomethingThatIsNotAnEntryIsRefused(): void
    {
        self::assertSame(PhotoDecision::Refuse, PhotoValidator::verdict(PhotoFacts::fromEntry('https://example.org/x.jpg'), self::castle())->decision);
    }

    public function testOnlyScenicViewsMeasureTheCamera(): void
    {
        self::assertTrue(self::scenic()->isScenicView());
        self::assertFalse(self::castle()->isScenicView());
        self::assertFalse(PhotoPlace::unplaced()->isScenicView());
    }

    public function testShownKeepsOnlyShownEntriesAndDropsEmptyKeys(): void
    {
        $near = self::commons(['sm' => 'near', 'cameraAt' => [self::LAT + 0.0009, self::LNG]]);
        $far = self::commons(['sm' => 'far', 'cameraAt' => [self::LAT + 0.0036, self::LNG]]);
        $hidden = self::rider(['distanceM' => null]);

        $kept = PhotoValidator::sift(['type' => 'viewpoint', 'photo' => $far, 'photos' => [$far, $hidden, $near]], self::scenic());
        self::assertArrayNotHasKey('photo', $kept['attributes']);
        self::assertSame([$near], $kept['attributes']['photos'], 'the gallery keeps its shown entries as a list');
        self::assertSame('viewpoint', $kept['attributes']['type']);
        self::assertCount(3, $kept['dropped']);
        self::assertSame(PhotoReason::CameraFar, $kept['dropped'][0]['verdict']->reason);

        $none = PhotoValidator::sift(['photo' => $far, 'photos' => [$far]], self::scenic());
        self::assertArrayNotHasKey('photo', $none['attributes']);
        self::assertArrayNotHasKey('photos', $none['attributes']);
    }

    public function testLinkingKeepsHiddenRiderEntries(): void
    {
        $hidden = self::rider(['distanceM' => null]);
        $far = self::commons(['cameraAt' => [self::LAT + 0.0036, self::LNG]]);

        $linked = PhotoValidator::sift(['photos' => [$hidden, $far]], self::scenic(), keepHidden: true);
        self::assertSame([$hidden], $linked['attributes']['photos']);
        self::assertCount(1, $linked['dropped']);
    }

    public function testDistanceIsHaversine(): void
    {
        // 199 m south and 166 m west at this latitude: about 259 m apart.
        self::assertEqualsWithDelta(259.0, GpsDistance::metres(50.84676, 4.35234, 50.84497, 4.34998), 3.0);
        self::assertSame(259, GpsDistance::between(50.84676, 4.35234, 50.84497, 4.34998));
        self::assertNull(GpsDistance::between(null, 4.35234, 50.84497, 4.34998), 'a missing end is no distance, not zero');
    }
}
