<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
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

    /**
     * Seed a real pending submission row (queue is DB-backed —
     * SubmissionQueue). The submitter is a genuinely persisted user: a
     * successful decide() now also writes it a message, and
     * user_message.user_id carries a real DB FK to users(id).
     */
    private function seedSubmission(string $title, string $country = 'BE'): Submission
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
            ->setCountryCode($country)
            ->setChanges([])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();

        return $sub;
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
     * real submission queue rendered. loginUser() bypasses form-login; the TOTP
     * secret satisfies TwoFactorSetupEnforcer without needing real 2FA enrolment.
     */
    public function testRoleCuratorWithTotpCanAccessAndSeesQueue(): void
    {
        $client = static::createClient();
        $sub = $this->seedSubmission('Côte de la Vecquée');

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

        // The queue heading must be visible.
        self::assertSelectorTextContains('h1', 'Review pending submissions');

        // The seeded item's title must appear.
        self::assertSelectorTextContains('.q-title', $sub->getTitle());

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
        $sub = $this->seedSubmission('Repair station · Malmedy');

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

        // One view link per queued item, opening in a new tab.
        $viewLinks = $crawler->filter('.q-item a.q-view[target="_blank"]');
        self::assertSame(1, $viewLinks->count());

        // The item links to the real map, deep-linked to its pending id.
        $href = (string) $viewLinks->first()->attr('href');
        self::assertStringContainsString('/map?pending='.$sub->getId(), $href);
        self::assertStringContainsString('noopener', (string) $viewLinks->first()->attr('rel'));
    }

    // ── Decision POST ────────────────────────────────────────────────────────

    /**
     * §13 hardening: a valid CSRF-protected decision POST via the classic
     * (non-AJAX) form must redirect-after-POST back to the queue instead of
     * re-rendering inline (this replaced the old "receipt rendered on the
     * same page" behaviour — see ModerateDecideAjaxTest for the JSON receipt
     * contract, which is unaffected).
     */
    public function testValidDecisionPostRedirectsToQueue(): void
    {
        $client = static::createClient();
        $sub = $this->seedSubmission('Fountain · Spa centre');
        $other = $this->seedSubmission('Vaalserberg');

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
        $form['moderation_decision[submission_id]'] = (string) $sub->getId();
        $form['moderation_decision[decision]'] = 'approve';

        $client->submit($form);

        self::assertResponseRedirects('/moderate');

        // Following the redirect lands back on the (unfiltered) queue. The
        // decided submission is now Approved (ModerationService applied the
        // decision for real) so it has left the pending/needs-info queue;
        // the other, still-undecided submission remains visible.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('.q-title', $sub->getTitle());
        self::assertSelectorTextContains('.q-title', $other->getTitle());
    }

    /**
     * §13 hardening: the redirect target must preserve the curator's active
     * country/region/type filters, so a decision made from a filtered view
     * returns to that same filtered view rather than resetting it.
     */
    public function testDecisionRedirectPreservesActiveFilters(): void
    {
        $client = static::createClient();
        $sub = $this->seedSubmission('Vaalserberg', 'NL');

        $curator = $this->createUser(
            'moderate-filter-redirect@example.com',
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate?country=NL');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Record decision')->form();
        $form['moderation_decision[submission_id]'] = (string) $sub->getId();
        $form['moderation_decision[decision]'] = 'reject';

        $client->submit($form);

        self::assertResponseRedirects('/moderate?country=NL');
    }

    /**
     * #48: an INVALID decision submitted from a filtered view must re-render the
     * queue with the SAME filters (422), not reset to the unfiltered queue.
     */
    public function testInvalidDecisionReRenderPreservesFilters(): void
    {
        $client = static::createClient();
        $nl = $this->seedSubmission('Vaalserberg', 'NL');
        $this->seedSubmission('Mur de Huy', 'BE');

        $curator = $this->createUser(
            'moderate-invalid-filter@example.com',
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate?country=NL');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="moderation_decision[_token]"]')->first()->attr('value');

        // Post an out-of-range decision → the form is invalid.
        $client->request('POST', '/moderate/decide?country=NL', [
            'moderation_decision' => [
                'submission_id' => (string) $nl->getId(),
                'decision' => 'bogus-decision',
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Vaalserberg', $body, 'the NL-filtered queue is preserved');
        self::assertStringNotContainsString('Mur de Huy', $body, 'the BE item must not appear — filters were NOT reset');
    }
}
