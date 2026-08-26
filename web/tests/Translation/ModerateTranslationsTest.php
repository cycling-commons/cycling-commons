<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\ProposalService;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Curator /moderate/translations desk (translations.md §5).
 *
 * The queue is a scan; decisions live on /moderate/translations/{id}.
 *
 * ROLE_CURATOR users need totpSecret so TwoFactorSetupEnforcer does not redirect.
 */
final class ModerateTranslationsTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

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

    private function loginCurator(string $email): User
    {
        $client = static::getClient();
        $curator = $this->createUser(
            $email,
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        return $curator;
    }

    private function seedPendingProposal(User $rider, string $key, string $english, string $locale, string $value): TranslationProposal
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, $key, $english);

        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);

        return $proposals->submit($rider, $entry, $locale, $value, true);
    }

    public function testRiderForbiddenOnModerateTranslations(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-rider@example.com', 'hunter2secure!');
        $client->loginUser($rider);

        $client->request('GET', '/moderate/translations');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRiderForbiddenOnDetail(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-detail-rider@example.com', 'hunter2secure!');
        $client->loginUser($rider);

        $client->request('GET', '/moderate/translations/1');

        self::assertResponseStatusCodeSame(403);
    }

    public function testQueueIsAScanWithReviewLink(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-queue-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte desk');
        $id = (int) $proposal->getId();
        $this->loginCurator('mod-tr-curator@example.com');

        $crawler = $client->request('GET', '/moderate/translations');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('nav.map', $html);
        self::assertStringNotContainsString('Carte desk', $html);
        self::assertSame(0, $crawler->filter('input[name="translation_decision[_token]"]')->count());
        self::assertSame(
            1,
            $crawler->filter(sprintf('a.q-review[href$="/moderate/translations/%d"]', $id))->count(),
        );
    }

    public function testDetailShowsEnglishAndProposed(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-show-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte detail visible');
        $id = (int) $proposal->getId();
        $this->loginCurator('mod-tr-show-curator@example.com');

        $client->request('GET', '/moderate/translations/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'nav.map');
        self::assertSelectorTextContains('.q-diff', 'Map');
        self::assertSelectorTextContains('.q-diff', 'Carte detail visible');
        self::assertSelectorExists('input[name="translation_decision[_token]"]');
        self::assertSelectorExists('button[value="approve"]');
    }

    public function testCuratorCanApproveFromDetail(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-approve-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte csrf');
        $proposalId = (int) $proposal->getId();
        $this->loginCurator('mod-tr-approve-curator@example.com');

        $crawler = $client->request('GET', '/moderate/translations/'.$proposalId);
        self::assertResponseIsSuccessful();

        $token = (string) $crawler->filter('form input[name="translation_decision[_token]"]')->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', '/moderate/translations/'.$proposalId, [
            'translation_decision' => [
                '_token' => $token,
                'proposal_id' => (string) $proposalId,
                'decision' => 'approve',
                'note' => '',
            ],
        ]);

        self::assertResponseRedirects('/moderate/translations');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $decided = $em->find(TranslationProposal::class, $proposalId);
        self::assertNotNull($decided);
        self::assertSame(TranslationProposalStatus::Approved, $decided->getStatus());

        $overlay = $em->getRepository(TranslationOverlay::class)->findOneBy([
            'locale' => 'fr',
            'sourceProposal' => $decided,
        ]);
        self::assertNotNull($overlay);
        self::assertSame('Carte csrf', $overlay->getValue());
    }

    public function testNeedsInfoCardShowsStatus(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-badge-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte badge');
        $proposal->setStatus(TranslationProposalStatus::NeedsInfo);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->loginCurator('mod-tr-badge-curator@example.com');

        $client->request('GET', '/moderate/translations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tr-status', 'Needs info');
    }

    public function testUnknownProposalIsNotFound(): void
    {
        $client = static::createClient();
        $this->loginCurator('mod-tr-unknown-curator@example.com');

        $client->request('GET', '/moderate/translations/999999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testQueueDoesNotAcceptDecisions(): void
    {
        $client = static::createClient();
        $this->loginCurator('mod-tr-list-post-curator@example.com');

        $client->request('POST', '/moderate/translations', [
            'translation_decision' => [
                'proposal_id' => '1',
                'decision' => 'approve',
                'note' => '',
            ],
        ]);

        self::assertResponseStatusCodeSame(405);
    }
}
