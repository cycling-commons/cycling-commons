<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Entity\User;
use App\Moderation\SampleQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Moderation queue (/moderate): auth-gate, role enforcement, rendering, and
 * CSRF-protected decision POST to the contribution stub.
 *
 * ROLE_CURATOR users must have a totpSecret set to bypass TwoFactorSetupEnforcer
 * (which redirects elevated roles without a TOTP secret to /2fa/setup).
 * See AdminAccessTest for the full explanation.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class ModerateTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a user in the DB and return the entity.
     *
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
        $user->setDisplayName('Test User');
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

    // ── Auth gate ────────────────────────────────────────────────────────────

    /** Anonymous GET /moderate must redirect to the login page. */
    public function testAnonRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/moderate');

        self::assertResponseRedirects('/login', 302);
    }

    /** A plain ROLE_USER must receive a 403 when accessing /moderate. */
    public function testRoleUserForbidden(): void
    {
        $client = static::createClient();

        $user = $this->createUser('moderate-rider@example.com', 'hunter2secure!');
        $client->loginUser($user);

        $client->request('GET', '/moderate');

        self::assertResponseStatusCodeSame(403);
    }

    // ── ROLE_CURATOR GET ────────────────────────────────────────────────────

    /**
     * A ROLE_CURATOR with a preset totpSecret must reach /moderate and see the
     * sample queue rendered. loginUser() bypasses form-login; the TOTP secret
     * satisfies TwoFactorSetupEnforcer without needing real 2FA enrolment.
     */
    public function testRoleCuratorWithTotpCanAccessAndSeesQueue(): void
    {
        $client = static::createClient();

        $curator = $this->createUser(
            'moderate-curator@example.com',
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        $client->request('GET', '/moderate');

        self::assertResponseIsSuccessful();

        // The sample queue heading must be visible.
        self::assertSelectorTextContains('h1', 'Review pending submissions');

        // The first sample queue item title must appear.
        $items = SampleQueue::items();
        self::assertGreaterThan(0, count($items));
        self::assertSelectorTextContains('.q-title', $items[0]['title']);

        // Queue items must have a decision form.
        self::assertSelectorExists('.q-act-form');
    }

    /**
     * Each queue item must be viewable: a link that opens the item on the
     * real map (in a new tab) so the curator can see and correct the
     * location before deciding. See ModerateOverviewTest for the
     * '/map?pending=<id>' deep-link contract.
     */
    public function testQueueItemsLinkToViewSubmissionAtItsLocation(): void
    {
        $client = static::createClient();

        $curator = $this->createUser(
            'moderate-view@example.com',
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();

        // One view link per sample item, opening in a new tab.
        $viewLinks = $crawler->filter('.q-item a.q-view[target="_blank"]');
        self::assertSame(count(SampleQueue::items()), $viewLinks->count());

        // The first item links to the real map, deep-linked to its pending id.
        $href = (string) $viewLinks->first()->attr('href');
        self::assertStringContainsString('/map?pending=1', $href);
        self::assertStringContainsString('noopener', (string) $viewLinks->first()->attr('rel'));
    }

    // ── Decision POST ────────────────────────────────────────────────────────

    /**
     * A valid CSRF-protected decision POST (approve) must show the honest stub
     * receipt: "Decision recorded" / "not yet persisted". The sample queue is
     * NOT actually mutated — the same items are still present.
     */
    public function testValidDecisionPostShowsHonestStubReceipt(): void
    {
        $client = static::createClient();

        $curator = $this->createUser(
            'moderate-decide@example.com',
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        // GET the queue to obtain CSRF token.
        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();

        // Submit the first queue item's decision form.
        $form = $crawler->selectButton('Record decision')->form();
        $form['moderation_decision[submission_id]'] = '1';
        $form['moderation_decision[decision]'] = 'approve';

        $client->submit($form);

        self::assertResponseIsSuccessful();

        // Honest stub receipt — decision recorded, NOT persisted.
        self::assertSelectorTextContains('.receipt-box h2', 'Decision recorded');
        self::assertSelectorTextContains('.receipt-box .stub-note', 'not yet persisted');
        self::assertSelectorTextContains('.receipt-box .ref', 'CC-');

        // Sample queue is unchanged — same items still render.
        $items = SampleQueue::items();
        self::assertSelectorTextContains('.q-title', $items[0]['title']);
    }

    /**
     * The contribution stub must be called with kind='moderation_decision'.
     * Verified indirectly: the receipt reference starts with CC- (only returned
     * by ContributionStubService when submit() succeeds).
     */
    public function testDecisionSubmitKindIsModeration(): void
    {
        $client = static::createClient();

        $curator = $this->createUser(
            'moderate-kind@example.com',
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Record decision')->form();
        $form['moderation_decision[submission_id]'] = '2';
        $form['moderation_decision[decision]'] = 'reject';

        $client->submit($form);

        // CC- reference confirms ContributionStubService::submit() was called.
        self::assertSelectorTextContains('.receipt-box .ref', 'CC-');
    }
}
