<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Add-climb page: auth-gate, rendering, and CSRF-protected POST to the contribution stub.
 *
 * All tests use a plain ROLE_USER to avoid triggering TwoFactorSetupEnforcer
 * (which redirects elevated roles without a TOTP secret to /2fa/setup).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class AddClimbTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    private function createUser(string $email, string $plain): void
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Test Climber');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();
    }

    private function loginAs(KernelBrowser $client, string $email, string $plain): void
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    // ── Auth-gate ────────────────────────────────────────────────────────────

    public function testAnonGetAddClimbRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/add-climb');

        self::assertResponseRedirects('/login', 302);
    }

    // ── Authenticated GET ────────────────────────────────────────────────────

    public function testAuthenticatedUserCanGetAddClimbPage(): void
    {
        $client = static::createClient();

        $email = 'addclimb-get@example.com';
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);

        $client->request('GET', '/add-climb');

        self::assertResponseIsSuccessful();
        // Step 1 section visible
        self::assertSelectorExists('.stepper li.on');
        // Name field present in the DOM (all fields rendered; step 2 hidden by CSS only)
        self::assertSelectorExists('[name="add_climb[fName]"]');
        // Wizard heading present
        self::assertSelectorTextContains('h1.disp', 'Add a climb');
        // Shared three-point editor's hidden geometry fields (route/grad/steep) are present
        self::assertSelectorExists('[name="add_climb[route]"]');
        self::assertSelectorExists('[name="add_climb[grad]"]');
        self::assertSelectorExists('[name="add_climb[steep]"]');
    }

    // ── Authenticated POST — valid submission ────────────────────────────────

    public function testValidPostShowsHonestStubReceipt(): void
    {
        $client = static::createClient();

        $email = 'addclimb-post@example.com';
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/add-climb');
        self::assertResponseIsSuccessful();

        // Select the "Next →" button to get the right form (not nav logout form).
        // The wizard hides later steps with CSS but all fields are in the DOM.
        $form = $crawler->selectButton('Next →')->form([
            'add_climb[fName]' => 'Côte de La Redoute',
            'add_climb[fLen]' => '1.8',
            'add_climb[fGain]' => '161',
            'add_climb[fAvg]' => '8.9',
            'add_climb[fMax]' => '19.0',
            'add_climb[fSurface]' => 'Asphalt',
            'add_climb[fSurfaceQ]' => 'Good',
            'add_climb[fTraffic]' => 'Quiet',
            'add_climb[fNote]' => 'Classics climb, brutal final ramp.',
            'add_climb[fOsm]' => 'Yes',
            'add_climb[lat]' => '50.499',
            'add_climb[lng]' => '5.739',
            'add_climb[place]' => 'La Redoute, Remouchamps',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        // Real intake (Task 3): a Submission row (state=submitted item, pending
        // review) now backs the receipt — reference is SUB-<submissionId>, not
        // the old unpersisted-stub CC- prefix.
        self::assertSelectorTextContains('.receipt h2', 'Climb submitted.');
        self::assertSelectorTextContains('.receipt .stub-note', 'awaiting curator review');
        self::assertSelectorTextContains('.receipt .ref', 'SUB-');
    }
}
