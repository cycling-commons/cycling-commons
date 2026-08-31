<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Messaging\MessageService;
use App\Translation\DecisionService;
use App\Translation\Exception\SelfReviewException;
use App\Translation\MarkerIndex;
use App\Translation\OverlayCatalogue;
use App\Translation\OverlayCatalogueLoader;
use App\Translation\ProposalService;
use App\Translation\StaleIndex;
use App\Translation\TranslationCaches;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Approve must invalidate the caches after the transaction commits, not inside it.
 */
final class DecisionServiceInvalidateOrderTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    public function testApproveInvalidatesAfterTransactionNotInside(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $overlaySpy = $this->phaseSpy();

        $rider = $this->user($em, 'inv-order-rider@test.test');
        $curator = $this->user($em, 'inv-order-curator@test.test', ['ROLE_CURATOR']);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $proposal = static::getContainer()->get(ProposalService::class)
            ->submit($rider, $entry, 'fr', 'Carte (post-commit invalidate)', true);

        $service = new DecisionService(
            $em,
            static::getContainer()->get(MessageService::class),
            $this->caches($overlaySpy),
        );
        $service->decide((int) $proposal->getId(), 'approve', $curator, null);

        // TranslationCaches::invalidateAll() invalidates the overlay map for
        // every overlay locale (translations.md §3.3), so the spy sees one
        // call per locale, none of them inside the transaction.
        self::assertNotEmpty($overlaySpy->phases);
        foreach ($overlaySpy->phases as $phase) {
            self::assertSame('after_commit', $phase);
        }

        // Live translator still sees the overlay (same contract as DecisionServiceTest).
        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');
        $t = static::getContainer()->get('translator');
        self::assertSame('Carte (post-commit invalidate)', $t->trans('nav.map', [], 'messages', 'fr'));
    }

    public function testThrownTransactionDoesNotInvalidate(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $overlaySpy = $this->phaseSpy();

        $curator = $this->user($em, 'inv-self@test.test', ['ROLE_CURATOR']);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $proposal = static::getContainer()->get(ProposalService::class)
            ->submit($curator, $entry, 'fr', 'Self carte', true);

        $service = new DecisionService(
            $em,
            static::getContainer()->get(MessageService::class),
            $this->caches($overlaySpy),
        );

        try {
            $service->decide((int) $proposal->getId(), 'approve', $curator, null);
            self::fail('Expected SelfReviewException');
        } catch (SelfReviewException) {
        }

        self::assertSame([], $overlaySpy->phases);
    }

    /**
     * @return OverlayCatalogue&object{phases: list<string>}
     */
    private function phaseSpy(): OverlayCatalogue
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        return new class(static::getContainer()->get(OverlayCatalogueLoader::class), static::getContainer()->get(CacheInterface::class), $conn) extends OverlayCatalogue {
            /** @var list<string> */
            public array $phases = [];

            public function __construct(
                OverlayCatalogueLoader $loader,
                CacheInterface $cache,
                private readonly Connection $conn,
            ) {
                parent::__construct($loader, $cache);
            }

            public function invalidate(string $locale): void
            {
                $this->phases[] = $this->conn->isTransactionActive() ? 'in_txn' : 'after_commit';
                parent::invalidate($locale);
            }
        };
    }

    /**
     * TranslationCaches is final, so the spy wraps its OverlayCatalogue
     * dependency instead of subclassing the whole thing.
     *
     * @param OverlayCatalogue&object{phases: list<string>} $overlaySpy
     */
    private function caches(OverlayCatalogue $overlaySpy): TranslationCaches
    {
        return new TranslationCaches(
            $overlaySpy,
            static::getContainer()->get(StaleIndex::class),
            static::getContainer()->get(MarkerIndex::class),
        );
    }

    /**
     * @param list<string> $roles
     */
    private function user(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $u = (new User())->setEmail($email)->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setPassword('x');
        $u->setRoles($roles);
        $em->persist($u);
        $em->flush();

        return $u;
    }
}
