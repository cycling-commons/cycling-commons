<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ScenicPhotoRule;
use PHPUnit\Framework\TestCase;

/**
 * A scenic view's photo is shown only when the camera stood near the pin.
 *
 * The pin used throughout is a point in the Ardennes; one degree of latitude
 * is about 111 km, so 0.0009 degrees north is about 100 m and 0.0036 about
 * 400 m.
 */
final class ScenicPhotoRuleTest extends TestCase
{
    private const float LAT = 50.4;
    private const float LNG = 5.8;

    public function testACameraAHundredMetresAwayIsShown(): void
    {
        self::assertTrue(ScenicPhotoRule::allows(['sm' => 'x', 'cameraAt' => [self::LAT + 0.0009, self::LNG]], self::LAT, self::LNG));
    }

    public function testACameraFourHundredMetresAwayIsNot(): void
    {
        self::assertFalse(ScenicPhotoRule::allows(['sm' => 'x', 'cameraAt' => [self::LAT + 0.0036, self::LNG]], self::LAT, self::LNG));
    }

    public function testAPhotoWithNoKnownCameraIsNot(): void
    {
        self::assertFalse(ScenicPhotoRule::allows(['sm' => 'x'], self::LAT, self::LNG));
        self::assertFalse(ScenicPhotoRule::allows(['sm' => 'x', 'cameraAt' => 'somewhere'], self::LAT, self::LNG), 'a malformed camera is no camera');
    }

    public function testARiderPhotoTakenThirtyMetresFromThePinIsShown(): void
    {
        self::assertTrue(ScenicPhotoRule::allows(['sm' => 'x', 'distanceM' => 30], self::LAT, self::LNG));
    }

    public function testARiderPhotoWithNoGpsIsNot(): void
    {
        self::assertFalse(ScenicPhotoRule::allows(['sm' => 'x', 'distanceM' => null], self::LAT, self::LNG));
        self::assertFalse(ScenicPhotoRule::allows(['sm' => 'x', 'distanceM' => 251], self::LAT, self::LNG));
    }

    public function testAnItemWithNoPinShowsNoCommonsPhoto(): void
    {
        self::assertFalse(ScenicPhotoRule::allows(['sm' => 'x', 'cameraAt' => [self::LAT, self::LNG]], null, null));
    }

    public function testOnlyScenicViewsAreFiltered(): void
    {
        self::assertTrue(ScenicPhotoRule::appliesTo('P'));
        self::assertFalse(ScenicPhotoRule::appliesTo('Q'));
        self::assertFalse(ScenicPhotoRule::appliesTo('B'));
    }

    public function testFilteringDropsFarPhotosAndEmptyKeys(): void
    {
        $near = ['sm' => 'near', 'cameraAt' => [self::LAT + 0.0009, self::LNG]];
        $far = ['sm' => 'far', 'cameraAt' => [self::LAT + 0.0036, self::LNG]];

        $kept = ScenicPhotoRule::filterAttributes(['type' => 'viewpoint', 'photo' => $far, 'photos' => [$far, $near]], self::LAT, self::LNG);
        self::assertArrayNotHasKey('photo', $kept);
        self::assertSame([$near], $kept['photos'], 'the gallery keeps its shown entries as a list');
        self::assertSame('viewpoint', $kept['type']);

        $none = ScenicPhotoRule::filterAttributes(['photo' => $far, 'photos' => [$far]], self::LAT, self::LNG);
        self::assertArrayNotHasKey('photo', $none);
        self::assertArrayNotHasKey('photos', $none);
    }

    public function testDistanceIsHaversine(): void
    {
        // 199 m south and 166 m west at this latitude: about 259 m apart.
        $m = ScenicPhotoRule::metres(50.84676, 4.35234, 50.84497, 4.34998);
        self::assertEqualsWithDelta(259.0, $m, 3.0);
    }
}
