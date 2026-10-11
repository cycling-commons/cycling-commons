<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Service\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The photo-credit choice on the deletion form is stored only when the
 * deletion itself goes ahead: a wrong code changes nothing (audit 2026-10-04).
 *
 * @see docs/specs/photo-uploads.md §6
 */
final class DeleteConfirmCreditTest extends WebTestCase
{
    private function token(KernelBrowser $client, string $id): string
    {
        $client->request('GET', '/account/settings');
        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($client->getRequest());
        $token = static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($id)->getValue();
        $client->getRequest()->getSession()->save();
        $stack->pop();

        return $token;
    }

    public function testAWrongCodeLeavesTheCreditChoiceAlone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('credit-keep@example.test')->setDisplayName('Credit Keep');
        $user->setPassword('x');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setKeepMediaCredit(true);
        $em->persist($user);
        $em->flush();
        static::getContainer()->get(UserDeletionService::class)->requestDeletion($user);
        $client->loginUser($user);

        // The radio left at "no credit", with a wrong code.
        $client->request('POST', '/account/settings/delete-confirm', [
            '_token' => $this->token($client, 'delete_confirm'),
            'deletion_code' => 'WRONGCOD',
        ]);
        self::assertResponseRedirects();

        $em->clear();
        $stored = $em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $stored, 'the account is still there');
        self::assertTrue($stored->isKeepMediaCredit(), 'a refused code stores no choice');
    }
}
