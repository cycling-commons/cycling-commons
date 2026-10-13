<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\Partner\Command;

use App\Api\Partner\ApiApps;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Register and manage the partner apps that may write through the public
 * API (docs/specs/public-api.md §8). An operator's tool: an app writes only
 * under a partner agreement, so there is no self-serve page for it.
 *
 *     app:api-app register "Example Rides" dev@example.org
 *     app:api-app list
 *     app:api-app rotate 3
 *     app:api-app suspend 3      (and: resume 3)
 *
 * The key is printed once, by `register` and `rotate`, and stored only as
 * its SHA-256.
 *
 * @api
 */
#[AsCommand(
    name: 'app:api-app',
    description: 'Register, list, rotate, suspend or resume the partner apps that write through the public API',
)]
final class ApiAppCommand extends Command
{
    public function __construct(private readonly ApiApps $apps)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'register | list | rotate | suspend | resume')
            ->addArgument('subject', InputArgument::OPTIONAL, 'register: the app\'s name; rotate, suspend, resume: its id')
            ->addArgument('email', InputArgument::OPTIONAL, 'register: the address we reach the app\'s operator at');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $subject = trim((string) $input->getArgument('subject'));

        switch ($action) {
            case 'register':
                $email = trim((string) $input->getArgument('email'));
                if ('' === $subject || mb_strlen($subject) > 120 || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                    $io->error('register needs the app\'s name (at most 120 characters) and a valid contact address.');

                    return Command::INVALID;
                }
                $issued = $this->apps->register($subject, $email, [ApiApps::SCOPE_WRITE]);
                $io->success(\sprintf('Registered app %d, "%s".', $issued['id'], $subject));
                $io->writeln('Key (shown once, store it now; we keep only its hash):');
                $io->writeln($issued['key']);

                return Command::SUCCESS;

            case 'list':
                $rows = array_map(static fn (array $r): array => [
                    $r['id'], $r['name'], $r['contact_email'], $r['key_prefix'].'…', $r['scopes'], $r['status'], $r['created_at'], $r['last_used_at'] ?? '-',
                ], $this->apps->all());
                $io->table(['id', 'name', 'contact', 'key', 'scopes', 'status', 'registered', 'last used'], $rows);

                return Command::SUCCESS;

            case 'rotate':
                $key = ctype_digit($subject) ? $this->apps->rotate((int) $subject) : null;
                if (null === $key) {
                    $io->error('rotate needs the id of a registered app (see: app:api-app list).');

                    return Command::FAILURE;
                }
                $io->success(\sprintf('App %s has a new key; the old one no longer works.', $subject));
                $io->writeln($key);

                return Command::SUCCESS;

            case 'suspend':
            case 'resume':
                if (!ctype_digit($subject) || !$this->apps->setStatus((int) $subject, 'suspend' === $action ? 'suspended' : 'active')) {
                    $io->error(\sprintf('%s needs the id of a registered app (see: app:api-app list).', $action));

                    return Command::FAILURE;
                }
                $io->success(\sprintf('App %s is %s.', $subject, 'suspend' === $action ? 'suspended: its key answers 401' : 'active again'));

                return Command::SUCCESS;
        }

        $io->error('action must be register, list, rotate, suspend or resume.');

        return Command::INVALID;
    }
}
