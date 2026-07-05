<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Improve page: auth-gate, type-aware rendering (A–K), and the real edit
 * submission (Task 4 — see ImproveBindingTest for the prefill/was-now-snapshot
 * scenarios this file doesn't duplicate).
 *
 * The wizard's Details step is driven by the catalog registry — each type
 * renders its own Fix-details (`improve[details][*]`) and Add-missing
 * (`improve[extras][*]`) fields. Since Task 4, `/improve` is bound to a real
 * item (`?item=<dbId>`, the map edit-bridge's target) and its letter — not a
 * `?type=` query param — drives which type's fields render; every scenario
 * below therefore seeds a real `Item` of the letter under test. Bare
 * `/improve` (no valid item) shows the unbound explainer, not a form —
 * covered by ImproveBindingTest::testUnboundImproveShowsExplainerNotForm.
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

    /**
     * A real seeded Item to bind `/improve?item=` to — the edit flow only
     * ever renders its type-aware form for a real target (spec §6/§8).
     *
     * @param array<string, mixed> $attributes
     */
    private function createItem(string $letter, array $attributes = [], string $name = 'Test place'): Item
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $item = (new Item())->setLetter($letter)->setName($name)
            ->setGeom('{"type":"Point","coordinates":[6.027,50.426]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)
            ->setSourceRef('node/improve-test-'.$letter.'-'.bin2hex(random_bytes(4)))
            ->setAttributes($attributes);
        $em->persist($item);
        $em->flush();

        return $item;
    }

    // ── Auth-gate ────────────────────────────────────────────────────────────

    public function testAnonGetImproveRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/improve');

        self::assertResponseRedirects('/login', 302);
    }

    // ── Authenticated GET — a bound item drives its own type's fields ────────

    public function testDefaultTypeRendersItsOwnFields(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'get');
        $item = $this->createItem('D');

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        // The wizard header names the exact item being edited (persistent across every step).
        self::assertSelectorTextContains('h1.disp', 'Test place');
        self::assertSelectorExists('.stepper li.on');
        // D · bike services renders its typed fields, not a generic
        // "what changed" textarea.
        self::assertSelectorExists('[name="improve[details][pumpValve]"]');
        self::assertSelectorNotExists('[name="improve[whatChanged]"]');
    }

    // ── Type-aware rendering (driven by the bound item's own letter) ─────────

    public function testItemLetterRendersThatTypesFields(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'water');
        $item = $this->createItem('C');

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        // Water & food keeps the "Unsigned — use judgement" potable option.
        self::assertSelectorExists('[name="improve[details][potable]"]');
        self::assertSelectorNotExists('[name="improve[details][pumpValve]"]');
    }

    public function testRoadSurfaceExposesSegmentLocationMode(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'surface');
        $item = $this->createItem('A');

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="improve[details][surface]"]');
        // The client wizard reads how to set the location off the container.
        self::assertSelectorExists('#wiz[data-location-mode="segment"]');
    }

    public function testRideExposesTrackUploadMode(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'ride');
        $item = $this->createItem('K');

        $client->request('GET', '/improve?item='.$item->getId());

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
        $item = $this->createItem('B');

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        // Climbs (B) are a votable type — the wizard says so, tying into the
        // verification→votable→best-of funnel from the edit-items spec.
        self::assertSelectorTextContains('.lc-verdict', 'votable');
    }

    public function testUtilityTypeShowsCoverageContext(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'utility');
        $item = $this->createItem('C');

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        // Water & food (C) is a utility type — verified for coverage, never ranked.
        self::assertSelectorTextContains('.lc-verdict', 'utility');
    }

    public function testBoundItemLetterResolvesType(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'letter');

        // Map deep-links carry the catalog letter (layer.letter) — E is sleep.
        $item = $this->createItem('E');
        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="improve[details][bikeStorage]"]');
    }

    // ── A stale/garbage deep-link degrades to the unbound explainer, not a 400 ─

    public function testDeepLinkWithItemAndModeReturns200(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'deep');

        // A non-numeric `item` (a stale slug-based deep link, pre-Task-4) is
        // not a valid binding — it must degrade to the unbound explainer,
        // never a 400/500.
        $client->request('GET', '/improve?type=water-food&item=water-fountain&mode=add');

        self::assertResponseIsSuccessful();
        // Confirm it's specifically the unbound explainer that rendered, not
        // some other 200 (e.g. a form that silently ignored the bad item).
        self::assertSelectorExists('[data-improve-unbound]');
        self::assertSelectorNotExists('form[name="improve"]');
    }

    // ── Authenticated POST — real edit submission ────────────────────────────

    public function testValidPostPersistsARealEditSubmission(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'post');
        $item = $this->createItem('D', ['correction' => 'Nothing to report yet.']);

        $crawler = $client->request('GET', '/improve?item='.$item->getId());
        self::assertResponseIsSuccessful();

        // Select the "Next →" button to get the right form (not nav logout form).
        $form = $crawler->selectButton('Next →')->form([
            'improve[details][correction]' => 'A work stand was added this spring.',
            'improve[lat]' => '50.426',
            'improve[lng]' => '6.027',
            'improve[place]' => 'Malmedy, Wallonia',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        // Real receipt: an Edit submission was persisted (SUB-<id>), still
        // pending curator review — never live instantly.
        self::assertSelectorTextContains('.receipt h2', 'Suggestion submitted.');
        self::assertSelectorTextContains('.receipt .stub-note', 'not yet persisted');
        self::assertSelectorTextContains('.receipt .ref', 'SUB-');
    }
}
