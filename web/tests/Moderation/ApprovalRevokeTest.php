<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Community\ItemConfirmationService;
use App\Contribution\PlaceTextProposals;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Moderation\ApprovalRevoker;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationService;
use App\Moderation\OutOfScopeException;
use App\Moderation\RevokeRefused;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Revoking an approval (moderation-and-contribution.md §5.6): what the
 * approval changed is undone, the submission waits in the queue again, the
 * rider is told why; refused when anything it wrote changed since.
 *
 * One region far from any real one, its curator, a rider. DAMA wraps each
 * test in a rolled-back transaction.
 */
final class ApprovalRevokeTest extends WebTestCase
{
    public function testRevokingAnEditPutsTheOldValuesBackAndTheSubmissionInTheQueue(): void
    {
        static::createClient()->disableReboot();
        [$region, $curator, $rider] = $this->scene('rv1');
        [$item, $sub] = $this->edit($region, $rider, ['hours' => 'closed Sundays'], ['hours' => ['was' => 'closed Sundays', 'now' => 'weekdays only']]);
        $this->moderation()->decide((int) $sub->getId(), 'approve', $curator, null);
        self::assertSame('weekdays only', $this->fresh($item)->getAttributes()['hours']);

        $this->revoker()->revoke((int) $sub->getId(), $curator, 'Wrong place: this was the shop next door.');

        self::assertSame('closed Sundays', $this->fresh($item)->getAttributes()['hours'], 'the old value is back');
        $after = $this->freshSub($sub);
        self::assertSame(SubmissionStatus::Pending, $after->getStatus(), 'back in the queue');
        self::assertNull($after->getDecidedBy());
        self::assertNull($after->getDecidedAt());
        $record = $after->getPayload()['_revoked'][0] ?? [];
        self::assertSame($curator->getId(), $record['by'] ?? null);
        self::assertSame('Wrong place: this was the shop next door.', $record['reason'] ?? null);
        self::assertSame($curator->getId(), $record['approvedBy'] ?? null);

        $undo = $this->db()->fetchAssociative(
            "SELECT submission_id, changed_by, old_value, new_value FROM change_history WHERE item_id = :i AND field = 'hours' ORDER BY id DESC LIMIT 1",
            ['i' => $item->getId()],
        );
        self::assertIsArray($undo);
        self::assertSame($sub->getId(), (int) $undo['submission_id'], 'the undo is filed under what it undoes');
        self::assertSame($curator->getId(), (int) $undo['changed_by']);
        self::assertSame('"weekdays only"', $undo['old_value']);
        self::assertSame('"closed Sundays"', $undo['new_value']);

        $message = $this->em()->getRepository(UserMessage::class)->findOneBy(['userId' => $rider->getId(), 'kind' => UserMessageKind::SubmissionRevoked->value]);
        self::assertInstanceOf(UserMessage::class, $message, 'the rider is told');
        self::assertSame('1', (string) $this->db()->fetchOne("SELECT COUNT(*) FROM admin_action_log WHERE action = 'submission_revoked' AND note LIKE :n", ['n' => 'SUB-'.$sub->getId().' %']));

        // Decided again and revoked again: the second approval's rows are its own.
        $this->moderation()->decide((int) $sub->getId(), 'approve', $curator, null);
        self::assertSame('weekdays only', $this->fresh($item)->getAttributes()['hours']);
        $this->revoker()->revoke((int) $sub->getId(), $curator, 'Still the shop next door.');
        self::assertSame('closed Sundays', $this->fresh($item)->getAttributes()['hours']);
        self::assertCount(2, $this->freshSub($sub)->getPayload()['_revoked']);
    }

