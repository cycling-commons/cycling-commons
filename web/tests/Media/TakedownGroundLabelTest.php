<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaTakedownCategory;
use App\Support\ReportGround;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin restore page (and the Takedowns desk card) name a photo report's
 * ground with the same label the Reports desk uses (ReportGround), and still
 * read the five categories the old photo form stored.
 *
 * @see docs/specs/photo-uploads.md §6c
 */
final class TakedownGroundLabelTest extends WebTestCase
{
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /** @param list<string> $roles */
    private function user(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Ground Label '.$email);
        $user->setRoles($roles);
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function approved(EntityManagerInterface $em, User $owner): MediaUpload
    {
        $ownerId = (int) $owner->getId();
        $consent = new ConsentRecord(Uuid::v4(), $ownerId, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);

        $upload = new MediaUpload(Uuid::v4(), $ownerId, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $upload->claim(1);
        $upload->approve(null);
        $em->flush();

        return $upload;
    }

    /**
     * Every value the category column can hold resolves to a key that all five
     * catalogues carry: the nine grounds through ReportGround, the three values
     * only the old photo form stored through their old keys.
     */
    public function testEveryStoredCategoryHasALabelInEveryLocale(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        $values = [...array_map(static fn (ReportGround $g): string => $g->value, ReportGround::cases()), ...MediaTakedownCategory::all()];
        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            foreach ($values as $value) {
                $key = MediaTakedownCategory::label($value);
                self::assertTrue($catalogue->has($key), \sprintf('%s: %s has no label (%s)', $locale, $value, $key));
            }
        }

        // A ground reads the way the Reports desk reads it.
        self::assertSame(ReportGround::PersonalData->label(), MediaTakedownCategory::label('personal_data'));
        self::assertSame(ReportGround::IntimateOrChild->label(), MediaTakedownCategory::label('intimate_or_child'));
        self::assertSame('media.report.category.identifiable_self', MediaTakedownCategory::label('identifiable_self'));
    }

    public function testTheRestorePageNamesAReportGround(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->approved($em, $this->user($em, 'gl-held@example.test'));
        // A withheld row on a ground the old photo form never had.
        $upload->reportThirdParty('copyright', 'I took this picture in 2019.', null, 'feedfacefeedface', true);
        $em->flush();

        $client->loginUser($this->user($em, 'gl-admin@example.test', ['ROLE_ADMIN']));
        $page = $client->request('GET', '/admin/withheld-photos');
        self::assertResponseIsSuccessful();

        $text = $page->text();
        self::assertStringContainsString('I took this picture in 2019.', $text);
        self::assertStringContainsString('It is my work and I did not agree to this', $text);
        self::assertStringNotContainsString('media.report.category', $text);
    }
}
