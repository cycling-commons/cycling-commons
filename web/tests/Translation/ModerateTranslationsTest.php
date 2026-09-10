<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Catalog\Entity\Submission;
use App\Catalog\RiderPseudonym;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\ModerationScope;
use App\Moderation\SubmissionQueue;
use App\Translation\DecisionService;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\ProposalService;
use App\Translation\TranslationCaches;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Curator /moderate/translations desk (translations.md §5).
 *
 * The queue is a scan; decisions live on /moderate/translations/{id}.
 *
 * ROLE_CURATOR users need totpSecret so TwoFactorSetupEnforcer does not redirect.
 */
final class ModerateTranslationsTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

    /**
     * @param list<string> $roles
     */
    private function createUser(
        string $email,
        string $plain,
        array $roles = [],
        ?string $totpSecret = null,
        bool $twoFaEnabled = false,
    ): User {
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
        $user->setPassword($hasher->hashPassword($user, $plain));

        if (null !== $totpSecret) {
            $user->setTotpSecret($totpSecret);
            $user->setTwoFaEnabled($twoFaEnabled);
        }

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function loginCurator(string $email): User
    {
        $client = static::getClient();
        $curator = $this->createUser(
            $email,
            'hunter2secure!',
            roles: ['ROLE_CURATOR'],
            totpSecret: 'JBSWY3DPEHPK3PXP',
            twoFaEnabled: true,
        );
        $client->loginUser($curator);

        return $curator;
    }

    private function seedPendingProposal(User $rider, string $key, string $english, string $locale, string $value): TranslationProposal
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, $key, $english);

        /** @var ProposalService $proposals */
        $proposals = static::getContainer()->get(ProposalService::class);

        return $proposals->submit($rider, $entry, $locale, $value, true);
    }

    public function testRiderForbiddenOnModerateTranslations(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-rider@example.com', 'hunter2secure!');
        $client->loginUser($rider);

        $client->request('GET', '/moderate/translations');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRiderForbiddenOnDetail(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-detail-rider@example.com', 'hunter2secure!');
        $client->loginUser($rider);

        $client->request('GET', '/moderate/translations/1');

        self::assertResponseStatusCodeSame(403);
    }

    public function testQueueIsAScanWithReviewLink(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-queue-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte desk');
        $id = (int) $proposal->getId();
        $this->loginCurator('mod-tr-curator@example.com');

        $crawler = $client->request('GET', '/moderate/translations');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('nav.map', $html);
        self::assertStringNotContainsString('Carte desk', $html);
        self::assertSame(0, $crawler->filter('input[name="translation_decision[_token]"]')->count());
        self::assertSame(0, $crawler->filter('a.q-review')->count());
        self::assertSame(
            1,
            $crawler->filter(sprintf('a.q-title-link[href$="/moderate/translations/%d"]', $id))->count(),
        );
        self::assertSame(1, $crawler->filter('h2.sec-band')->count());
        self::assertSame(1, $crawler->filter('.mh .lead')->count());
        $html = (string) $crawler->filter('#main')->html();
        self::assertLessThan(strpos($html, 'class="lead"'), strpos($html, '<h1'));
        self::assertLessThan(strpos($html, 'class="lfilter"'), strpos($html, 'class="lead"'));
    }

    public function testDetailShowsEnglishAndProposed(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-show-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte detail visible');
        $id = (int) $proposal->getId();
        $this->loginCurator('mod-tr-show-curator@example.com');

        $crawler = $client->request('GET', '/moderate/translations/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'nav.map');
        self::assertSelectorTextContains('.tr-english', 'Map');
        self::assertSelectorTextContains('.tr-pending', RiderPseudonym::for((int) $rider->getId()));
        $published = $crawler->filter('textarea[name="translation_decision[published]"]');
        self::assertSame(1, $published->count());
        self::assertStringContainsString('Carte detail visible', (string) $published->text());
        self::assertSelectorExists('input[name="translation_decision[_token]"]');
        self::assertSelectorExists('button[value="approve"]');
    }

    public function testDetailShowsLiveToProposedWordDiffBeforeTextarea(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-change-rider@example.com', 'hunter2secure!');
        $first = $this->seedPendingProposal(
            $rider,
            'test.tr.change.diff',
            'Water and views',
            'nl',
            'water en uitzichten',
        );
        $curator = $this->loginCurator('mod-tr-change-curator@example.com');
        static::getContainer()->get(DecisionService::class)->decide((int) $first->getId(), 'approve', $curator, null);

        $second = $this->seedPendingProposal(
            $rider,
            'test.tr.change.diff',
            'Water and views',
            'nl',
            'waterpunten en uitzichten',
        );
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate/translations/'.(int) $second->getId());

        self::assertResponseIsSuccessful();
        $changes = $crawler->filter('.tr-change');
        self::assertSame(2, $changes->count());
        self::assertSelectorTextContains('.tr-pending .tr-change .q-was', 'water');
        self::assertSelectorTextContains('.tr-pending .tr-change .q-now', 'waterpunten');
        $html = (string) $crawler->filter('#main')->html();
        $changePos = strpos($html, 'tr-pending');
        $textareaPos = strpos($html, 'name="translation_decision[published]"');
        self::assertNotFalse($changePos);
        self::assertNotFalse($textareaPos);
        self::assertLessThan($textareaPos, $changePos, 'word diff must sit above the proposed textarea');
        $published = $crawler->filter('textarea[name="translation_decision[published]"]');
        self::assertSame(1, $published->count());
        self::assertStringContainsString('waterpunten en uitzichten', (string) $published->text());
    }

    public function testDetailStoryIsEnglishThenOriginalThenChangesOldestFirst(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-story-rider@example.com', 'hunter2secure!');
        $first = $this->seedPendingProposal(
            $rider,
            'test.tr.story.order',
            'A community-built map of water',
            'nl',
            'eerste verhaaltekst',
        );
        $curator = $this->loginCurator('mod-tr-story-curator@example.com');
        static::getContainer()->get(DecisionService::class)->decide((int) $first->getId(), 'approve', $curator, null);
        $second = $this->seedPendingProposal(
            $rider,
            'test.tr.story.order',
            'A community-built map of water',
            'nl',
            'tweede verhaaltekst',
        );
        static::getContainer()->get(DecisionService::class)->decide((int) $second->getId(), 'approve', $curator, null);
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate/translations/'.(int) $second->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tr-english', 'A community-built map of water');
        self::assertSame(1, $crawler->filter('.tr-original')->count());
        self::assertSame(0, $crawler->filter('textarea[name="translation_decision[published]"]')->count());
        $main = (string) $crawler->filter('#main')->html();
        self::assertLessThan(strpos($main, 'tr-original'), strpos($main, 'tr-english'));
        self::assertLessThan(strpos($main, 'tr-hist-item'), strpos($main, 'tr-original'));
        $items = $crawler->filter('.tr-hist-item');
        self::assertSame(2, $items->count());
        self::assertStringContainsString('eerste', $items->eq(0)->text());
        self::assertStringContainsString('tweede', $items->eq(1)->text());
        self::assertStringNotContainsString('Live on the site', $main);
        self::assertSame(0, $crawler->filter('.tr-english-update')->count());
    }

    public function testDetailShowsEnglishChangeBetweenVersions(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-en-rider@example.com', 'hunter2secure!');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $first = $this->seedPendingProposal(
            $rider,
            'test.tr.story.english',
            'Old English source',
            'nl',
            'eerste nederlandse zin',
        );
        $curator = $this->loginCurator('mod-tr-en-curator@example.com');
        static::getContainer()->get(DecisionService::class)->decide((int) $first->getId(), 'approve', $curator, null);
        $entry = $this->findOrCreateEntry($em, 'test.tr.story.english', 'Old English source');
        $entry->applyGitEnglish('New English source');
        $em->flush();
        $second = $this->seedPendingProposal(
            $rider,
            'test.tr.story.english',
            'New English source',
            'nl',
            'tweede nederlandse zin',
        );
        static::getContainer()->get(DecisionService::class)->decide((int) $second->getId(), 'approve', $curator, null);
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate/translations/'.(int) $second->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tr-english', 'Old English source');
        self::assertSelectorTextContains('.tr-english-update', 'New English source');
        $main = (string) $crawler->filter('#main')->html();
        $firstChange = strpos($main, 'eerste');
        $enUpdate = strpos($main, 'tr-english-update');
        $secondChange = strpos($main, 'tweede');
        self::assertNotFalse($firstChange);
        self::assertNotFalse($enUpdate);
        self::assertNotFalse($secondChange);
        self::assertLessThan($enUpdate, $firstChange);
        self::assertLessThan($secondChange, $enUpdate);
    }

    public function testDetailListsPriorApprovalsInHistory(): void
    {
        $client = static::createClient();
        $firstRider = $this->createUser('mod-tr-hist-a@example.com', 'hunter2secure!');
        $first = $this->seedPendingProposal($firstRider, 'nav.map', 'Map', 'fr', 'Carte history alpha');
        $curator = $this->loginCurator('mod-tr-hist-curator@example.com');
        static::getContainer()->get(DecisionService::class)->decide((int) $first->getId(), 'approve', $curator, null);

        $secondRider = $this->createUser('mod-tr-hist-b@example.com', 'hunter2secure!');
        $second = $this->seedPendingProposal($secondRider, 'nav.map', 'Map', 'fr', 'Carte history beta');
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate/translations/'.(int) $second->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.tr-history');
        self::assertSelectorTextContains('.tr-history', 'Carte history alpha');
        self::assertSelectorTextContains('.tr-history', RiderPseudonym::for((int) $firstRider->getId()));
        self::assertSelectorTextContains('.tr-pending', 'Carte history beta');
        $main = (string) $crawler->filter('#main')->html();
        self::assertLessThan(strpos($main, 'Carte history beta'), strpos($main, 'Carte history alpha'));
    }

    public function testCuratorEditShowsInLaterHistory(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-typo-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte orignal typo');
        $proposalId = (int) $proposal->getId();
        $this->loginCurator('mod-tr-typo-curator@example.com');

        $crawler = $client->request('GET', '/moderate/translations/'.$proposalId);
        $token = (string) $crawler->filter('form input[name="translation_decision[_token]"]')->attr('value');
        $client->request('POST', '/moderate/translations/'.$proposalId, [
            'translation_decision' => [
                '_token' => $token,
                'proposal_id' => (string) $proposalId,
                'decision' => 'approve',
                'note' => '',
                'published' => 'Carte original typo',
            ],
        ]);
        self::assertResponseRedirects('/moderate/translations');

        $nextRider = $this->createUser('mod-tr-typo-next@example.com', 'hunter2secure!');
        $next = $this->seedPendingProposal($nextRider, 'nav.map', 'Map', 'fr', 'Carte next pending');

        $crawler = $client->request('GET', '/moderate/translations/'.(int) $next->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tr-history .q-was', 'orignal');
        self::assertSelectorTextContains('.tr-history .q-now', 'original');
        self::assertStringContainsString('Carte original typo', (string) $crawler->filter('.tr-history')->text());
    }

    public function testCuratorCanApproveFromDetail(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-approve-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte csrf');
        $proposalId = (int) $proposal->getId();
        $this->loginCurator('mod-tr-approve-curator@example.com');

        $crawler = $client->request('GET', '/moderate/translations/'.$proposalId);
        self::assertResponseIsSuccessful();

        $token = (string) $crawler->filter('form input[name="translation_decision[_token]"]')->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', '/moderate/translations/'.$proposalId, [
            'translation_decision' => [
                '_token' => $token,
                'proposal_id' => (string) $proposalId,
                'decision' => 'approve',
                'note' => '',
                'published' => 'Carte csrf',
            ],
        ]);

        self::assertResponseRedirects('/moderate/translations');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $decided = $em->find(TranslationProposal::class, $proposalId);
        self::assertNotNull($decided);
        self::assertSame(TranslationProposalStatus::Approved, $decided->getStatus());

        $overlay = $em->getRepository(TranslationOverlay::class)->findOneBy([
            'locale' => 'fr',
            'sourceProposal' => $decided,
        ]);
        self::assertNotNull($overlay);
        self::assertSame('Carte csrf', $overlay->getValue());
    }

    public function testNeedsInfoCardShowsStatus(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-badge-rider@example.com', 'hunter2secure!');
        $proposal = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte badge');
        $proposal->setStatus(TranslationProposalStatus::NeedsInfo);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->loginCurator('mod-tr-badge-curator@example.com');

        $client->request('GET', '/moderate/translations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.q-pill', 'Needs info');
    }

    public function testUnknownProposalIsNotFound(): void
    {
        $client = static::createClient();
        $this->loginCurator('mod-tr-unknown-curator@example.com');

        $client->request('GET', '/moderate/translations/999999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testQueueDoesNotAcceptDecisions(): void
    {
        $client = static::createClient();
        $this->loginCurator('mod-tr-list-post-curator@example.com');

        $client->request('POST', '/moderate/translations', [
            'translation_decision' => [
                'proposal_id' => '1',
                'decision' => 'approve',
                'note' => '',
            ],
        ]);

        self::assertResponseStatusCodeSame(405);
    }

    public function testQueueLinksToSettledHistory(): void
    {
        $client = static::createClient();
        $this->loginCurator('mod-tr-hist-link-curator@example.com');

        $crawler = $client->request('GET', '/moderate/translations');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('a.tr-desk-hist[href$="/moderate/translations/history"]')->count());
    }

    public function testAccountChipShowsOpenTranslationCount(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-chip-rider@example.com', 'hunter2secure!');
        $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte chip count');
        $this->loginCurator('mod-tr-chip-curator@example.com');

        $crawler = $client->request('GET', '/moderate/translations');
        self::assertResponseIsSuccessful();

        $open = static::getContainer()->get(DecisionService::class)->pendingCount();
        self::assertGreaterThan(0, $open);
        $expected = (string) $open;
        $count = $crawler->filter('.acct-dropdown a[href$="/moderate/translations"] .acct-count');
        self::assertSame(1, $count->count());
        self::assertSame($expected, trim($count->text()));
        self::assertSelectorExists('.acct-chip .acct-bulb');
        self::assertSame($expected, trim($crawler->filter('.acct-chip .acct-bulb')->text()));
    }

    public function testAccountChipBulbSumsMessagesSubmissionsAndTranslations(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-chip-sum-rider@example.com', 'hunter2secure!');
        $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte chip sum');
        $this->seedPendingSubmission();
        $curator = $this->loginCurator('mod-tr-chip-sum-curator@example.com');
        static::getContainer()->get(MessageService::class)->sendSystem(
            (int) $curator->getId(),
            UserMessageKind::SubmissionApproved,
            'submission',
            1,
            'SUB-1 · Fountain',
            'messages.body.submission_approved',
            ['%title%' => 'Fountain'],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/moderate/translations');
        self::assertResponseIsSuccessful();

        $messages = static::getContainer()->get(MessageService::class)->unreadCount((int) $curator->getId());
        $submissions = static::getContainer()->get(SubmissionQueue::class)->total(ModerationScope::global());
        $translations = static::getContainer()->get(DecisionService::class)->pendingCount();
        self::assertGreaterThan(0, $messages);
        self::assertGreaterThan(0, $submissions);
        self::assertGreaterThan(0, $translations);
        $total = $messages + $submissions + $translations;

        self::assertSame((string) $total, trim($crawler->filter('.acct-chip .acct-bulb')->text()));
        self::assertSame(
            (string) $messages,
            trim($crawler->filter('.acct-dropdown a[href$="/messages"] .acct-count')->text()),
        );
        self::assertSame(
            (string) $submissions,
            trim($crawler->filter('.acct-dropdown a[href$="/moderate"] .acct-count')->text()),
        );
        self::assertSame(
            (string) $translations,
            trim($crawler->filter('.acct-dropdown a[href$="/moderate/translations"] .acct-count')->text()),
        );
    }

    private function seedPendingSubmission(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('D')->setUserId(7)
            ->setStatus(SubmissionStatus::Pending)->setTitle('Chip sum fountain')
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')
            ->setCountryCode('BE')
            ->setChanges(['hours' => ['was' => '24/7', 'now' => 'closed Sundays']])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();
    }

    public function testSettledHistoryShowsDecidedNotPending(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-desk-hist-rider@example.com', 'hunter2secure!');
        $kept = $this->seedPendingProposal($rider, 'nav.map', 'Map', 'fr', 'Carte encore ouverte');
        $done = $this->seedPendingProposal($rider, 'nav.home', 'Home', 'de', 'Startseite decided');
        $curator = $this->loginCurator('mod-tr-desk-hist-curator@example.com');
        static::getContainer()->get(DecisionService::class)->decide((int) $done->getId(), 'approve', $curator, null);

        $crawler = $client->request('GET', '/moderate/translations/history');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.q-list', 'Startseite decided');
        self::assertSelectorTextNotContains('.q-list', 'Carte encore ouverte');
        self::assertSelectorTextContains('.q-list', RiderPseudonym::for((int) $rider->getId()));
        self::assertSame(0, $crawler->filter('a.q-review')->count());
        self::assertSame(
            1,
            $crawler->filter(sprintf('a.q-title-link[href$="/moderate/translations/%d"]', (int) $done->getId()))->count(),
        );
        self::assertSame(1, $crawler->filter('h2.sec-band')->count());
        self::assertSame(1, $crawler->filter('.mh .lead')->count());
        $html = (string) $crawler->filter('#main')->html();
        self::assertLessThan(strpos($html, 'class="lead"'), strpos($html, '<h1'));
        self::assertLessThan(strpos($html, 'class="lfilter"'), strpos($html, 'class="lead"'));
        unset($kept);
    }

    public function testHistoryGroupsSameKeyAndLocaleToLatest(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-hist-group-rider@example.com', 'hunter2secure!');
        $first = $this->seedPendingProposal($rider, 'home.lede', 'A community-built map', 'nl', 'eerste versie water');
        $other = $this->seedPendingProposal($rider, 'nav.home', 'Home', 'de', 'Startseite andere sleutel');
        $curator = $this->loginCurator('mod-tr-hist-group-curator@example.com');
        /** @var DecisionService $decisions */
        $decisions = static::getContainer()->get(DecisionService::class);
        $decisions->decide((int) $first->getId(), 'approve', $curator, null);
        $decisions->decide((int) $other->getId(), 'approve', $curator, null);
        $second = $this->seedPendingProposal($rider, 'home.lede', 'A community-built map', 'nl', 'tweede versie waterpunten');
        $decisions->decide((int) $second->getId(), 'approve', $curator, null);

        $crawler = $client->request('GET', '/moderate/translations/history');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('.q-item')->count());
        self::assertSelectorTextContains('.q-list', 'tweede versie waterpunten');
        self::assertSelectorTextNotContains('.q-list', 'eerste versie water');
        self::assertSame(0, $crawler->filter('a.q-review')->count());
        self::assertSame(
            1,
            $crawler->filter(sprintf('a.q-title-link[href$="/moderate/translations/%d"]', (int) $second->getId()))->count(),
        );
        self::assertSame(
            0,
            $crawler->filter(sprintf('a.q-title-link[href$="/moderate/translations/%d"]', (int) $first->getId()))->count(),
        );
        $keys = $crawler->filter('a.q-title-link');
        self::assertSame('home.lede', trim($keys->eq(0)->text()));
        self::assertSame('nav.home', trim($keys->eq(1)->text()));
    }

    public function testSettledDetailIsReadOnly(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-settled-detail-rider@example.com', 'hunter2secure!');
        $done = $this->seedPendingProposal($rider, 'nav.home', 'Home', 'de', 'Startseite settled detail');
        $curator = $this->loginCurator('mod-tr-settled-detail-curator@example.com');
        static::getContainer()->get(DecisionService::class)->decide((int) $done->getId(), 'approve', $curator, null);

        $crawler = $client->request('GET', '/moderate/translations/'.(int) $done->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'nav.home');
        self::assertSelectorTextContains('.q-diff', 'Startseite settled detail');
        self::assertSame(0, $crawler->filter('button[value="approve"]')->count());
        self::assertSame(0, $crawler->filter('textarea[name="translation_decision[published]"]')->count());
        self::assertSame(1, $crawler->filter('a.tr-back[href$="/moderate/translations/history"]')->count());
    }

    public function testEnglishProposalShowsInQueueWithApproveNoticeAndVersions(): void
    {
        $client = static::createClient();
        $author = $this->createUser('en-author-desk@example.com', 'hunter2secure!', ['ROLE_CURATOR'], 'JBSWY3DPEHPK3PXP', true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $proposal = new TranslationProposal($entry, 'en', 'Map view', 'Map', (int) $author->getId(), null, 1);
        $em->persist($proposal);
        $em->flush();

        $this->loginCurator('en-reviewer-desk@example.com');
        $crawler = $client->request('GET', '/moderate/translations');
        self::assertStringContainsString('nav.map', $crawler->filter('#main')->html());

        $crawler = $client->request('GET', '/moderate/translations/'.$proposal->getId());
        $html = $crawler->filter('#main')->html();
        self::assertStringContainsString('marks the four translations', $html);
        self::assertStringContainsString('v1', $html);
    }

    public function testHistoryShowsSettledEnglishProposal(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'zzz.desk.en.history', 'Photos up to 5 MB');
        // Null submitter and null consent record are the shape of a system-
        // authored 'en' proposal (translations.md §4.2, §6): settledCard()
        // must survive both without a curator ever seeing them explicitly.
        $proposal = new TranslationProposal($entry, 'en', 'Photos up to 10 MB', 'Photos up to 5 MB', null, null, $entry->getEnglishVersion());
        $em->persist($proposal);
        $em->flush();

        $curator = $this->loginCurator('en-history-curator@example.com');
        static::getContainer()->get(DecisionService::class)->decide((int) $proposal->getId(), 'approve', $curator, null, 'Photos up to 10 MB');

        $crawler = $client->request('GET', '/moderate/translations/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.q-list', 'zzz.desk.en.history');
    }

    public function testStaleListPerLocale(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'zzz.desk.stale', 'Photos up to 5 MB');
        $entry->applyApprovedEnglish('Photos up to 10 MB');
        $em->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();

        $this->loginCurator('stale-desk@example.com');
        $crawler = $client->request('GET', '/moderate/translations/stale?locale=nl');
        self::assertResponseIsSuccessful();
        $html = $crawler->filter('#main')->html();
        self::assertStringContainsString('zzz.desk.stale', $html);
        self::assertStringContainsString('v2', $html);
    }

    public function testRiderForbiddenOnSettledHistory(): void
    {
        $client = static::createClient();
        $rider = $this->createUser('mod-tr-hist-rider@example.com', 'hunter2secure!');
        $client->loginUser($rider);

        $client->request('GET', '/moderate/translations/history');

        self::assertResponseStatusCodeSame(403);
    }
}
