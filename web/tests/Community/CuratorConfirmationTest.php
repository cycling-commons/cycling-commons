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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A curator's confirmation verifies an item outright.
 *
 * Repetition by strangers is the only real check this project has — a photo can
 * be generated, a place invented — which is why three riders mean something and
 * one does not. But it is the wrong instrument for a castle (owner 2026-08-12):
 * some things a curator settles by looking, and making them wait for three
 * riders to pass is ceremony rather than verification.
 */
final class CuratorConfirmationTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles): User
    {
        $u = (new User())->setEmail($email)->setDisplayName('U');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles($roles);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    private function item(string $letter = 'Q', ItemState $state = ItemState::Unverified): Item
    {
        $item = (new Item())->setLetter($letter)->setName('Muiderslot')
            ->setGeom('{"type":"Point","coordinates":[5.07,52.33]}')->setCountryCode('NL')
            ->setState($state)->setSource(ItemSource::Osm)
            ->setSourceRef('node/'.bin2hex(random_bytes(4)))->setAttributes([]);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function service(): ItemConfirmationService
    {
        return static::getContainer()->get(ItemConfirmationService::class);
    }

    public function testACuratorsConfirmationVerifiesTheItem(): void
    {
        self::bootKernel();
        $item = $this->item();
        $curator = $this->user('curator-confirm@test.test', ['ROLE_CURATOR']);

        $this->service()->record($item, $curator, ConfirmationStance::Exists);

        self::assertSame(ItemState::Verified, $item->getState());
        $history = $this->em()->getRepository(ChangeHistory::class)
            ->findOneBy(['itemId' => $item->getId(), 'field' => 'state']);
        self::assertNotNull($history, 'the promotion is an act, recorded as one');
        self::assertSame((int) $curator->getId(), $history->getChangedBy());
    }

    public function testAnAdminCountsAsACurator(): void
    {
        self::bootKernel();
        $item = $this->item();
        $admin = $this->user('admin-confirm@test.test', ['ROLE_ADMIN']);

        $this->service()->record($item, $admin, ConfirmationStance::Exists);

        self::assertSame(ItemState::Verified, $item->getState());
    }

    public function testAnOrdinaryRidersConfirmationDoesNotVerify(): void
    {
        // One stranger is not repetition. This is the whole reason the tally
        // exists, and a single rider must not be able to shortcut it.
        self::bootKernel();
        $item = $this->item();
        $rider = $this->user('rider-confirm@test.test', []);

        $this->service()->record($item, $rider, ConfirmationStance::Exists);

        self::assertSame(ItemState::Unverified, $item->getState());
    }

    public function testTheSubmittersOwnFormAnswerNeverVerifies(): void
    {
        // Even from a curator: a submitter is not a witness to their own
        // submission, which is what ConfirmationSource::Form marks.
        self::bootKernel();
        $item = $this->item();
        $curator = $this->user('form-confirm@test.test', ['ROLE_CURATOR']);

        $this->service()->record($item, $curator, ConfirmationStance::Exists, ConfirmationSource::Form);

        self::assertSame(ItemState::Unverified, $item->getState());
    }

    public function testAWarningIsNotAVouching(): void
    {
        // "Not potable" says the water is bad, not that the entry is good.
        // Verifying an item on the strength of a warning would read as approval
        // of the thing being warned about.
        self::bootKernel();
        $water = $this->item('C');
        $curator = $this->user('warn-confirm@test.test', ['ROLE_CURATOR']);

        $this->service()->record($water, $curator, ConfirmationStance::NotPotable);

        self::assertSame(ItemState::Unverified, $water->getState());
    }

    public function testAnAlreadyVerifiedItemIsNotTouchedAgain(): void
    {
        self::bootKernel();
        $item = $this->item('J', ItemState::Verified);
        $curator = $this->user('again-confirm@test.test', ['ROLE_CURATOR']);

        $this->service()->record($item, $curator, ConfirmationStance::Exists);

        self::assertSame(ItemState::Verified, $item->getState());
        self::assertCount(0, $this->em()->getRepository(ChangeHistory::class)
            ->findBy(['itemId' => $item->getId(), 'field' => 'state']), 'no second history row');
    }
}
