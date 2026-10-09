<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use App\Service\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The real deletion seam with one more hook after every real one, which makes
 * the erasure of chosen accounts fail once all real hooks have written:
 * `throw` throws from the hook, `flush` leaves a row that makes the final
 * DELETE of the account fail inside the flush (the EntityManager closes).
 *
 * @internal
 */
final class SabotagedErasure extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<int, 'throw'|'flush'> $plan user id => how its erasure fails */
    public static function deletions(ContainerInterface $c, array $plan, ?self $logger = null): UserDeletionService
    {
        $real = $c->get(UserDeletionService::class);
        /** @var iterable<UserDeletionHookInterface> $hooks */
        $hooks = (new \ReflectionProperty(UserDeletionService::class, 'hooks'))->getValue($real);
        $db = $c->get(Connection::class);

        $saboteur = new class($db, $plan) implements UserDeletionHookInterface {
            /** @param array<int, 'throw'|'flush'> $plan */
            public function __construct(private readonly Connection $db, private readonly array $plan)
            {
            }

            #[\Override]
            public function preDelete(User $user): void
            {
                $how = $this->plan[(int) $user->getId()] ?? null;
                if ('throw' === $how) {
                    throw new \RuntimeException('hook failed midway');
                }
                if ('flush' === $how) {
                    $this->db->executeStatement(
                        "INSERT INTO reset_password_request (selector, hashed_token, requested_at, expires_at, user_id) VALUES ('sabotage', 'x', NOW(), NOW(), :u)",
                        ['u' => $user->getId()],
                    );
                }
            }
        };

        return new UserDeletionService(
            $c->get(EntityManagerInterface::class),
            $c->get(MailerInterface::class),
            [...$hooks, $saboteur],
            $logger ?? new self(),
            $c->get(ManagerRegistry::class),
        );
    }

    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
