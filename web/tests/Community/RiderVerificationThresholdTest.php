<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\ConfirmationSource;
use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Community\ItemConfirmationService;
use App\Entity\User;
use App\Settings\SettingsRegistry;
use App\Tests\Settings\FakeSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Riders verify a place by repeating each other.
 *
 * Until 2026-09-09 a single confirmation removed the "?" from a pin while the
 * record stayed Unverified, so the map and the database disagreed about what
 * verified meant and a rider clearing a badge changed nothing a curator could
 * see. Owner: "if the ? mark is gone, it has verified state", one rule for
 * OpenStreetMap rows, register rows and our own alike.
 */
final class RiderVerificationThresholdTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function rider(string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName('U');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    /** Letter B offers potable/not-potable; letter C (toilets) offers `exists`. */
    private function item(string $letter = 'B', ItemSource $source = ItemSource::Osm): Item
    {
        $item = (new Item())->setLetter($letter)->setName('Fontein')
            ->setGeom('{"type":"Point","coordinates":[5.12,52.09]}')->setCountryCode('NL')
            ->setState(ItemState::Unverified)->setSource($source)
            ->setSourceRef('node/'.bin2hex(random_bytes(4)))->setAttributes([]);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    /** The service with the threshold dialled to a known number. */
    private function service(int $threshold): ItemConfirmationService
    {
        return new ItemConfirmationService(
            $this->em(),
            static::getContainer()->get(Connection::class),
            new FakeSettings([SettingsRegistry::MAP_ITEM_VERIFY_THRESHOLD => $threshold]),
        );
    }

    public function testTwoRidersVerifyAPlace(): void
    {
        self::bootKernel();
        $item = $this->item();
        $service = $this->service(2);

        $service->record($item, $this->rider('thr-one@test.test'), ConfirmationStance::Potable);
        self::assertSame(ItemState::Unverified, $item->getState(), 'one rider is not repetition');

        $service->record($item, $this->rider('thr-two@test.test'), ConfirmationStance::Potable);
        self::assertSame(ItemState::Verified, $item->getState());
    }

    public function testThePromotionIsRecordedAgainstTheRiderWhoTippedIt(): void
    {
        self::bootKernel();
        $item = $this->item();
        $service = $this->service(2);
        $service->record($item, $this->rider('thr-hist-one@test.test'), ConfirmationStance::Potable);
        $second = $this->rider('thr-hist-two@test.test');

        $service->record($item, $second, ConfirmationStance::Potable);

        $history = $this->em()->getRepository(ChangeHistory::class)
            ->findOneBy(['itemId' => $item->getId(), 'field' => 'state']);
        self::assertNotNull($history, 'the promotion is an act, recorded as one');
        self::assertSame((int) $second->getId(), $history->getChangedBy());
        self::assertSame(ItemState::Verified->value, $history->getNewValue());
    }

    public function testTheThresholdIsTheSettingNotAConstant(): void
    {
        self::bootKernel();
        $item = $this->item('C');
        $service = $this->service(3);

        $service->record($item, $this->rider('thr-dial-one@test.test'), ConfirmationStance::Exists);
        $service->record($item, $this->rider('thr-dial-two@test.test'), ConfirmationStance::Exists);
        self::assertSame(ItemState::Unverified, $item->getState(), 'two is short of three');

        $service->record($item, $this->rider('thr-dial-three@test.test'), ConfirmationStance::Exists);
        self::assertSame(ItemState::Verified, $item->getState());
    }

    public function testTheSameRiderTwiceIsStillOneRider(): void
    {
        self::bootKernel();
        $item = $this->item();
        $service = $this->service(2);
        $rider = $this->rider('thr-twice@test.test');

        $service->record($item, $rider, ConfirmationStance::Potable);
        $service->record($item, $rider, ConfirmationStance::Potable);

        self::assertSame(ItemState::Unverified, $item->getState());
    }

    public function testAWarningNeverCountsTowardsTheThreshold(): void
    {
        // "Not potable" says the water is bad, not that the entry is good.
        self::bootKernel();
        $item = $this->item();
        $service = $this->service(2);

        $service->record($item, $this->rider('thr-warn-one@test.test'), ConfirmationStance::NotPotable);
        $service->record($item, $this->rider('thr-warn-two@test.test'), ConfirmationStance::NotPotable);

        self::assertSame(ItemState::Unverified, $item->getState());
    }

    public function testFormAnswersNeverCountTowardsTheThreshold(): void
    {
        // A submitter is not a witness to their own submission.
        self::bootKernel();
        $item = $this->item();
        $service = $this->service(2);

        $service->record($item, $this->rider('thr-form-one@test.test'), ConfirmationStance::Potable, ConfirmationSource::Form);
        $service->record($item, $this->rider('thr-form-two@test.test'), ConfirmationStance::Potable, ConfirmationSource::Form);

        self::assertSame(ItemState::Unverified, $item->getState());
    }

    public function testARegisterRowEarnsItTheSameWayAsAnyOther(): void
    {
        // Owner 2026-09-09: one rule for OSM, provider and our own rows. An
        // authority row is a place nobody here has stood at until they have.
        self::bootKernel();
        $item = $this->item('C', ItemSource::Authority);
        $service = $this->service(2);

        $service->record($item, $this->rider('thr-auth-one@test.test'), ConfirmationStance::Exists);
        self::assertSame(ItemState::Unverified, $item->getState());

        $service->record($item, $this->rider('thr-auth-two@test.test'), ConfirmationStance::Exists);
        self::assertSame(ItemState::Verified, $item->getState());
    }
}
