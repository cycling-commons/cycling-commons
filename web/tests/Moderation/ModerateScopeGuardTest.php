<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Moderation\Entity\ModeratorArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * moderator-areas spec 2026-07-14, task 3: hard scope guards on submission
 * writes. A curator confined to region A must not be able to decide, trash,
 * or message a submission that lives in region B — even by posting the id
 * directly, bypassing whatever the queue happens to render. ROLE_ADMIN stays
 * global regardless of any moderator_area rows attached to it.
 *
 * Copied helper shapes from ModerateTest (createUser/seedSubmission) — see
 * that file's docblock for why ROLE_CURATOR needs a totpSecret here.
 */
final class ModerateScopeGuardTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param list<string> $roles
     */
    private function createUser(
        string $email,
        string $plain,
        array $roles = [],
        ?string $totpSecret = null,
        bool $twoFaEnabled = false,
    ): User {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, $plain));

        if (null !== $totpSecret) {
            $user->setTotpSecret($totpSecret);
            $user->setTwoFaEnabled($twoFaEnabled);
        }

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedSubmission(string $title, ?int $regionId, string $country = 'BE'): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $submitterEmail = 'submitter-'.uniqid('', true).'@example.com';
        $submitter = new User();
        $submitter->setEmail($submitterEmail);
        $submitter->setDisplayName(strstr($submitterEmail, '@', true) ?: $submitterEmail);
        $submitter->setPassword('x');
        $em->persist($submitter);
        $em->flush();

        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('N')->setUserId((int) $submitter->getId())
            ->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode($country)->setRegionId($regionId)
            ->setChanges([])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();

        return $sub;
    }

    private function seedRegion(string $slug, string $name, string $country): Region
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $region = (new Region())->setSlug($slug)->setName($name)->setCountryCode($country)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $em->persist($region);
        $em->flush();

        return $region;
    }

    private function assignRegion(User $curator, int $regionId): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ModeratorArea((int) $curator->getId(), $regionId, null));
        $em->flush();
    }

    private function curator(string $email): User
    {
        return $this->createUser(
            $email,
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function testQueueHidesOutOfScopeSubmission(): void
    {
        $client = static::createClient();
        $regionA = $this->seedRegion('guard-a', 'Guard Region A', 'BE');
        $regionB = $this->seedRegion('guard-b', 'Guard Region B', 'NL');
        $subA = $this->seedSubmission('In region A', $regionA->getId());
        $subB = $this->seedSubmission('In region B', $regionB->getId(), 'NL');

        $curator = $this->curator('guard-queue@example.com');
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('.q-title', $subA->getTitle());
        self::assertSelectorTextNotContains('.q-title', $subB->getTitle());
    }

    public function testUnassignedCuratorSeesEverything(): void
    {
        $client = static::createClient();
        $regionA = $this->seedRegion('guard-unassigned-a', 'Guard Unassigned A', 'BE');
        $regionB = $this->seedRegion('guard-unassigned-b', 'Guard Unassigned B', 'NL');
        $subA = $this->seedSubmission('In region A', $regionA->getId());
        $subB = $this->seedSubmission('In region B', $regionB->getId(), 'NL');

        $curator = $this->curator('guard-unassigned@example.com');
        // No assignRegion() call — a curator with zero moderator_area rows is
        // global (rollout safety: newly promoted curators aren't silently
        // scoped to nothing).
        $client->loginUser($curator);

        $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($subA->getTitle(), $body);
        self::assertStringContainsString($subB->getTitle(), $body);
    }

    public function testDecideOutOfScopeIs403(): void
    {
        $client = static::createClient();
        $regionA = $this->seedRegion('guard-decide-a', 'Guard Decide A', 'BE');
        $regionB = $this->seedRegion('guard-decide-b', 'Guard Decide B', 'NL');
        $this->seedSubmission('Decide in-scope', $regionA->getId());
        $subB = $this->seedSubmission('Decide out-of-scope', $regionB->getId(), 'NL');

        $curator = $this->curator('guard-decide@example.com');
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        // The decision form lives on the map drawer now; mint the stateless
        // "submit" CSRF token from window.CC_MOD_TOKEN there.
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', $html, $m));
        $token = $m[1];

        $client->request('POST', '/moderate/decide', [
            'moderation_decision' => [
                'submission_id' => (string) $subB->getId(),
                'decision' => 'approve',
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(403);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(Submission::class, $subB->getId());
        self::assertNotNull($reloaded);
        self::assertSame(SubmissionStatus::Pending, $reloaded->getStatus());
    }

    public function testDecideInScopeStillWorks(): void
    {
        $client = static::createClient();
        $regionA = $this->seedRegion('guard-inscope-a', 'Guard Inscope A', 'BE');
        $subA = $this->seedSubmission('Decide me', $regionA->getId());

        $curator = $this->curator('guard-inscope@example.com');
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', $html, $m));
        $token = $m[1];

        $client->request('POST', '/moderate/decide', [
            'moderation_decision' => [
                'submission_id' => (string) $subA->getId(),
                'decision' => 'approve',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/moderate');
    }

    public function testTrashOutOfScopeIs403(): void
    {
        $client = static::createClient();
        $regionA = $this->seedRegion('guard-trash-a', 'Guard Trash A', 'BE');
        $regionB = $this->seedRegion('guard-trash-b', 'Guard Trash B', 'NL');
        $this->seedSubmission('Trash in-scope', $regionA->getId());
        $subB = $this->seedSubmission('Trash out-of-scope', $regionB->getId(), 'NL');

        $curator = $this->curator('guard-trash@example.com');
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        // `moderate-trash` is a session-bound CSRF token id (TrashTest's
        // submissionTrashToken pattern): read it off the trash-confirm form
        // the queue renders for the in-scope item — the token is keyed to
        // (session, token id) only, not to the row id.
        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('.trash-confirm input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/moderate/trash', [
            'kind' => 'submission',
            'confirm' => 'DELETE',
            'id' => (string) $subB->getId(),
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);

        // The out-of-scope row must still exist — the guard fires before the
        // audit log and the remove(), so nothing was deleted.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Submission::class, $subB->getId()));
    }

    public function testMessageOutOfScopeIs403(): void
    {
        $client = static::createClient();
        $regionA = $this->seedRegion('guard-msg-a', 'Guard Msg A', 'BE');
        $regionB = $this->seedRegion('guard-msg-b', 'Guard Msg B', 'NL');
        $this->seedSubmission('Message in-scope', $regionA->getId());
        $subB = $this->seedSubmission('Message out-of-scope', $regionB->getId(), 'NL');

        $curator = $this->curator('guard-message@example.com');
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        // The queue renders one .q-act--message form per visible (in-scope) item —
        // the CSRF token id ('moderate-message') is fixed, not tied to the
        // particular submission it happened to render alongside.
        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('.q-act--message input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/moderate/message', [
            'channel' => 'submission',
            'id' => (string) $subB->getId(),
            'body' => 'Trying to reach across regions.',
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);

        // The guard fires before MessageService::sendCurator() — no message
        // row may exist for the out-of-scope submission.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(UserMessage::class)->findBy([
            'channel' => 'submission',
            'refId' => (int) $subB->getId(),
        ]));
    }

    public function testAdminIgnoresAreaRows(): void
    {
        $client = static::createClient();
        $regionA = $this->seedRegion('guard-admin-a', 'Guard Admin A', 'BE');
        $regionB = $this->seedRegion('guard-admin-b', 'Guard Admin B', 'NL');
        $this->seedSubmission('Admin sees A', $regionA->getId());
        $subB = $this->seedSubmission('Admin sees B', $regionB->getId(), 'NL');

        $admin = $this->createUser(
            'guard-admin@example.com',
            'hunter2secure!',
            roles: ['ROLE_ADMIN'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        // Even with a stray area row, ROLE_ADMIN must stay global.
        $this->assignRegion($admin, (int) $regionA->getId());
        $client->loginUser($admin);

        $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Admin sees A', $body);
        self::assertStringContainsString('Admin sees B', $body);

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', $html, $m));
        $token = $m[1];

        $client->request('POST', '/moderate/decide', [
            'moderation_decision' => [
                'submission_id' => (string) $subB->getId(),
                'decision' => 'approve',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/moderate');
    }

    public function testModeratorBarShowsAssignedRegionName(): void
    {
        $client = static::createClient();
        $region = $this->seedRegion('wallonia', 'Wallonia', 'BE');

        $curator = $this->curator('guard-label-region@example.com');
        $this->assignRegion($curator, (int) $region->getId());
        $client->loginUser($curator);

        $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.dtabs-modlabel', 'Wallonia');
    }

    public function testModeratorBarShowsAllAreasForUnassignedCurator(): void
    {
        $client = static::createClient();
        $curator = $this->curator('guard-label-all@example.com');
        $client->loginUser($curator);

        $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.dtabs-modlabel', 'All areas');
    }
}
