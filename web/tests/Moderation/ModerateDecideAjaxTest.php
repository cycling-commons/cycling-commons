<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ModerateDecideAjaxTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function login(KernelBrowser $client, string $email, array $roles, bool $curator): void
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Ajax User');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        if ($curator) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }

    public function testAjaxDecisionReturnsJsonStubReceipt(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-curator@example.com', ['ROLE_CURATOR'], true);

        // Grab the CSRF token from a rendered decision form (stateless, id "submit").
        $crawler = $client->request('GET', '/moderate');
        $token = (string) $crawler->filter('form.q-act-form input[name="moderation_decision[_token]"]')->first()->attr('value');

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => '1', 'decision' => 'approve', '_token' => $token]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{persisted:bool,reference:string,decision:string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($data['persisted']);
        self::assertStringStartsWith('CC-', $data['reference']);
        self::assertSame('approve', $data['decision']);
    }

    public function testAjaxDecisionForbiddenForPlainRider(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-rider@example.com', [], false);

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => '1', 'decision' => 'approve']],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseStatusCodeSame(403);
    }
}
