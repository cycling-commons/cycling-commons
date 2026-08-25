<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Command;

use App\Doctrine\EncryptedStringType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports whether the running encryption key can still read what is stored.
 *
 * Exists because the failure it detects is otherwise completely silent. TOTP
 * secrets are encrypted with a key derived from ENCRYPTION_SECRET (falling back
 * to APP_SECRET), and a value encrypted under a different key hydrates as null
 * on purpose, so that a mis-set variable cannot 500 every login. The cost of
 * that kindness is that the symptom of a wrong key is "my authenticator app
 * stopped working" reported by one curator at a time, with nothing in the logs
 * (security scan 2026-08-25).
 *
 * Run it after any deploy that touches either variable, and before rotating
 * APP_SECRET. Non-zero exit when anything is unreadable, so a deploy script can
 * gate on it.
 *
 * @api
 */
#[AsCommand(
    name: 'app:security:encryption-audit',
    description: 'Check that the current encryption key can still read every encrypted column',
)]
final class EncryptionAuditCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<array{id: int|string, email: string, totp_secret: string|null, two_fa_enabled: bool|string|int}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, email, totp_secret, two_fa_enabled FROM users WHERE totp_secret IS NOT NULL ORDER BY id',
        );

        if ([] === $rows) {
            $io->success('No encrypted TOTP secrets stored. Nothing to check.');

            return Command::SUCCESS;
        }

        $unreadable = [];
        foreach ($rows as $row) {
            if (EncryptedStringType::isUnreadable($row['totp_secret'])) {
                $unreadable[] = $row;
            }
        }

        $io->writeln(sprintf('%d stored TOTP secret(s); %d unreadable.', \count($rows), \count($unreadable)));

        if ([] === $unreadable) {
            $io->success('The current key reads every stored secret.');

            return Command::SUCCESS;
        }

        // Emails, not just counts: whoever runs this has to go and tell these
        // people to re-enrol, and an admin already sees every address on the
        // support desk.
        $io->table(
            ['id', 'email', '2FA enabled'],
            array_map(
                static fn (array $r): array => [
                    (string) $r['id'],
                    $r['email'],
                    filter_var($r['two_fa_enabled'], \FILTER_VALIDATE_BOOL) ? 'yes' : 'no',
                ],
                $unreadable,
            ),
        );
        $io->error(sprintf(
            '%d TOTP secret(s) cannot be decrypted by the running key. Those accounts cannot complete 2FA.',
            \count($unreadable),
        ));
        $io->writeln('Most likely ENCRYPTION_SECRET (or APP_SECRET, which it falls back to) changed.');
        $io->writeln('Restore the previous value, or reset those accounts\' 2FA from the admin desk so they can re-enrol.');

        return Command::FAILURE;
    }
}
