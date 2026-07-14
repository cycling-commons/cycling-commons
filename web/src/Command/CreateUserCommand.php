<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Command;

use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @api Bootstrap CLI command — wired by Symfony's DI; never referenced from code.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Create a new user account (bootstraps the first admin/curator).',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The user\'s email address')
            ->addArgument('password', InputArgument::OPTIONAL, 'The plain-text password — omit to be prompted securely (hashed on creation)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Extra role: ROLE_ADMIN or ROLE_CURATOR (ROLE_USER is always granted)', 'ROLE_USER')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Display name (defaults to the email local part)', null)
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string|null $plain */
        $plain = $input->getArgument('password');
        if (null === $plain || '' === $plain) {
            $plain = $io->askHidden('Password (input hidden)');
            if (null === $plain || '' === $plain) {
                $io->error('Password cannot be empty.');

                return Command::FAILURE;
            }
        }
        /** @var string $role */
        $role = $input->getOption('role');
        /** @var string|null $displayName */
        $displayName = $input->getOption('display-name');

        $allowedRoles = ['ROLE_USER', 'ROLE_CURATOR', 'ROLE_ADMIN'];
        if (!in_array($role, $allowedRoles, true)) {
            $io->error(sprintf('Invalid role "%s". Allowed: %s', $role, implode(', ', $allowedRoles)));

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName ?? strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());

        $roles = 'ROLE_USER' === $role ? [] : [$role];
        $user->setRoles($roles);

        $hashed = $this->hasher->hashPassword($user, $plain);
        $user->setPassword($hashed);

        try {
            $this->em->persist($user);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // The unique canonical-display-name index rejects this — most likely
            // because the default (email local part) collides with an existing
            // user. Surface a friendly message instead of the raw DBAL stack trace.
            $io->error(sprintf(
                'Display name "%s" is already taken. Pass --display-name to choose a different one.',
                $user->getDisplayName(),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf('User "%s" created with role(s): %s', $email, implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
