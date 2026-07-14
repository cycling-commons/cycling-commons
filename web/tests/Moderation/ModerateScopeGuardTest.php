<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
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
            ->setType(SubmissionType::NewItem)->setLetter('B')->setUserId((int) $submitter->getId())
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

        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="moderation_decision[_token]"]')->first()->attr('value');

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

        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="moderation_decision[_token]"]')->first()->attr('value');

        $client->request('POST', '/moderate/decide', [
            'moderation_decision' => [
                'submission_id' => (string) $subA->getId(),
                'decision' => 'approve',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/moderate');
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

        // The queue renders one .msg-rider form per visible (in-scope) item —
        // the CSRF token id ('moderate-message') is fixed, not tied to the
        // particular submission it happened to render alongside.
        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('.msg-rider input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/moderate/message', [
            'channel' => 'submission',
            'id' => (string) $subB->getId(),
            'body' => 'Trying to reach across regions.',
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
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

        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Admin sees A', $body);
        self::assertStringContainsString('Admin sees B', $body);

        $token = (string) $crawler->filter('input[name="moderation_decision[_token]"]')->first()->attr('value');

        $client->request('POST', '/moderate/decide', [
            'moderation_decision' => [
                'submission_id' => (string) $subB->getId(),
                'decision' => 'approve',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/moderate');
    }
}
