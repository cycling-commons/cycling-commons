<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator-side account removal, by email, for accounts that never asked for
 * it themselves: test riders left on a deployed database, probes, a duplicate.
 *
 * The same deletion a rider asks for. It goes through
 * {@see UserDeletionService::purge()}, so every pre-delete hook runs and
 * contributions are anonymised rather than cascaded (account-and-auth.md
 * §6.3). The admin desk offers no delete on purpose: an admin removes an
 * account only after the rider asked, and this command is the one exception,
 * for the operator at the console.
 *
 * Dry run by default. Refuses to remove the last ROLE_ADMIN, because that
 * locks every desk.
 *
 * @api
 */
#[AsCommand(name: 'app:user:purge', description: 'Remove accounts by email through the shared deletion seam (dry run unless --force)')]
final class PurgeUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserDeletionService $deletions,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'One or more account emails')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually remove the accounts. Without it, nothing changes.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        /** @var list<string> $emails */
        $emails = $input->getArgument('email');

        $targets = [];
        foreach ($emails as $email) {
            $user = $this->users->findOneBy(['email' => $email]);
            if (!$user instanceof User) {
                $io->error(\sprintf('No account with email %s. Nothing was removed.', $email));

                return Command::FAILURE;
            }
            $targets[] = $user;
        }

        $adminsGoing = \count(array_filter($targets, static fn (User $u): bool => \in_array('ROLE_ADMIN', $u->getRoles(), true)));
        if ($adminsGoing > 0 && $this->adminCount() - $adminsGoing < 1) {
            $io->error('That would remove the last ROLE_ADMIN. Nothing was removed.');

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($targets as $user) {
            $rows[] = [$user->getId(), $user->getEmail(), $user->getDisplayName(), implode(',', $user->getRoles()) ?: 'rider', $this->submissionCount($user)];
        }
        $io->table(['id', 'email', 'display name', 'roles', 'submissions kept'], $rows);

        if (!$force) {
            $io->note('Dry run: no account removed. Pass --force to act.');

            return Command::SUCCESS;
        }

        foreach ($targets as $user) {
            $this->deletions->purge($user);
        }
        $this->em->flush();

        $io->success(\sprintf('%d account(s) removed. Their submissions stay, under the anonymous former-contributor identity.', \count($targets)));

        return Command::SUCCESS;
    }

    private function adminCount(): int
    {
        return \count(array_filter(
            $this->users->findAll(),
            static fn (User $u): bool => \in_array('ROLE_ADMIN', $u->getRoles(), true),
        ));
    }

    private function submissionCount(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM submission WHERE user_id = :id',
            ['id' => $user->getId()],
        );
    }
}