    public function testALaterChangeToTheSameValueRefusesTheRevokeAndChangesNothing(): void
    {
        static::createClient()->disableReboot();
        [$region, $curator, $rider] = $this->scene('rv2');
        [$item, $first] = $this->edit($region, $rider, ['hours' => 'closed Sundays'], ['hours' => ['was' => 'closed Sundays', 'now' => 'weekdays only']]);
        $this->moderation()->decide((int) $first->getId(), 'approve', $curator, null);
        $second = $this->submission($region, $rider, SubmissionType::Edit, $item, ['hours' => ['was' => 'weekdays only', 'now' => 'every day']]);
        $this->moderation()->decide((int) $second->getId(), 'approve', $curator, null);

        try {
            $this->revoker()->revoke((int) $first->getId(), $curator, 'Mistake.');
            self::fail('a value changed since must refuse the revoke');
        } catch (RevokeRefused $e) {
            self::assertSame('moderate.revoke.error.changed_since', $e->getMessage());
            self::assertSame(['Repair rv2: hours'], $e->fields);
        }
        self::assertSame('every day', $this->fresh($item)->getAttributes()['hours'], 'nothing newer is overwritten');
        self::assertSame(SubmissionStatus::Approved, $this->freshSub($first)->getStatus());

        // The later one can go first; then the first is free again.
        $this->revoker()->revoke((int) $second->getId(), $curator, 'Mistake.');
        $this->revoker()->revoke((int) $first->getId(), $curator, 'Mistake.');
        self::assertSame('closed Sundays', $this->fresh($item)->getAttributes()['hours']);
    }

    public function testRevokingANewPlaceTakesItOffTheMapUnlessItWasVerifiedSince(): void
    {
        static::createClient()->disableReboot();
        [$region, $curator, $rider] = $this->scene('rv3');
        $item = (new Item())->answerOsm(null)->setLetter('D')->setName('New stand rv3')
            ->setGeom('{"type":"Point","coordinates":[-140.5,-40.5]}')->setCountryCode('PN')->setRegionId($region->getId())
            ->setState(ItemState::Submitted)->setSource(ItemSource::User)->setSourceRef('sub:rv3');
        $this->em()->persist($item);
        $this->em()->flush();
        $sub = $this->submission($region, $rider, SubmissionType::NewItem, $item, ['name' => ['was' => null, 'now' => 'New stand rv3']]);

        $this->moderation()->decide((int) $sub->getId(), 'approve', $curator, null);
        self::assertSame(ItemState::Unverified, $this->fresh($item)->getState());
        $this->revoker()->revoke((int) $sub->getId(), $curator, 'Not a public stand.');
        self::assertSame(ItemState::Submitted, $this->fresh($item)->getState(), 'off the map, waiting again');

        $this->moderation()->decide((int) $sub->getId(), 'approve', $curator, null);
        /** @var ItemConfirmationService $confirmations */
        $confirmations = static::getContainer()->get(ItemConfirmationService::class);
        $confirmations->record($this->fresh($item), $curator, ConfirmationStance::Exists);
        self::assertSame(ItemState::Verified, $this->fresh($item)->getState());

        $this->expectException(RevokeRefused::class);
        $this->revoker()->revoke((int) $sub->getId(), $curator, 'Not a public stand.');
    }

    public function testARevokeNeedsAReasonAndAnApproval(): void
    {
        static::createClient()->disableReboot();
        [$region, $curator, $rider] = $this->scene('rv4');
        [, $sub] = $this->edit($region, $rider, ['hours' => 'a'], ['hours' => ['was' => 'a', 'now' => 'b']]);

        foreach ([['  ', 'moderate.revoke.error.reason_required'], ['Mistake.', 'moderate.revoke.error.not_approved']] as [$reason, $error]) {
            try {
                $this->revoker()->revoke((int) $sub->getId(), $curator, $reason);
                self::fail($error.' expected');
            } catch (RevokeRefused $e) {
                self::assertSame($error, $e->getMessage());
            }
        }
    }

