<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Translation\DecisionService;
use App\Translation\ProposalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rider /translate/mine ledger (translations.md §4).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class TranslateMineTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = []): User
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testAnonIsRedirectedFromMine(): void
    {
        $client = static::createClient();
        $client->request('GET', '/translate/mine');

        self::assertResponseRedirects('/login', 302);
    }

    public function testEmptyStatePointsAtTheCatalogue(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-empty@example.com');
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/translate/mine');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.empty-state');
        self::assertSame(0, $crawler->filter('.q-item')->count());
        self::assertSame(0, $crawler->filter('a.tr-to-cat')->count());
        self::assertSame(1, $crawler->filter('.mh .lead')->count());
        $html = (string) $crawler->filter('#main')->html();
        $h1 = strpos($html, '<h1');
        $lead = strpos($html, 'class="lead"');
        $chips = strpos($html, 'class="lfilter"');
        self::assertNotFalse($h1);
        self::assertNotFalse($lead);
        self::assertNotFalse($chips);
        self::assertLessThan($lead, $h1, 'the lead belongs under the title, not under the chips');
        self::assertLessThan($chips, $lead);
    }

    public function testOwnPendingAppearsAndOthersDoNot(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-own@example.com');
        $other = $this->createUser('mine-other@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $proposals->submit($rider, $entry, 'fr', 'Carte mine own', true);
        $proposals->submit($other, $entry, 'nl', 'Kaart someone else', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/translate/mine');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.q-item', 'nav.map');
        self::assertSelectorTextContains('.q-item', 'Carte mine own');
        self::assertSelectorTextNotContains('.q-list', 'Kaart someone else');
        $href = (string) $crawler->filter('.q-item a.tr-continue')->attr('href');
        self::assertStringContainsString('/fr/translate/'.$entry->getId(), $href);
    }

    public function testRejectedShowsNoteAndApprovedShowsCuratorEdit(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-decided@example.com');
        $curator = $this->createUser('mine-curator@example.com', ['ROLE_CURATOR']);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $map = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $home = $this->findOrCreateEntry($em, 'nav.home', 'Home');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $rejected = $proposals->submit($rider, $map, 'fr', 'Carte refusee', true);
        $approved = $proposals->submit($rider, $home, 'de', 'Startseita', true);
        /** @var DecisionService $decisions */
        $decisions = static::getContainer()->get(DecisionService::class);
        $decisions->decide((int) $rejected->getId(), 'reject', $curator, 'Not idiomatic.');
        $decisions->decide((int) $approved->getId(), 'approve', $curator, null, 'Startseite');
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/translate/mine');

        self::assertResponseIsSuccessful();
        $html = (string) $crawler->filter('.q-list')->html();
        self::assertStringContainsString('Carte refusee', $html);
        self::assertStringContainsString('Not idiomatic.', $html);
        self::assertStringContainsString('Startseita', $html);
        self::assertStringContainsString('Startseite', $html);
        self::assertSame(0, $crawler->filter('.q-item a.tr-continue')->count());
    }

    public function testStatusFilterHidesOtherRows(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-filter@example.com');
        $curator = $this->createUser('mine-filter-curator@example.com', ['ROLE_CURATOR']);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $map = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $home = $this->findOrCreateEntry($em, 'nav.home', 'Home');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $proposals->submit($rider, $map, 'es', 'Mapa pendiente', true);
        $done = $proposals->submit($rider, $home, 'es', 'Inicio', true);
        static::getContainer()->get(DecisionService::class)
            ->decide((int) $done->getId(), 'approve', $curator, null);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/es/translate/mine?status=approved');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.q-list', 'Inicio');
        self::assertSelectorTextNotContains('.q-list', 'Mapa pendiente');
        self::assertSame(1, $crawler->filter('a.tr-yours.on')->count());
        self::assertSame(1, $crawler->filter('a.tr-loc-es.on')->count());
        $esChip = (string) $crawler->filter('a.tr-loc-es.on')->attr('href');
        self::assertStringContainsString('locale=es', $esChip);
        self::assertStringContainsString('status=approved', $esChip);
        $statusApproved = (string) $crawler->filter('.mod-bar nav.lfilter')->eq(1)
            ->filter('a.lchip[href*="status=approved"]')->attr('href');
        self::assertStringContainsString('locale=es', $statusApproved);
    }

    public function testCatalogueLinksToMine(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-from-cat@example.com');
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/fr/translate');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('a.tr-yours[href$="/translate/mine"]')->count());
    }

    public function testMineDefaultsToCurrentLocale(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-loc-default@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $proposals->submit($rider, $entry, 'fr', 'Carte locale default', true);
        $proposals->submit($rider, $entry, 'nl', 'Kaart locale hidden', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/fr/translate/mine');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.q-list', 'Carte locale default');
        self::assertSelectorTextNotContains('.q-list', 'Kaart locale hidden');
        self::assertSame(1, $crawler->filter('a.tr-loc-fr.on')->count());
        self::assertSame(0, $crawler->filter('a.tr-loc-all.on')->count());
    }

    public function testMineAllLocalesShowsEveryProposal(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-loc-all@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $proposals->submit($rider, $entry, 'fr', 'Carte locale all', true);
        $proposals->submit($rider, $entry, 'nl', 'Kaart locale all', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/fr/translate/mine?locale=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.q-list', 'Carte locale all');
        self::assertSelectorTextContains('.q-list', 'Kaart locale all');
        self::assertSame(1, $crawler->filter('a.tr-loc-all.on')->count());
        $nl = (string) $crawler->filter('a.tr-loc-nl')->attr('href');
        self::assertStringContainsString('locale=nl', $nl);
    }

    public function testMineLocaleQueryOverridesDefault(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-loc-nl@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $proposals->submit($rider, $entry, 'fr', 'Carte locale override', true);
        $proposals->submit($rider, $entry, 'nl', 'Kaart locale override', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/fr/translate/mine?locale=nl');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.q-list', 'Kaart locale override');
        self::assertSelectorTextNotContains('.q-list', 'Carte locale override');
        self::assertSame(1, $crawler->filter('a.tr-loc-nl.on')->count());
        $approvedChip = $crawler->filter('nav.lfilter a.lchip[href*="status="]');
        if ($approvedChip->count() > 0) {
            self::assertStringContainsString('locale=nl', (string) $approvedChip->attr('href'));
        }
    }

    public function testMineEnglishDefaultsToAllLocales(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-loc-en@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $proposals->submit($rider, $entry, 'fr', 'Carte locale english', true);
        $proposals->submit($rider, $entry, 'de', 'Karte locale english', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/translate/mine');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.q-list', 'Carte locale english');
        self::assertSelectorTextContains('.q-list', 'Karte locale english');
        self::assertSame(1, $crawler->filter('a.tr-loc-all.on')->count());
    }

    public function testMineFilteredEmptyIsNotNeverProposed(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-loc-empty@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        static::getContainer()->get(ProposalService::class)
            ->submit($rider, $entry, 'nl', 'Kaart only dutch', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/translate/mine?locale=fr');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.q-item')->count());
        self::assertSelectorTextContains('.empty-state', 'Nothing in this language');
        self::assertSelectorTextNotContains('.empty-state', 'Nothing proposed yet');
        $all = (string) $crawler->filter('a.tr-loc-all')->attr('href');
        self::assertStringContainsString('locale=all', $all);
    }

    public function testMineGroupsSameKeyAndLocaleToLatest(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-group-rider@example.com');
        $curator = $this->createUser('mine-group-curator@example.com', ['ROLE_CURATOR']);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'home.lede', 'A community-built map');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $first = $proposals->submit($rider, $entry, 'nl', 'eerste versie water', true);
        static::getContainer()->get(DecisionService::class)
            ->decide((int) $first->getId(), 'approve', $curator, null);
        $proposals->submit($rider, $entry, 'nl', 'tweede versie waterpunten', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/nl/translate/mine');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.q-item')->count());
        self::assertSelectorTextContains('.q-item', 'tweede versie waterpunten');
        self::assertSelectorTextNotContains('.q-list', 'eerste versie water');
        $href = (string) $crawler->filter('a.tr-key-hist')->attr('href');
        self::assertStringContainsString('/nl/translate/mine/nl/'.$entry->getId(), $href);
    }

    public function testMineKeyHistoryIsTheCuratorStoryOldestFirst(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-key-hist-rider@example.com');
        $curator = $this->createUser('mine-key-hist-curator@example.com', ['ROLE_CURATOR']);
        $other = $this->createUser('mine-key-hist-other@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'home.lede', 'A community-built map');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $first = $proposals->submit($rider, $entry, 'nl', 'eerste sleutelgeschiedenis', true);
        static::getContainer()->get(DecisionService::class)
            ->decide((int) $first->getId(), 'approve', $curator, null);
        $proposals->submit($rider, $entry, 'nl', 'tweede sleutelgeschiedenis', true);
        $proposals->submit($other, $entry, 'nl', 'niet van deze fietser', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/nl/translate/mine/nl/'.$entry->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'home.lede');
        self::assertSame(1, $crawler->filter('.mh .lead')->count());
        $main = (string) $crawler->filter('#main')->html();
        self::assertLessThan(strpos($main, 'class="lead"'), strpos($main, '<h1'));
        self::assertSelectorTextContains('.tr-english', 'A community-built map');
        self::assertSame(1, $crawler->filter('.tr-original')->count());
        self::assertLessThan(strpos($main, 'tr-original'), strpos($main, 'tr-english'));
        self::assertLessThan(strpos($main, 'tr-hist-item'), strpos($main, 'tr-original'));
        self::assertStringNotContainsString('niet van deze fietser', $main);
        $items = $crawler->filter('.tr-hist-item');
        self::assertSame(1, $items->count());
        self::assertStringContainsString('eerste', $items->eq(0)->text());
        self::assertSelectorTextContains('.tr-pending .tr-change .q-was', 'eerste');
        self::assertSelectorTextContains('.tr-pending .tr-change .q-now', 'tweede');
        self::assertSame(1, $crawler->filter('a.tr-back[href$="/translate/mine"]')->count());
        self::assertSame(1, $crawler->filter('a.tr-continue')->count());
        self::assertSame(1, $crawler->filter('.tr-history')->count());
        self::assertSame(0, $crawler->filter('.q-item')->count());
        self::assertSame(0, $crawler->filter('.q-list')->count());
        self::assertSame(0, $crawler->filter('a.tr-pick')->count());
        self::assertSame(0, $crawler->filter('a.tr-key-hist')->count());
        self::assertSame(0, $crawler->filter('.tr-latest')->count());
        self::assertSame(0, $crawler->filter('.lfilter')->count());
    }

    public function testMineKeySettledStoryHasNoContinue(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-key-settled-rider@example.com');
        $curator = $this->createUser('mine-key-settled-curator@example.com', ['ROLE_CURATOR']);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'home.lede', 'A community-built map');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $first = $proposals->submit($rider, $entry, 'nl', 'eerste versie water', true);
        /** @var DecisionService $decisions */
        $decisions = static::getContainer()->get(DecisionService::class);
        $decisions->decide((int) $first->getId(), 'approve', $curator, null);
        $second = $proposals->submit($rider, $entry, 'nl', 'tweede versie waterpunten', true);
        $decisions->decide((int) $second->getId(), 'approve', $curator, null);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/nl/translate/mine/nl/'.$entry->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('.tr-hist-item')->count());
        self::assertSame(0, $crawler->filter('.tr-pending')->count());
        self::assertSame(0, $crawler->filter('a.tr-continue')->count());
        self::assertStringContainsString('eerste', $crawler->filter('.tr-hist-item')->eq(0)->text());
        self::assertStringContainsString('tweede', $crawler->filter('.tr-hist-item')->eq(1)->text());
        self::assertStringContainsString('waterpunten', $crawler->filter('.tr-hist-item')->eq(1)->html());
    }

    public function testMineKeyHistoryShowsADiffOnEachSubmission(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-key-pick-rider@example.com');
        $curator = $this->createUser('mine-key-pick-curator@example.com', ['ROLE_CURATOR']);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);
        $oldest = $proposals->submit($rider, $entry, 'nl', 'alpha old wording', true);
        static::getContainer()->get(DecisionService::class)
            ->decide((int) $oldest->getId(), 'approve', $curator, null);
        $mid = $proposals->submit($rider, $entry, 'nl', 'beta mid wording', true);
        static::getContainer()->get(DecisionService::class)
            ->decide((int) $mid->getId(), 'approve', $curator, null);
        $proposals->submit($rider, $entry, 'nl', 'gamma new wording', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/nl/translate/mine/nl/'.$entry->getId());

        self::assertResponseIsSuccessful();
        $items = $crawler->filter('.tr-hist-item');
        self::assertSame(2, $items->count());
        self::assertStringContainsString('alpha', $items->eq(0)->filter('.tr-change .q-now')->text());
        self::assertStringContainsString('alpha', $items->eq(1)->filter('.tr-change .q-was')->text());
        self::assertStringContainsString('beta', $items->eq(1)->filter('.tr-change .q-now')->text());
        self::assertSelectorTextContains('.tr-pending .tr-change .q-was', 'beta');
        self::assertSelectorTextContains('.tr-pending .tr-change .q-now', 'gamma');
        self::assertSame(0, $crawler->filter('a.tr-pick')->count());
    }

    public function testMineKeyHistorySingleVersionDiffsAgainstOriginal(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-key-one-rider@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.home', 'Home');
        static::getContainer()->get(ProposalService::class)
            ->submit($rider, $entry, 'fr', 'Accueil seul', true);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/fr/translate/mine/fr/'.$entry->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.tr-english')->count());
        self::assertSame(1, $crawler->filter('.tr-original')->count());
        self::assertSame(0, $crawler->filter('.tr-hist-item')->count());
        self::assertSame(1, $crawler->filter('.tr-pending .tr-change')->count());
        self::assertSelectorTextContains('.tr-pending .tr-change .q-now', 'Accueil');
        self::assertSame(1, $crawler->filter('a.tr-continue')->count());
        self::assertSame(0, $crawler->filter('a.tr-pick')->count());
    }

    public function testMineKeyHistoryIsForbiddenToOtherRiders(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mine-key-own@example.com');
        $stranger = $this->createUser('mine-key-stranger@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        static::getContainer()->get(ProposalService::class)
            ->submit($rider, $entry, 'fr', 'Carte privee', true);
        $client->loginUser($stranger);

        $client->request('GET', '/fr/translate/mine/fr/'.$entry->getId());

        self::assertResponseStatusCodeSame(404);
    }
}
