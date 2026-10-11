<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * A malformed id is "not found", never a server error (audit 2026-10-04).
 */
final class MalformedUuidTest extends WebTestCase
{
    private function user(array $roles): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('uuid-'.implode('-', $roles ?: ['rider']).'@example.test')->setDisplayName('Uuid Test');
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles($roles);
        $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testTheReportAnswerPageAnswersNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user([]));

        // 36 characters of the route's class, but no uuid.
        $client->request('GET', '/report/'.str_repeat('a', 36).'/answer');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPhotoEscalationAnswersNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user(['ROLE_CURATOR']));
        // A page first, so the session the token lives in exists; the token
        // is minted into that session and saved, as a rendered form would.
        $client->request('GET', '/moderate/submissions');
        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($client->getRequest());
        $token = static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('moderate_escalate')->getValue();
        $client->getRequest()->getSession()->save();
        $stack->pop();

        $client->request('POST', '/moderate/escalate', ['_token' => $token, 'media' => 'not-a-uuid', 'reason' => 'x']);

        self::assertResponseStatusCodeSame(404);
    }
}