    public function testRevokingAHistoryTextPutsBackExactlyWhatWasThere(): void
    {
        static::createClient()->disableReboot();
        [$region, $curator, $rider] = $this->scene('rv5');
        $this->db()->executeStatement('UPDATE region SET story = CAST(:s AS jsonb) WHERE id = :id', [
            's' => json_encode(['en' => ['text' => 'The old text.', 'userId' => $curator->getId(), 'approvedBy' => $curator->getId(), 'submissionId' => null, 'at' => '2026-10-01T00:00:00+00:00']], \JSON_THROW_ON_ERROR),
            'id' => $region->getId(),
        ]);
        $info = $this->proposals()->region('rv5');
        self::assertNotNull($info);
        $sub = $this->proposals()->proposeStory($rider, $info, 'en', 'The new text.', '')['submission'];
        $this->proposals()->approve($this->freshSub($sub), $curator, 'The new text.', null, '');
        self::assertSame('The new text.', $this->story($region)['en']['text']);

        $this->revoker()->revoke((int) $sub->getId(), $curator, 'Copied from a book.');

        $entry = $this->story($region)['en'];
        self::assertSame('The old text.', $entry['text']);
        self::assertSame($curator->getId(), $entry['userId'], 'its writer is credited again');
        self::assertSame(SubmissionStatus::Pending, $this->freshSub($sub)->getStatus());
        self::assertArrayNotHasKey('_before', $this->freshSub($sub)->getPayload());

        // Approved again, then written over by another approval: not revoked.
        $this->proposals()->approve($this->freshSub($sub), $curator, 'The new text.', null, '');
        $info = $this->proposals()->region('rv5');
        self::assertNotNull($info);
        $other = $this->proposals()->proposeStory($curator, $info, 'en', 'A curator\'s own text.', '');
        self::assertTrue($other['applied']);
        try {
            $this->revoker()->revoke((int) $sub->getId(), $curator, 'Copied from a book.');
            self::fail('a text written over since must refuse the revoke');
        } catch (RevokeRefused $e) {
            self::assertSame('moderate.revoke.error.changed_since', $e->getMessage());
        }
        self::assertSame('A curator\'s own text.', $this->story($region)['en']['text']);
    }

    public function testTheHistoryDeskRevokesWithAReasonAndOnlyInTheCuratorsArea(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$region, $curator, $rider] = $this->scene('rv6');
        [$item, $sub] = $this->edit($region, $rider, ['hours' => 'a'], ['hours' => ['was' => 'a', 'now' => 'b']]);
        $this->moderation()->decide((int) $sub->getId(), 'approve', $curator, null);

        $client->loginUser($curator);
        $crawler = $client->request('GET', '/moderate/submissions/history');
        self::assertResponseIsSuccessful();
        self::assertSame('EN', $crawler->filter('.q-item[data-submission-id="'.$sub->getId().'"] .q-tag--lang')->text(), 'the card says the language, English when none was given');
        $form = $crawler->filter('.q-item[data-submission-id="'.$sub->getId().'"] .q-act--revoke form');
        self::assertCount(1, $form, 'an approved row carries the revoke');
        $client->submit($form->form(['reason' => 'Wrong place.']));
        self::assertResponseRedirects('/moderate/submissions/history');
        $client->followRedirect();
        self::assertStringContainsString('Approval revoked. #'.$sub->getCode().' is back in the queue.', (string) $client->getResponse()->getContent());
        self::assertSame('a', $this->fresh($item)->getAttributes()['hours']);

        // A curator of another area may not.
        $this->moderation()->decide((int) $sub->getId(), 'approve', $curator, null);
        [, $elsewhere] = $this->scene('rv6b', -120.5);
        try {
            $this->revoker()->revoke((int) $sub->getId(), $elsewhere, 'No.');
            self::fail('a curator of another area may not revoke');
        } catch (OutOfScopeException) {
        }
        self::assertSame('b', $this->fresh($item)->getAttributes()['hours']);
        self::assertSame(SubmissionStatus::Approved, $this->freshSub($sub)->getStatus());

