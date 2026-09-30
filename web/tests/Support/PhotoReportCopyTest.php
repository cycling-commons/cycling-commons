<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\ReportGround;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the public copy says a photo report does.
 *
 * Only a report on the intimate-imagery-or-a-child ground hides a photo before
 * a curator has looked (ReportGround::autoWithholds()); every other ground
 * leaves it up. A removal can follow any ground, so the contributor's message
 * names none.
 *
 * @see docs/specs/content-reports.md §2
 */
final class PhotoReportCopyTest extends KernelTestCase
{
    private const string WHERE_PHOTO_EN = 'Under the photo: "This photo shows me, report it". The photo stays up while a curator looks. The one exception is a report of intimate imagery or of a child: that hides the photo at once, before a curator has looked.';

    private const string REMOVED_EN = 'One of your photos has been removed after a report about it. A curator read the report and agreed with it. This is about that one image, not your contribution, and thank you for it. We cannot tell you who reported it, in either direction: they were not told who uploaded it. If you think the removal is wrong, reply to this message and a curator will read it.';

    public function testTheGuideSaysOnlyTheChildGroundHidesAPhotoAtOnce(): void
    {
        self::assertSame([ReportGround::IntimateOrChild], array_values(array_filter(ReportGround::cases(), static fn (ReportGround $g): bool => $g->autoWithholds())));

        self::assertSame(self::WHERE_PHOTO_EN, $this->trans('support.guide.where_photo', 'en'));
    }

    public function testTheRemovalMessageNamesNoGround(): void
    {
        self::assertSame(self::REMOVED_EN, $this->trans('messages.body.media_removed_on_report', 'en'));
    }

    public function testNeitherLineCarriesAnEmDashInAnyLocale(): void
    {
        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            foreach (['support.guide.where_photo', 'messages.body.media_removed_on_report'] as $key) {
                $text = $this->trans($key, $locale);
                self::assertNotSame($key, $text, $locale.': '.$key.' is missing');
                self::assertStringNotContainsString('—', $text, $locale.': '.$key);
            }
        }
    }

    private function trans(string $key, string $locale): string
    {
        self::bootKernel();

        return static::getContainer()->get(TranslatorInterface::class)->trans($key, [], 'messages', $locale);
    }
}
