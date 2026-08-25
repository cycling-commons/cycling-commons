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

    public function testCuratorWithTotpCanOpenDesk(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-queue-rider@example.com', 'hunter2secure!');
        $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte desk');

        $curator = $this->createUser(
            'mod-tr-curator@example.com',
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        $client->request('GET', '/moderate/translations');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('nav.map', $html);
        self::assertStringContainsString('Carte desk', $html);
    }

    public function testCuratorCanApproveWithCsrf(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-approve-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte csrf');
        $proposalId = (int) $proposal->getId();

        $curator = $this->createUser(
            'mod-tr-approve-curator@example.com',
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate/translations');
        self::assertResponseIsSuccessful();

        $token = (string) $crawler->filter('form input[name="translation_decision[_token]"]')->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', '/moderate/translations', [
            'translation_decision' => [
                '_token' => $token,
                'proposal_id' => (string) $proposalId,
                'decision' => 'approve',
                'note' => '',
            ],
        ]);

        self::assertResponseRedirects();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $decided = $em->find(TranslationProposal::class, $proposalId);
        self::assertNotNull($decided);
        self::assertSame(TranslationProposalStatus::Approved, $decided->getStatus());

        $overlay = $em->getRepository(TranslationOverlay::class)->findOneBy(['locale' => 'fr']);
        self::assertNotNull($overlay);
        self::assertSame('Carte csrf', $overlay->getValue());
    }
}
