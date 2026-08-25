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
 * @api
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
            ->addArgument('password', InputArgument::OPTIONAL, 'The plain-text password. OMIT IT: you are then prompted with the input hidden, and it never reaches your shell history or the process list')
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
        } else {
            // Kept, not removed: seeding a fixture from a script is a real use
            // and a prompt cannot be answered by one. But a password given here
            // is now in the shell history and was visible in `ps` output to
            // every other user on the box while this ran, and whoever typed it
            // is unlikely to have thought about that (security scan
            // 2026-08-25). Say so, once, where it cannot be missed.
            $io->warning(
                'The password was passed as an argument, so it is in your shell history '
                .'and was visible in the process list while this command ran. '
                .'Omit it to be prompted instead, and treat this one as compromised if the machine is shared.',
            );
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
            $io->error(sprintf('An account with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $io->success(sprintf('User "%s" created with role(s): %s', $email, implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
