<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Legal\Command;

use App\Legal\LegalNotice;
use App\Legal\LegalVersions;
use App\Legal\PrivacyNoticeVersions;
use App\Legal\TermsVersions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Email every account about a significant version of the privacy notice or
 * the terms (not an unconfirmed address that never signed in, LegalNotice). Refuses a version that is not significant,
 * applies in fewer than LegalVersions::NOTICE_DAYS days, or has no
 * translations/<page>_next.<locale>.yaml in every language for the email to
 * link. Run it again after an interruption, or after the mail server refused
 * some addresses, and it mails only the accounts not mailed yet.
 *
 * @see docs/specs/privacy-notice.md
 *
 * @api
 */
#[AsCommand(name: 'app:legal:announce', description: 'Email every account about a significant change to the privacy notice or the terms')]
final class AnnounceLegalChangeCommand extends Command
{
    /** @var array<string, class-string<LegalVersions>> */
    private const array PAGES = ['privacy' => PrivacyNoticeVersions::class, 'terms' => TermsVersions::class];

    public function __construct(private readonly LegalNotice $notice, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('page', InputArgument::REQUIRED, 'privacy or terms')
            ->addArgument('version', InputArgument::REQUIRED, 'The version number to announce');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = (string) $input->getArgument('page');
        $versions = self::PAGES[$page] ?? null;
        if (null === $versions) {
            $io->error('The page is privacy or terms.');

            return Command::FAILURE;
        }
        $number = (int) $input->getArgument('version');
        $version = array_values(array_filter($versions::all(), static fn (array $v): bool => $v['number'] === $number))[0] ?? null;
        if (null === $version) {
            $io->error(\sprintf('%s has no version %d.', $page, $number));

            return Command::FAILURE;
        }
        if ('privacy' !== $page && 'terms' !== $page) {
            return Command::FAILURE;
        }

        try {
            $result = $this->notice->announce($page, $version, $this->clock->now());
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $mailed = \sprintf('%d account(s) mailed about %s version %d, which applies on %s.', $result['sent'], $page, $number, $version['effective']);
        if ($result['failed'] > 0) {
            $io->warning(\sprintf('%s The mail server refused %d account(s); the log names them by id. Run the command again to retry only those.', $mailed, $result['failed']));

            return Command::FAILURE;
        }
        $io->success($mailed);

        return Command::SUCCESS;
    }
}
