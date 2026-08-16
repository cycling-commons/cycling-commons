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
    private function createItem(string $letter, array $attributes = [], string $name = 'Test place', ItemState $state = ItemState::Unverified): Item
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $item = (new Item())->setLetter($letter)->setName($name)
            ->setGeom('{"type":"Point","coordinates":[6.027,50.426]}')->setCountryCode('BE')
            ->setState($state)->setSource(ItemSource::Osm)
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

    /**
     * #38: the wizard eyebrow/label are translated on a localized improve page,
     * not rendered as hardcoded English.
     */
    public function testTypeLabelIsLocalizedOnFrenchImprovePage(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'fr-label');
        $item = $this->createItem('D', name: 'Un endroit');

        $client->request('GET', '/fr/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        // FR eyebrow ("Améliorer ce lieu") + FR label ("Services vélo") — never
        // the English "Improve this place" / "Bike services".
        self::assertStringContainsString('Améliorer ce lieu', $body);
        self::assertStringContainsString('Services vélo', $body);
        self::assertStringNotContainsString('Improve this place', $body);
    }

    /**
     * #11: /improve?item= must only bind items in a publicly-served state
     * (unverified/verified). A 'submitted' item (another rider's un-moderated
     * contribution) or a rejected/retired one must be treated as unbound —
     * never prefilling the form with un-vetted data.
     */
    public function testSubmittedItemIsTreatedAsUnbound(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'state-leak');
        $item = $this->createItem('D', ['correction' => 'secret pending note'], name: 'Unvetted place', state: ItemState::Submitted);

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-improve-unbound]');
        self::assertSelectorNotExists('form[name="improve"]');
        self::assertStringNotContainsString('Unvetted place', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('secret pending note', (string) $client->getResponse()->getContent());
    }

    public function testRejectedItemIsTreatedAsUnbound(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'state-rej');
        $item = $this->createItem('D', name: 'Rejected place', state: ItemState::Rejected);

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-improve-unbound]');
        self::assertStringNotContainsString('Rejected place', (string) $client->getResponse()->getContent());
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

    /**
     * Frontend review 2026-07-12 (critical C6): the segment branch of the
     * wizard's syncLoc stored the two drawn endpoints only in memory — the
     * form POSTed with no segment data while the UI toasted "submit to
     * record it". The form must expose a hidden `segment` field for
     * segment-located types so the drawn endpoints reach the submission
     * payload (same parity the point branch has via lat/lng).
     */
    public function testRoadSurfaceFormExposesSegmentHiddenField(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'seg-field');
        $item = $this->createItem('A');

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="improve[segment]"]');
    }

    /** Point-located types never render the segment carrier. */
    public function testPointTypeHasNoSegmentField(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'seg-none');
        $item = $this->createItem('D');

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[name="improve[segment]"]');
    }

    /**
     * The place-search box can read a coordinate pair copied off the map
     * (right-click there → "lat, lng"). Pasting one used to reach Photon, come
     * back "No matches" and leave the map on its default centre — which reads
     * as the search sending you to the wrong country. The parser is a separate
     * script, so what breaks silently is the WIRING: this asserts the page
     * still ships coords.js and both result-row strings.
     */
    public function testImprovePageShipsTheCoordinatePasteWiring(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'coords');
        $item = $this->createItem('D');

        $crawler = $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('script[src*="contribute/coords"]')->count(),
            'coords.js must load before improve.js reads window.Cc.parseLatLng',
        );
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('search_coords_place', $html);
        self::assertStringContainsString('search_coords_go', $html);
    }

    /** The drawn segment endpoints round-trip into the persisted submission payload. */
    public function testSegmentPostLandsInSubmissionPayload(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'seg-post');
        $item = $this->createItem('A', ['surface' => 'asphalt'], name: 'Rue de Test');

        $crawler = $client->request('GET', '/improve?item='.$item->getId());
        self::assertResponseIsSuccessful();

        $segment = '{"a":[5.8601,50.4901],"b":[5.8702,50.4952]}';
        $form = $crawler->selectButton('Next →')->form([
            'improve[details][surface]' => 'Gravel',
            'improve[segment]' => $segment,
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.receipt .ref', 'SUB-');

        /** @var \App\Catalog\Entity\Submission $submission */
        $submission = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(\App\Catalog\Entity\Submission::class)
            ->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($submission);
        self::assertSame($segment, $submission->getPayload()['segment'] ?? null, 'the drawn segment must be recorded in the submission payload');
    }

    /**
     * A rider editing an item can fix its name.
     *
     * Several field sets — water & food among them — carry no name field, so
     * editing offered no way to change it at all: a mis-tagged OSM tap kept
     * whatever name it arrived with. The name is not an attribute, so it must
     * ride as a CHANGE and never reach the attribute vocabulary (which has no
     * `name` key for any letter, and would reject the whole edit).
     */
    public function testEditingAnItemCanChangeItsName(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'rename');
        $item = $this->createItem('C', ['potable' => 'Yes (public supply)'], name: 'Waterpunt');

        $crawler = $client->request('GET', '/improve?item='.$item->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Next →')->form();
        self::assertSame('Waterpunt', $form['improve[details][name]']->getValue(), 'the current name is offered, prefilled');
        $form['improve[details][name]'] = 'Waterpunt Geestmerambacht';
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $submission = $this->latestSubmission();
        self::assertArrayHasKey('name', $submission->getChanges());
        self::assertSame(
            ['was' => 'Waterpunt', 'now' => 'Waterpunt Geestmerambacht'],
            $submission->getChanges()['name'],
        );
        self::assertArrayNotHasKey('name', $submission->getPayload()['attributes'] ?? [], 'the name is never an attribute');
    }

    /**
     * An emptied name box means "leave the name alone". Clearing a place's
     * name is not something an edit form should be able to do by omission —
     * an unnamed point must stay editable for its other fields.
     */
    public function testAnEmptiedNameLeavesTheNameAlone(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'rename-empty');
        $item = $this->createItem('C', ['potable' => 'Yes (public supply)'], name: 'Waterpunt');

        $crawler = $client->request('GET', '/improve?item='.$item->getId());
        $form = $crawler->selectButton('Next →')->form();
        $form['improve[details][name]'] = '';
        $form['improve[details][potable]'] = 'No / non-potable';
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $changes = $this->latestSubmission()->getChanges();
        self::assertArrayNotHasKey('name', $changes, 'an empty box is not a rename to nothing');
        self::assertArrayHasKey('potable', $changes, 'the rest of the edit still lands');
    }

    private function latestSubmission(): \App\Catalog\Entity\Submission
    {
        /** @var \App\Catalog\Entity\Submission|null $submission */
        $submission = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(\App\Catalog\Entity\Submission::class)
            ->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($submission);

        return $submission;
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
        // Climbs (B) can be voted on, and the wizard says so. Asserted on what
        // a rider is actually told — "vote" — rather than on the word "votable",
        // which is the schema's vocabulary and was never meant to reach the page.
        // One paragraph now carries both halves (the funnel and what this type
        // means for the rider); the second one repeated the first.
        self::assertSelectorTextContains('.lc-funnel', 'voted on');
        self::assertSelectorCount(0, '.lc-verdict');
    }

    public function testUtilityTypeSaysItIsNeverVotedOn(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'utility');
        $item = $this->createItem('C');

        $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        // Water & food (C) is a utility type. The rider is told what that MEANS
        // for them — nobody votes on it, one rider's confirmation promotes it —
        // and never the word "utility" or "coverage", which are ours, not theirs.
        self::assertSelectorTextContains('.lc-funnel', 'they never vote on them');
        self::assertSelectorTextNotContains('.lc-funnel', 'coverage');
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
        // not a valid binding — it must never 400/500. Since the add flow's
        // restoration (AddPlaceFlowTest), `mode=add` + a valid type wins over
        // the junk item param: the link degrades to a fresh ADD wizard for
        // the right type, not the dead-end explainer.
        $client->request('GET', '/improve?type=water-food&item=water-fountain&mode=add');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-improve-unbound]');
        self::assertSelectorExists('form[name="improve"]');
        self::assertSelectorExists('input[name="improve[details][name]"]', 'the add wizard, not a bound edit');
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
        self::assertSelectorTextContains('.receipt p', 'curator queue');
        self::assertSelectorTextContains('.receipt .ref', 'SUB-');
        // The old amber note said suggestions were "not yet persisted" while
        // this very assertion reads the persisted row's id off the page.
        self::assertSelectorNotExists('.receipt .stub-note');
    }

    /**
     * Security review 2026-07-07 (critical #3): a stay's `web` field is
     * user-editable and its value is interpolated into an `<a href>` on the
     * public map. A `javascript:` (or any non-http) scheme must be rejected by
     * validation — the submission never persists, so the payload can never
     * reach the map. End-to-end reproduction of the closed XSS path.
     */
    public function testJavascriptSchemeInWebFieldIsRejectedAndNeverPersisted(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'xss');
        $item = $this->createItem('E', ['web' => 'https://old.example.test'], name: 'Gîte XSS');

        $crawler = $client->request('GET', '/improve?item='.$item->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Next →')->form([
            'improve[details][web]' => 'javascript:alert(document.cookie)',
            'improve[lat]' => '50.45',
            'improve[lng]' => '5.62',
            'improve[place]' => 'Spa, Wallonia',
        ]);
        $client->submit($form);

        // Symfony re-renders an invalid form as 422 — the point is that NOTHING
        // is persisted: no receipt, no Submission row.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('.receipt', 'a rejected malicious URL must never produce a receipt');

        $submissions = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(\App\Catalog\Entity\Submission::class)
            ->count([]);
        self::assertSame(0, $submissions, 'a javascript: URL must be blocked before any Submission is created');
    }

    /** The counterpart: a well-formed https URL passes and persists. */
    public function testHttpsUrlInWebFieldIsAcceptedAndPersisted(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'xss-ok');
        $item = $this->createItem('E', ['web' => 'https://old.example.test'], name: 'Gîte OK');

        $crawler = $client->request('GET', '/improve?item='.$item->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Next →')->form([
            'improve[details][web]' => 'https://new.example.test',
            'improve[lat]' => '50.45',
            'improve[lng]' => '5.62',
            'improve[place]' => 'Spa, Wallonia',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.receipt .ref', 'SUB-');
    }

    /**
     * #12 revisited (owner 2026-08-16): there is no photo-URL field any more.
     *
     * The original test pinned that a `javascript:` scheme in `photoUrl` was
     * rejected by validation. The field itself is now gone - it promised a
     * licence check nothing performed, offered hosts the CSP cannot display,
     * and its value was discarded after being counted as "you changed
     * something". So the pin moves up a level: the input must not be on the
     * page at all, which is the only version of this that cannot be bypassed.
     *
     * The service-side belt is pinned separately, in
     * CatalogContributionServiceTest: a payload carrying `photoUrl` has the
     * key dropped before anything reads it.
     */
    public function testTheWizardOffersNoPhotoUrlFieldAtAll(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'photo-xss');
        $item = $this->createItem('D', ['correction' => 'Nothing yet.'], name: 'Repair point');

        $client->request('GET', '/improve?item='.$item->getId());
        self::assertResponseIsSuccessful();

        self::assertSelectorNotExists('input[name="improve[photoUrl]"]');
        // The Link button and its source note went with it - a control for a
        // field that does not exist reads as broken, not absent.
        self::assertSelectorNotExists('#btn-link-photo');
        self::assertSelectorNotExists('#lnk-photo');
    }
}
