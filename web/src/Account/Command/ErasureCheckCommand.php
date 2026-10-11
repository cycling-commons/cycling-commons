<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account\Command;

use App\Community\Entity\CountryInterest;
use App\Entity\User;
use App\Service\UserDeletionService;
use App\Support\ContactMessageRetention;
use App\Support\ContactTopic;
use App\Support\Entity\BugReport;
use App\Support\Entity\ContactMessage;
use App\Support\Entity\ContentReport;
use App\Support\ReportContactRetention;
use App\Support\ReportGround;
use App\Support\ReportTarget;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The running check that a deleted account's address goes, and when.
 *
 * Every day, on the real database: a throwaway account files what a rider
 * files (a contact message, a content report, a bug report, a country
 * request), the account is deleted through the same UserDeletionService a
 * rider's own deletion runs, and every text column of every table is searched
 * for the address. It may stay only where the privacy notice keeps it on its
 * own clock ({@see self::KEPT_ON_A_CLOCK}). Those rows are then moved past
 * their clocks and the real retention code runs, after which the address may
 * be nowhere at all. Any other hit names the table and column and fails the
 * run. The whole check runs inside one transaction that is always rolled
 * back, so nothing it wrote is ever seen, kept, mailed or shown on a desk.
 *
 * A failed run is not recorded as a good one, so the admin dashboard marks
 * the job late (operations.md, daily jobs), and the failure is logged as
 * critical.
 *
 * @see docs/specs/account-and-auth.md §6.3
 *
 * @api
 */
#[AsCommand(name: 'app:privacy:erasure-check', description: 'Check on the live database that a deleted account leaves its address only where a clock keeps it, and that the clocks remove it')]
final class ErasureCheckCommand extends Command
{
    /**
     * Tables left out of the search: large derived or reference data that
     * never holds an address a rider gave us (OpenStreetMap coverage,
     * traffic cells, boundaries, the world bundle), so scanning them daily
     * would cost much and find nothing.
     */
    private const array SKIP_TABLE_PREFIXES = ['coverage_', 'traffic_', 'world_', 'region', 'roadpiece', 'topology', 'doctrine_migration'];

    /**
     * Where a deleted account's address stays on purpose, until a clock
     * (privacy notice v3, owner 2026-10-11): a contact message for 24 months
     * after it closed, a content report's contact for 90 days after the
     * decision, so the reporter is told the outcome.
     */
    private const array KEPT_ON_A_CLOCK = ['contact_message.email', 'content_report.reporter_contact'];

    /** A domain that can never receive mail (RFC 2606). */
    private const string DOMAIN = 'erasure-check.invalid';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly UserDeletionService $deletion,
        private readonly ContactMessageRetention $contactRetention,
        private readonly ReportContactRetention $reportRetention,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = 'erasure-check-'.bin2hex(random_bytes(6)).'@'.self::DOMAIN;

        $this->db->beginTransaction();
        try {
            $this->fileAsARider($address);
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $address]);
            if (!$user instanceof User) {
                throw new \RuntimeException('The check account was not stored.');
            }
            $this->deletion->erase($user);
            $this->em->clear();

            // Right after the deletion: only where a clock keeps it.
            $hits = array_values(array_diff($this->search($address), self::KEPT_ON_A_CLOCK));

            // Then past those clocks, through the real retention code.
            $this->db->executeStatement(
                "UPDATE contact_message SET status = 'closed', updated_at = NOW() - INTERVAL '25 months' WHERE email = ?",
                [$address],
            );
            $this->db->executeStatement(
                "UPDATE content_report SET status = 'rejected', decided_at = NOW() - INTERVAL '91 days' WHERE reporter_contact = ?",
                [$address],
            );
            $this->contactRetention->purgeExpiredMessages();
            $this->reportRetention->purgeExpiredContacts();
            foreach ($this->search($address) as $kept) {
                $hits[] = $kept.' (past its clock)';
            }
        } finally {
            // Always: the check leaves nothing behind, pass or fail.
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->em->clear();
        }

        if ([] !== $hits) {
            $this->logger->critical('A deleted account left its address behind.', ['columns' => $hits]);
            $output->writeln('<error>A deleted account left its address in: '.implode(', ', $hits).'</error>');

            return Command::FAILURE;
        }

        $output->writeln('A deleted account left its address only where a clock keeps it, and the clocks removed it.');

        return Command::SUCCESS;
    }

    /** What a signed-in rider can file, each carrying the account's address. */
    private function fileAsARider(string $address): void
    {
        $user = (new User())->setEmail($address)->setDisplayName('Erasure check');
        $user->setPassword('!');
        $this->em->persist($user);
        $this->em->flush();
        $userId = (int) $user->getId();

        $message = new ContactMessage(ContactTopic::Question, $address, 'Erasure check.');
        $message->setUserId($userId);
        $message->setName('Erasure check');
        $this->em->persist($message);

        $report = new ContentReport(Uuid::v4(), ReportTarget::Item, '0', ReportGround::Untrue, 'Erasure check.', 'erasure-check', new \DateTimeImmutable());
        $report->setReporterContact($address);
        $this->em->persist($report);

        $bug = new BugReport('Erasure check', 'Erasure check.');
        $bug->setUserId($userId);
        $bug->setReporterEmail($address);
        $this->em->persist($bug);

        $this->em->persist(new CountryInterest($userId, 'DK'));
        $this->em->flush();
    }

    /**
     * Every text-like column of every table that may hold what a rider gave
     * us, searched for the address, case-insensitively and inside JSON too.
     *
     * @return list<string> `table.column` for each hit
     */
    private function search(string $address): array
    {
        /** @var list<array{table_name: string, column_name: string}> $columns */
        $columns = $this->db->fetchAllAssociative(
            "SELECT table_name, column_name FROM information_schema.columns
              WHERE table_schema = 'public'
                AND data_type IN ('character varying', 'text', 'character', 'json', 'jsonb')
              ORDER BY table_name, ordinal_position",
        );

        $byTable = [];
        foreach ($columns as $c) {
            foreach (self::SKIP_TABLE_PREFIXES as $prefix) {
                if (str_starts_with($c['table_name'], $prefix)) {
                    continue 2;
                }
            }
            $byTable[$c['table_name']][] = $c['column_name'];
        }

        $hits = [];
        $needle = '%'.addcslashes($address, '%_\\').'%';
        foreach ($byTable as $table => $cols) {
            foreach ($cols as $col) {
                $sql = \sprintf('SELECT 1 FROM %s WHERE CAST(%s AS text) ILIKE ? LIMIT 1', $this->db->quoteSingleIdentifier($table), $this->db->quoteSingleIdentifier($col));
                if (false !== $this->db->fetchOne($sql, [$needle])) {
                    $hits[] = $table.'.'.$col;
                }
            }
        }

        return $hits;
    }
}
