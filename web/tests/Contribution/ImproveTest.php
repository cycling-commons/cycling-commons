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
 * Improve page: auth-gate, type-aware rendering (A–K), and CSRF-protected POST
 * to the contribution stub.
 *
 * The wizard's Details step is driven by the catalog registry — each type
 * renders its own Fix-details (`improve[details][*]`) and Add-missing
 * (`improve[extras][*]`) fields. Persistence is still stubbed.
 *
 * All tests use a plain ROLE_USER to avoid triggering TwoFactorSetupEnforcer
 * (which redirects elevated roles without a TOTP secret to /2fa/setup).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class ImproveTest extends WebTestCase
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
        $user->setDisplayName('Test Contributor');
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

    private function loginFreshUser(KernelBrowser $client, string $tag): void
    {
        $email = "improve-{$tag}@example.com";
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);
    }

    // ── Auth-gate ────────────────────────────────────────────────────────────

    public function testAnonGetImproveRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/improve');

        self::assertResponseRedirects('/login', 302);
    }

    // ── Authenticated GET — default type (D · bike services) ─────────────────

    public function testDefaultTypeRendersItsOwnFields(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'get');

        $client->request('GET', '/improve');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.disp', 'Improve or add a place');
        self::assertSelectorExists('.stepper li.on');
        // No-param fallback is D · bike services — its typed fields, not a
        // generic "what changed" textarea.
        self::assertSelectorExists('[name="improve[details][pumpValve]"]');
        self::assertSelectorNotExists('[name="improve[whatChanged]"]');
    }

    // ── Type-aware rendering ─────────────────────────────────────────────────

    public function testTypeQueryRendersThatTypesFields(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'water');

        $client->request('GET', '/improve?type=water-food');

        self::assertResponseIsSuccessful();
        // Water & food keeps the "Unsigned — use judgement" potable option.
        self::assertSelectorExists('[name="improve[details][potable]"]');
        self::assertSelectorNotExists('[name="improve[details][pumpValve]"]');
    }

    public function testRoadSurfaceExposesSegmentLocationMode(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'surface');

        $client->request('GET', '/improve?type=road-surface&mode=add');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="improve[details][surface]"]');
        // The client wizard reads how to set the location off the container.
        self::assertSelectorExists('#wiz[data-location-mode="segment"]');
    }

    public function testRideExposesTrackUploadMode(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'ride');

        $client->request('GET', '/improve?type=quality-rides&mode=add');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="improve[details][rideName]"]');
        self::assertSelectorExists('#wiz[data-location-mode="none"]');
        self::assertSelectorExists('#wiz[data-track="1"]');
    }

    // ── Votability / lifecycle context (funnel model) ────────────────────────

    public function testVotableTypeShowsVotabilityContext(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'votable');

        $client->request('GET', '/improve?type=climbs');

        self::assertResponseIsSuccessful();
        // Climbs (B) are a votable type — the wizard says so, tying into the
        // verification→votable→best-of funnel from the edit-items spec.
        self::assertSelectorTextContains('.lc-verdict', 'votable');
    }

    public function testUtilityTypeShowsCoverageContext(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'utility');

        $client->request('GET', '/improve?type=water-food');

        self::assertResponseIsSuccessful();
        // Water & food (C) is a utility type — verified for coverage, never ranked.
        self::assertSelectorTextContains('.lc-verdict', 'utility');
    }

    public function testLetterQueryResolvesType(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'letter');

        // Map deep-links carry the catalog letter (layer.letter) — E is sleep.
        $client->request('GET', '/improve?type=E&mode=add');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="improve[details][bikeStorage]"]');
    }

    // ── Deep-link with an opaque feature id still resolves ────────────────────

    public function testDeepLinkWithItemAndModeReturns200(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'deep');

        $client->request('GET', '/improve?type=water-food&item=water-fountain&mode=add');

        self::assertResponseIsSuccessful();
    }

    // ── Authenticated POST — valid submission ────────────────────────────────

    public function testValidPostShowsHonestStubReceipt(): void
    {
        // Task 3 (plan 2026-07-04, §"Real intake") makes 'climb' real and turns
        // 'improve' into a `\LogicException('improve lands in Task 4')`
        // placeholder — the honest-stub CC- receipt this test asserts no longer
        // renders for improve. Task 4 ("Edit flow") implements improve for real
        // and replaces this scenario with tests/Contribution/ImproveBindingTest.php.
        self::markTestSkipped('improve is a LogicException placeholder until Task 4 (real edit-submission binding) lands.');

        $client = static::createClient();
        $this->loginFreshUser($client, 'post');

        $crawler = $client->request('GET', '/improve');
        self::assertResponseIsSuccessful();

        // Select the "Next →" button to get the right form (not nav logout form).
        $form = $crawler->selectButton('Next →')->form([
            'improve[details][name]' => 'Malmedy repair point',
            'improve[details][correction]' => 'A work stand was added this spring.',
            'improve[lat]' => '50.426',
            'improve[lng]' => '6.027',
            'improve[place]' => 'Malmedy, Wallonia',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        // Honest stub state: queued for review / not yet persisted.
        self::assertSelectorTextContains('.receipt h2', 'Suggestion submitted.');
        self::assertSelectorTextContains('.receipt .stub-note', 'not yet persisted');
        self::assertSelectorTextContains('.receipt .ref', 'CC-');
    }
}