        // And the form's own token only.
        $client->request('POST', '/moderate/revoke/'.$sub->getId(), ['_token' => 'x', 'reason' => 'No.']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A region, its curator, a rider.
     *
     * @return array{0: Region, 1: User, 2: User}
     */
    private function scene(string $slug, float $lng = -140.5): array
    {
        $region = (new Region())->setSlug($slug)->setName('Region '.$slug)->setCountryCode('PN')->setAreaKm2(1.0)
            ->setGeom(sprintf('{"type":"MultiPolygon","coordinates":[[[[%1$s,-41],[%2$s,-41],[%2$s,-40],[%1$s,-40],[%1$s,-41]]]]}', $lng - 0.5, $lng + 0.5));
        $this->em()->persist($region);
        $this->em()->flush();

        return [$region, $this->user($slug.'-cur@example.com', ['ROLE_CURATOR'], $region), $this->user($slug.'-rider@example.com', [])];
    }

    /**
     * @param array<string, mixed>                         $attributes
     * @param array<string, array{was: mixed, now: mixed}> $changes
     *
     * @return array{0: Item, 1: Submission}
     */
    private function edit(Region $region, User $rider, array $attributes, array $changes): array
    {
        $item = (new Item())->setLetter('D')->setName('Repair '.$region->getSlug())
            ->setGeom('{"type":"Point","coordinates":[-140.5,-40.5]}')->setCountryCode('PN')->setRegionId($region->getId())
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)->setSourceRef('node/'.random_int(1, 999999999))
            ->setAttributes($attributes);
        $this->em()->persist($item);
        $this->em()->flush();

        return [$item, $this->submission($region, $rider, SubmissionType::Edit, $item, $changes)];
    }

    /** @param array<string, array{was: mixed, now: mixed}> $changes */
    private function submission(Region $region, User $rider, SubmissionType $type, Item $item, array $changes): Submission
    {
        $sub = (new Submission())->setType($type)->setLetter($item->getLetter())->setUserId((int) $rider->getId())
            ->setItemId($item->getId())->setTitle($item->getName())
            ->setGeom('{"type":"Point","coordinates":[-140.5,-40.5]}')->setCountryCode('PN')->setRegionId($region->getId())
            ->setChanges($changes)->setPayload([]);
        $this->em()->persist($sub);
        $this->em()->flush();

        return $sub;
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles, ?Region $area = null): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'securepass12345!'));
        $this->em()->persist($user);
        $this->em()->flush();
        if (null !== $area) {
            $this->em()->persist(new ModeratorArea((int) $user->getId(), (int) $area->getId(), null));
            $this->em()->flush();
        }

        return $user;
    }

    /** @return array<string, array<string, mixed>> */
    private function story(Region $region): array
    {
        /* @var array<string, array<string, mixed>> */
        return json_decode((string) $this->db()->fetchOne('SELECT story FROM region WHERE id = :id', ['id' => $region->getId()]), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function fresh(Item $item): Item
    {
        $fresh = $this->em()->find(Item::class, (int) $item->getId());
        self::assertInstanceOf(Item::class, $fresh);
        $this->em()->refresh($fresh);

        return $fresh;
    }

    private function freshSub(Submission $submission): Submission
    {
        $fresh = $this->em()->find(Submission::class, (int) $submission->getId());
        self::assertInstanceOf(Submission::class, $fresh);
        $this->em()->refresh($fresh);

        return $fresh;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    private function db(): Connection
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        return $db;
    }

    private function moderation(): ModerationService
    {
        /** @var ModerationService $m */
        $m = static::getContainer()->get(ModerationService::class);

        return $m;
    }

    private function revoker(): ApprovalRevoker
    {
        /** @var ApprovalRevoker $r */
        $r = static::getContainer()->get(ApprovalRevoker::class);

        return $r;
    }

    private function proposals(): PlaceTextProposals
    {
        /** @var PlaceTextProposals $p */
        $p = static::getContainer()->get(PlaceTextProposals::class);

        return $p;
    }
}
