<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Contribution\PlaceText;
use App\Contribution\PlaceTextProposals;
use App\Entity\User;
use App\Moderation\DeskSeen;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\SeenSubject;
use App\Moderation\SubmissionQueue;
use App\Moderation\TextCreditUndecidedException;
use App\Town\TownPlaceRepository;
use App\Town\TownSummaryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Town and region texts: everybody signed in may propose one, a curator of
 * that region approves it (owner 2026-09-30,
 * moderation-and-contribution.md §3.1b).
 *
 * One way to moderate: a proposal is an ordinary submission of type Text in
 * the one queue, filed in the region the town lies in (or the region itself),
 * so area scope decides which curators see it. A curator's own proposal
 * inside their area applies at once; outside it, it queues like a rider's.
 *
 * Two regions far from any real one, so the point-in-region lookup can only
 * answer with them. DAMA wraps each test in a rolled-back transaction.
 */
final class PlaceTextProposalTest extends WebTestCase
{
    private const string TOWN = 'node/999990301';
    private const float LAT = -40.5;
    private const float LNG = -140.5;

    public function testARidersTownTextWaitsInTheTownsRegionAndOnlyItsCuratorsSeeIt(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a, $b] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider@example.com', []);
        $curatorA = $this->user('ptext-cur-a@example.com', ['ROLE_CURATOR'], $a);
        $curatorB = $this->user('ptext-cur-b@example.com', ['ROLE_CURATOR'], $b);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp: vlak, kasseien in het centrum.', 'nl');
        self::assertStringContainsString('Sent for review', (string) $client->getResponse()->getContent());

        $sub = $this->latestText((int) $rider->getId());
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus());
        self::assertSame($a->getId(), $sub->getRegionId(), 'filed in the region the town lies in');
        self::assertEquals(['text:nl' => ['was' => 'Testdorp is een dorp.', 'now' => 'Testdorp: vlak, kasseien in het centrum.']], $sub->getChanges());
        self::assertSame('Testdorp is een dorp.', $this->towns()->find(self::TOWN, 'nl')['extract'] ?? null, 'nothing changes before a curator decides');

        self::assertContains((int) $sub->getId(), $this->queueIds($curatorA), 'a curator of the town\'s region sees it');
        self::assertNotContains((int) $sub->getId(), $this->queueIds($curatorB), 'a curator of another region does not');

        // The desk card is the ordinary one: an Edit tag (it replaces the text
        // readers see) and the was/now diff.
        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/submissions');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame('Edit', $crawler->filter('.q-item[data-item-id="'.$sub->getId().'"] .q-tag--edit')->text());
        self::assertStringContainsString('Testdorp: vlak, kasseien in het centrum.', $html);
    }

    public function testApprovingPutsTheTextOnTheCardWithTheRidersCredit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider2@example.com', [], null, 'Dorpsfietser');
        $curatorA = $this->user('ptext-cur-a2@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp heeft een dorpsronde.', 'nl');
        $sub = $this->latestText((int) $rider->getId());

        $this->approve($sub, $curatorA, true);

        $row = $this->towns()->find(self::TOWN, 'nl');
        self::assertNotNull($row);
        self::assertTrue($row['edited'], 'local from now on');
        self::assertTrue($row['derived'], 'the curator kept the Wikipedia credit');
        self::assertSame('Testdorp heeft een dorpsronde.', $row['extract']);
        self::assertSame($rider->getId(), $row['editedBy'], 'written by the rider');
        self::assertSame($curatorA->getId(), $row['approvedBy'], 'let on by the curator');

        $client->request('GET', '/map/town/'.self::TOWN.'?lang=nl');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Testdorp heeft een dorpsronde.', $data['text']['extract']);
        self::assertSame(['name' => 'Dorpsfietser'], $data['editedBy'], 'the card names the rider by their public name');
        self::assertTrue($data['derived'], 'the card keeps the Wikipedia link and credit');
        self::assertSame('https://nl.wikipedia.org/wiki/Testdorp', $data['text']['url']);
    }

    public function testRejectingLeavesTheFetchedText(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider3@example.com', []);
        $curatorA = $this->user('ptext-cur-a3@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $this->propose($client, 'Onzin.', 'nl');
        $sub = $this->latestText((int) $rider->getId());
        $this->moderation()->decide((int) $sub->getId(), 'reject', $curatorA, 'Geen bron.');

        $row = $this->towns()->find(self::TOWN, 'nl');
        self::assertNotNull($row);
        self::assertFalse($row['edited']);
        self::assertSame('Testdorp is een dorp.', $row['extract']);
    }

    public function testACuratorsOwnTextAppliesInsideTheirAreaAndQueuesOutsideIt(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a, $b] = $this->twoRegions();
        $this->fetchedTown('en', 'Testville is a village.');
        $curatorA = $this->user('ptext-cur-a4@example.com', ['ROLE_CURATOR'], $a);
        $curatorB = $this->user('ptext-cur-b4@example.com', ['ROLE_CURATOR'], $b);

        $client->loginUser($curatorB);
        $this->propose($client, 'Testville, written from elsewhere.', 'en');
        self::assertStringContainsString('Sent for review', (string) $client->getResponse()->getContent());
        self::assertSame(SubmissionStatus::Pending, $this->latestText((int) $curatorB->getId())->getStatus(), 'outside their area it waits like a rider\'s');
        self::assertSame('Testville is a village.', $this->towns()->find(self::TOWN, 'en')['extract'] ?? null);

        $client->loginUser($curatorA);
        $this->propose($client, 'Testville, written by its own curator.', 'en');
        self::assertStringContainsString('Text updated', (string) $client->getResponse()->getContent());
        self::assertSame(SubmissionStatus::Approved, $this->latestText((int) $curatorA->getId())->getStatus(), 'inside it applies at once');
        $row = $this->towns()->find(self::TOWN, 'en');
        self::assertSame('Testville, written by its own curator.', $row['extract'] ?? null);

        // A curator's own text is "our curators", never a name.
        $client->request('GET', '/map/town/'.self::TOWN.'?lang=en');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNull($data['editedBy']);
    }

    public function testTheTownPenIsLimitedToTheCuratorsAreas(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a, $b] = $this->twoRegions();
        $this->fetchedTown('en', 'Testville is a village.');
        $curatorA = $this->user('ptext-cur-a5@example.com', ['ROLE_CURATOR'], $a);
        $curatorB = $this->user('ptext-cur-b5@example.com', ['ROLE_CURATOR'], $b);

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/town/'.self::TOWN);
        self::assertResponseIsSuccessful('inside their area the direct pen stays');
        $token = (string) $crawler->filter('form.townform input[name=_token]')->first()->attr('value');

        $client->loginUser($curatorB);
        $client->request('GET', '/moderate/town/'.self::TOWN);
        self::assertResponseRedirects('/town/'.self::TOWN.'/text', null, 'outside it, the proposal form like a rider');
        $client->followRedirect();
        self::assertStringContainsString('outside your areas', (string) $client->getResponse()->getContent());

        // Re-checked on the POST, with a token the form really rendered.
        $client->request('POST', '/moderate/town/'.self::TOWN, ['_token' => $token, 'lang' => 'en', 'text' => 'Sneaked in.']);
        self::assertResponseRedirects('/town/'.self::TOWN.'/text');
        self::assertSame('Testville is a village.', $this->towns()->find(self::TOWN, 'en')['extract'] ?? null, 'and nothing is written');
    }

    public function testASecondProposalAmendsTheOpenOne(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->twoRegions();
        $this->fetchedTown('en', 'Testville is a village.');
        $rider = $this->user('ptext-rider6@example.com', []);

        $client->loginUser($rider);
        $this->propose($client, 'First go.', 'en');
        $this->propose($client, 'Second go.', 'en');

        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM submission WHERE type = 'text' AND user_id = :u", ['u' => $rider->getId()]));
        self::assertSame('Second go.', $this->latestText((int) $rider->getId())->getChanges()['text:en']['now']);
    }

    public function testAnUnchangedTextIsSentBack(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->twoRegions();
        $this->fetchedTown('en', 'Testville is a village.');
        $client->loginUser($this->user('ptext-rider7@example.com', []));

        $this->propose($client, 'Testville is a village.', 'en');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('same as the one readers see now', (string) $client->getResponse()->getContent());
    }

    public function testTheWriterSaysWhereTheirTextComesFromWhereThereIsAnArticle(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider40@example.com', []);
        $client->loginUser($rider);

        // Two radios in a required fieldset, no default.
        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang=nl&name=Testdorp&lat='.self::LAT.'&lng='.self::LNG);
        $fieldset = $crawler->filter('fieldset#pt-source');
        self::assertCount(1, $fieldset);
        self::assertNull($fieldset->attr('hidden'));
        self::assertNull($fieldset->attr('disabled'));
        self::assertSame('Where your text comes from', trim(str_replace('*', '', $fieldset->filter('legend')->text())));
        self::assertCount(1, $fieldset->filter('legend .req'), 'marked required like the text box');
        self::assertSame('I adapted this from the Wikipedia article', $fieldset->filter('label[for=pt-source-adapted]')->text());
        self::assertSame('I wrote my own text', $fieldset->filter('label[for=pt-source-own]')->text());
        foreach (['adapted', 'own'] as $v) {
            $radio = $fieldset->filter('input#pt-source-'.$v);
            self::assertSame('radio', $radio->attr('type'));
            self::assertSame('derived', $radio->attr('name'));
            self::assertSame($v, $radio->attr('value'));
            self::assertNotNull($radio->attr('required'));
            self::assertNull($radio->attr('checked'), 'no default');
        }
        self::assertCount(0, $crawler->filter('input[type=checkbox][name=derived]'), 'no tick box any more');
        self::assertSame(['en' => false, 'fr' => false, 'nl' => true, 'de' => false, 'es' => false], json_decode((string) $crawler->filter('#place-text-form')->attr('data-articles'), true), 'the script shows it per language');

        // Without an answer the send is refused and nothing is filed.
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'nl', 'text' => 'Testdorp heeft een kerk.']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Say where your text comes from: adapted from the Wikipedia article, or your own text.', (string) $client->getResponse()->getContent());
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM submission WHERE type = 'text' AND user_id = :u", ['u' => $rider->getId()]));
        self::assertSame('Testdorp heeft een kerk.', $client->getCrawler()->filter('textarea#pt-text')->text('', false), 'the words stay in the box');

        // Each answer is stored as the writer's claim.
        $this->propose($client, 'Testdorp heeft een kerk.', 'nl', '', 'adapted');
        self::assertTrue($this->latestText((int) $rider->getId())->getPayload()['derived'] ?? null, 'adapted: true');
        $this->propose($client, 'Testdorp heeft een kerk en een plein.', 'nl', '', 'own');
        self::assertFalse($this->latestText((int) $rider->getId())->getPayload()['derived'] ?? null, 'own text: false');

        // Without an article there is no question: hidden, disabled, and the text is the writer's own.
        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang=fr&name=Testdorp');
        self::assertNotNull($crawler->filter('fieldset#pt-source')->attr('disabled'));
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'fr', 'text' => 'Testdorp a une église.']));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->latestText((int) $rider->getId())->getPayload()['derived'] ?? null);
    }

    public function testARegionTextNeedsTheSameAnswer(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->twoRegions();
        $rider = $this->user('ptext-rider41@example.com', []);
        $client->loginUser($rider);

        $crawler = $client->request('GET', '/regions/ptext-a/text?lang=en');
        self::assertNull($crawler->filter('fieldset#pt-source')->attr('hidden'), 'the region has an English article');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'Ptext A is hilly.']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Say where your text comes from', (string) $client->getResponse()->getContent());

        $client->submit($client->getCrawler()->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'Ptext A is hilly.', 'derived' => 'own']));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->latestText((int) $rider->getId())->getPayload()['derived'] ?? null);
        $crawler = $client->request('GET', '/regions/ptext-a/text?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'Ptext A is hilly, after the article.', 'derived' => 'adapted']));
        self::assertTrue($this->latestText((int) $rider->getId())->getPayload()['derived'] ?? null);
    }

    public function testTheFormShowsTheCurrentTextBesideAnEmptyBoxWithACounter(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $client->loginUser($this->user('ptext-rider10@example.com', []));

        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang=nl&name=Testdorp');
        self::assertResponseIsSuccessful();
        $quote = $crawler->filter('figure#pt-current blockquote#pt-current-text');
        self::assertSame('Testdorp is een dorp.', $quote->text());
        self::assertNull($quote->attr('hidden'));
        self::assertNotNull($crawler->filter('#pt-current-none')->attr('hidden'));
        self::assertSame('Current text', $crawler->filter('figure#pt-current figcaption')->text());

        $box = $crawler->filter('textarea#pt-text');
        self::assertSame('', $box->text('', false), 'the current text is not inside the box');
        self::assertSame('1200', $box->attr('maxlength'));
        self::assertStringContainsString('pt-text-count', (string) $box->attr('aria-describedby'));
        $count = $crawler->filter('#pt-text-count');
        self::assertSame('polite', $count->attr('aria-live'));
        self::assertSame('1200', $count->attr('data-max'));
        self::assertSame('0 of 1200', $count->text());
        self::assertSame('%count% of 1200', $count->attr('data-words'));
        self::assertStringContainsString('Write the whole text as readers should see it', $crawler->filter('#pt-text-hint')->text());
        self::assertStringContainsString('Testdorp is een dorp.', (string) $crawler->filter('#place-text-form')->attr('data-texts'), 'the script swaps the quote per language');

        // Send and Cancel as a primary and a secondary button, the licence in
        // small print below them.
        $actions = $crawler->filter('#place-text-form .pt-actions');
        self::assertSame('btn btn-p', $actions->filter('button[type=submit]')->attr('class'));
        self::assertSame('btn btn-g', $actions->filter('a')->attr('class'));
        $after = $crawler->filter('#place-text-form .pt-actions ~ p.pt-licence');
        self::assertCount(1, $after, 'the licence line follows the buttons');
        self::assertStringContainsString('CC BY-SA 4.0', $after->text());

        // No text yet in this language: said plainly, nothing quoted.
        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang=en&name=Testdorp');
        self::assertNotNull($crawler->filter('#pt-current-text')->attr('hidden'));
        self::assertNull($crawler->filter('#pt-current-none')->attr('hidden'));
        self::assertSame('There is no text in this language yet.', $crawler->filter('#pt-current-none')->text());

        // A refused send keeps the rider's words in the box and counts them.
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'nl', 'text' => 'Testdorp is een dorp.', 'derived' => 'own']));
        self::assertResponseStatusCodeSame(422);
        $crawler = $client->getCrawler();
        self::assertNotNull($crawler->filter('input#pt-source-own')->attr('checked'), 'and their answer to where it comes from');
        self::assertSame('Testdorp is een dorp.', $crawler->filter('textarea#pt-text')->text('', false));
        self::assertSame('21 of 1200', $crawler->filter('#pt-text-count')->text());
    }

    public function testAVisitorIsSentToSignInFirst(): void
    {
        $client = static::createClient();
        $client->request('GET', '/town/'.self::TOWN.'/text?lang=en');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testARidersRegionTextIsFiledInThatRegionAndCreditedOnceApproved(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a, $b] = $this->twoRegions();
        $rider = $this->user('ptext-rider8@example.com', [], null, 'Streekfietser');
        $curatorA = $this->user('ptext-cur-a8@example.com', ['ROLE_CURATOR'], $a);
        $curatorB = $this->user('ptext-cur-b8@example.com', ['ROLE_CURATOR'], $b);

        // The region page's credit line ends in the town card's two ringed
        // icons, "!" to report the text and the pencil to the form, with no
        // sentence and no second link under it.
        $crawler = $client->request('GET', '/regions/ptext-a');
        self::assertResponseIsSuccessful();
        $page = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Anyone signed in can suggest an edit', $page);
        $icons = $crawler->filter('.rg-about-attrib.tc-line > .tc-acts > a.ring-ico');
        self::assertCount(2, $icons, 'the "!" and the pencil are on the credit line');
        $report = $icons->eq(0);
        self::assertSame('ring-ico ring-ico--report', $report->attr('class'));
        self::assertSame('!', $report->text());
        self::assertSame('Report this text', $report->attr('aria-label'));
        $href = (string) $report->attr('href');
        self::assertStringStartsWith('/report/region/'.$a->getId().'?', $href, 'the one report door, region target');
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $q);
        self::assertSame(['from' => '/regions/ptext-a'], $q, 'the report desk names the region from its id');
        $pen = $icons->eq(1);
        self::assertSame('ring-ico ring-ico--edit', $pen->attr('class'));
        self::assertSame('/regions/ptext-a/text', $pen->attr('href'));
        self::assertSame('Edit this text', $pen->attr('aria-label'));
        self::assertSame('Edit this text', $pen->attr('title'));
        self::assertCount(0, $crawler->filter('.rg-about-write'), 'a region with a text has no plain link');

        // A region with no text has no credit line, so one plain link stands instead.
        $crawler = $client->request('GET', '/regions/ptext-b');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.tc-acts'));
        $write = $crawler->filter('.rg-about-write a');
        self::assertSame('Write a text for this region', $write->text());
        self::assertSame('/regions/ptext-b/text', $write->attr('href'));
        self::assertSame('Write a text for this region', trim($crawler->filter('.rg-about-write')->text()), 'the link alone, no sentence');

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/ptext-a/text?lang=en');
        self::assertResponseIsSuccessful();
        self::assertSame('Wikipedia says Ptext.', $crawler->filter('figure#pt-current blockquote#pt-current-text')->text(), 'what readers see now, read-only');
        self::assertSame('', $crawler->filter('textarea#pt-text')->text('', false), 'the box starts empty');
        $client->submit($crawler->filter('#place-text-form')->form([
            'lang' => 'en', 'text' => 'Ptext A is flat and windy.', 'note' => 'I live here.', 'derived' => 'adapted',
        ]));
        self::assertResponseIsSuccessful();

        $sub = $this->latestText((int) $rider->getId());
        self::assertSame($a->getId(), $sub->getRegionId());
        self::assertSame('I live here.', $sub->getPayload()['details']['note'] ?? null, 'the note is the card body, as for any contribution');
        self::assertContains((int) $sub->getId(), $this->queueIds($curatorA));
        self::assertNotContains((int) $sub->getId(), $this->queueIds($curatorB));

        $this->approve($sub, $curatorA, true);

        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        /** @var array<string, array<string, mixed>> $curated */
        $curated = json_decode((string) $db->fetchOne('SELECT context_curated FROM region WHERE id = :id', ['id' => $a->getId()]), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Ptext A is flat and windy.', $curated['en']['text']);
        self::assertSame($rider->getId(), $curated['en']['userId']);
        self::assertSame($curatorA->getId(), $curated['en']['approvedBy']);
        self::assertSame($sub->getId(), $curated['en']['submissionId']);

        $client->request('GET', '/regions/ptext-a');
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ptext A is flat and windy.', $page);
        self::assertStringContainsString('Adapted from Wikipedia by Streekfietser:', $page,
            'the form started from the article, so the adaptation keeps its citation and names the rider');
    }

    public function testACuratorsRegionTextOutsideTheirAreaQueues(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a, $b] = $this->twoRegions();
        $curatorB = $this->user('ptext-cur-b9@example.com', ['ROLE_CURATOR'], $b);

        $client->loginUser($curatorB);
        $crawler = $client->request('GET', '/regions/ptext-a/text?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'From the next region over.', 'derived' => 'own']));
        $sub = $this->latestText((int) $curatorB->getId());
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus());
        self::assertSame($a->getId(), $sub->getRegionId());
    }

    public function testTheTextCardCarriesTheEditPenToItsCorrectionForm(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider20@example.com', []);
        $curatorA = $this->user('ptext-cur-a20@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp heeft een kerk en een molen.', 'nl');
        $sub = $this->latestText((int) $rider->getId());

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/submissions');
        $card = $crawler->filter('.q-item[data-item-id="'.$sub->getId().'"]');
        self::assertCount(1, $card);
        $pen = $card->filter('a.q-edit');
        self::assertCount(1, $pen, 'a Text card carries the pen, as a place edit does');
        self::assertStringEndsWith('/moderate/text/'.$sub->getId(), (string) $pen->attr('href'));
        self::assertSame('_blank', $pen->attr('target'));
        self::assertNotNull($pen->attr('data-opens'), 'following it takes the unseen bar off');
        self::assertSame($pen->attr('href'), $card->filter('a.q-title-link')->attr('href'), 'the title opens the same form');
        self::assertSame(PlaceText::ICONS[PlaceText::TOWN], $card->filter('.q-head .q-icon svg path')->attr('d'), 'a town text shows the town icon');

        // A region text shows the region icon, in the queue and in History.
        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/ptext-a/text?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'Ptext A is flat.', 'derived' => 'own']));
        $region = $this->latestText((int) $rider->getId());
        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/submissions');
        self::assertSame(PlaceText::ICONS[PlaceText::REGION], $crawler->filter('.q-item[data-item-id="'.$region->getId().'"] .q-head .q-icon svg path')->attr('d'));
        $this->approve($region, $curatorA, true);
        $crawler = $client->request('GET', '/moderate/submissions/history');
        self::assertSame(PlaceText::ICONS[PlaceText::REGION], $crawler->filter('.q-item[data-submission-id="'.$region->getId().'"] .q-head .q-icon svg path')->attr('d'));
    }

    public function testTheCorrectionFormHoldsTheRidersTextAndNoteInTheirLanguage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider21@example.com', []);
        $curatorA = $this->user('ptext-cur-a21@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp heeft een kerk en een moolen.', 'nl', 'Ik woon er.');
        $sub = $this->latestText((int) $rider->getId());

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('Testdorp is een dorp.', $crawler->filter('figure#pt-current blockquote#pt-current-text')->text(), 'what readers see now, read-only');
        self::assertSame('Testdorp heeft een kerk en een moolen.', $crawler->filter('textarea#pt-text')->text('', false), 'the box holds the rider\'s text');
        self::assertSame('38 of 1200', $crawler->filter('#pt-text-count')->text());
        $lang = $crawler->filter('select#pt-lang');
        self::assertNotNull($lang->attr('disabled'), 'the language is the proposal\'s');
        self::assertCount(1, $lang->filter('option'));
        self::assertSame('Nederlands', $lang->filter('option')->text());
        self::assertSame('Ik woon er.', $crawler->filter('#pt-rider-note blockquote')->text(), 'the rider\'s note, read-only');
        self::assertCount(0, $crawler->filter('input#pt-note'), 'the note is the rider\'s, not a field');
        self::assertSame('Save correction', $crawler->filter('#place-text-form button[value=save]')->text());
        self::assertStringContainsString('credited to the rider', (string) $client->getResponse()->getContent());
        self::assertTrue(static::getContainer()->get(DeskSeen::class)->isSeen((int) $curatorA->getId(), SeenSubject::Submission, (int) $sub->getId()), 'loading the form opens the submission');
    }

    public function testSavingACorrectionAmendsTheSuggestionAndLeavesItForTheDecision(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider22@example.com', []);
        $curatorA = $this->user('ptext-cur-a22@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp heeft een kerk en een moolen.', 'nl', 'Ik woon er.');
        $sub = $this->latestText((int) $rider->getId());

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());

        // A forged token changes nothing.
        $client->request('POST', '/moderate/text/'.$sub->getId(), ['_token' => 'forged', 'text' => 'Testdorp heeft een kerk en een molen.']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Testdorp heeft een kerk en een moolen.', $this->latestText((int) $rider->getId())->getPayload()['text']);

        // Sending the rider's own words back is no correction.
        $client->submit($crawler->filter('#place-text-form')->form(['text' => 'Testdorp heeft een kerk en een moolen.']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('the text as the rider sent it', (string) $client->getResponse()->getContent());

        $client->submit($crawler->filter('#place-text-form')->form(['text' => 'Testdorp heeft een kerk en een molen.']));
        self::assertResponseRedirects();
        self::assertStringEndsWith('/moderate/submissions', (string) $client->getResponse()->headers->get('Location'));

        $sub = $this->latestText((int) $rider->getId());
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus(), 'still waiting for the decision');
        self::assertSame($rider->getId(), $sub->getUserId(), 'still the rider\'s suggestion');
        self::assertEquals(['text:nl' => ['was' => 'Testdorp is een dorp.', 'now' => 'Testdorp heeft een kerk en een molen.']], $sub->getChanges());
        $payload = $sub->getPayload();
        self::assertSame('Testdorp heeft een kerk en een molen.', $payload['text']);
        self::assertSame('nl', $payload['lang']);
        self::assertSame('Ik woon er.', $payload['details']['note'] ?? null, 'the rider\'s note stays');
        self::assertIsArray($payload['_corrected'] ?? null);
        self::assertSame($curatorA->getId(), $payload['_corrected']['by'], 'who corrected it');
        self::assertSame('Testdorp heeft een kerk en een moolen.', $payload['_corrected']['from'], 'what the rider sent, kept beside the correction');
        self::assertSame('Testdorp is een dorp.', $this->towns()->find(self::TOWN, 'nl')['extract'] ?? null, 'nothing shows before the decision');

        $crawler = $client->followRedirect();
        self::assertStringContainsString('Correction saved', (string) $client->getResponse()->getContent());
        $card = $crawler->filter('.q-item[data-item-id="'.$sub->getId().'"]');
        self::assertStringContainsString('Testdorp heeft een kerk en een molen.', $card->filter('.q-now')->text());
        self::assertSame('Corrected', $card->filter('.q-tag--corrected')->text());

        // A second correction keeps the rider's first words as the source.
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertSame('Testdorp heeft een kerk en een molen.', $crawler->filter('textarea#pt-text')->text('', false));
        $client->submit($crawler->filter('#place-text-form')->form(['text' => 'Testdorp heeft een kerk en een windmolen.']));
        self::assertSame('Testdorp heeft een kerk en een moolen.', $this->latestText((int) $rider->getId())->getPayload()['_corrected']['from']);
    }

    public function testApprovingACorrectedSuggestionShowsTheCorrectionCreditedToTheRider(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider23@example.com', [], null, 'Molenaar');
        $curatorA = $this->user('ptext-cur-a23@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp heeft een moolen.', 'nl');
        $sub = $this->latestText((int) $rider->getId());

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        $client->submit($crawler->filter('#place-text-form')->form(['text' => 'Testdorp heeft een molen.']));
        $this->approve($this->latestText((int) $rider->getId()), $curatorA, true);

        $row = $this->towns()->find(self::TOWN, 'nl');
        self::assertNotNull($row);
        self::assertSame('Testdorp heeft een molen.', $row['extract'], 'the corrected text goes live');
        self::assertSame($rider->getId(), $row['editedBy'], 'written by the rider');
        self::assertSame($curatorA->getId(), $row['approvedBy']);
        $sub = $this->latestText((int) $rider->getId());
        self::assertSame(SubmissionStatus::Approved, $sub->getStatus());
        self::assertSame($curatorA->getId(), $sub->getPayload()['_corrected']['by'] ?? null, 'the proposal keeps the correction on record');
    }

    public function testTheCorrectionFormApprovesWithANoteToTheWriter(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider26@example.com', [], null, 'Schrijver');
        $curatorA = $this->user('ptext-cur-a26@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp heeft een moolen.', 'nl');
        $sub = $this->latestText((int) $rider->getId());

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertSame('Approve', $crawler->filter('#place-text-form button[value=approve]')->text());
        self::assertCount(1, $crawler->filter('textarea#pt-reply'));

        // A note over the limit is sent back, with nothing changed.
        $client->submit($crawler->selectButton('Approve')->form(['text' => 'Testdorp heeft een molen.', 'reply' => str_repeat('x', PlaceTextProposals::REPLY_MAX + 1), 'credit' => 'keep']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(SubmissionStatus::Pending, $this->latestText((int) $rider->getId())->getStatus());
        self::assertSame('Testdorp heeft een moolen.', $this->latestText((int) $rider->getId())->getPayload()['text'], 'a refused approval keeps no correction');

        $client->submit($crawler->selectButton('Approve')->form(['text' => 'Testdorp heeft een molen.', 'reply' => 'Dank je, mooi geschreven.', 'credit' => 'keep']));
        self::assertResponseRedirects();
        self::assertStringEndsWith('/moderate/submissions', (string) $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        self::assertStringContainsString('Approved. The text is live', (string) $client->getResponse()->getContent());

        $sub = $this->latestText((int) $rider->getId());
        self::assertSame(SubmissionStatus::Approved, $sub->getStatus());
        self::assertSame('Dank je, mooi geschreven.', $sub->getDecisionNote(), 'the note goes to the writer with the approval');
        self::assertSame('Testdorp heeft een moolen.', $sub->getPayload()['_corrected']['from'] ?? null, 'the correction is on record');
        $row = $this->towns()->find(self::TOWN, 'nl');
        self::assertSame('Testdorp heeft een molen.', $row['extract'] ?? null, 'the corrected text goes live');
        self::assertSame($rider->getId(), $row['editedBy'] ?? null, 'credited to the writer');
    }

    public function testTheCorrectionFormApprovesAnUnchangedTextWithoutANote(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider27@example.com', []);
        $curatorA = $this->user('ptext-cur-a27@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp heeft een molen.', 'nl');
        $sub = $this->latestText((int) $rider->getId());

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        $client->submit($crawler->selectButton('Approve')->form(['credit' => 'keep']));
        self::assertResponseRedirects();

        $sub = $this->latestText((int) $rider->getId());
        self::assertSame(SubmissionStatus::Approved, $sub->getStatus());
        self::assertNull($sub->getDecisionNote());
        self::assertArrayNotHasKey('_corrected', $sub->getPayload(), 'nothing was corrected');
        self::assertSame('Testdorp heeft een molen.', $this->towns()->find(self::TOWN, 'nl')['extract'] ?? null);
    }

    public function testCorrectingARegionTextKeepsItInThatLocale(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $rider = $this->user('ptext-rider24@example.com', []);
        $curatorA = $this->user('ptext-cur-a24@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/ptext-a/text?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'Ptext A is flat and windey.', 'derived' => 'adapted']));
        $sub = $this->latestText((int) $rider->getId());

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('Wikipedia says Ptext.', $crawler->filter('blockquote#pt-current-text')->text());
        self::assertCount(0, $crawler->filter('#pt-source, input[name=derived]'), 'the claim is the writer\'s, not a field to correct');
        self::assertStringContainsString('The writer says: "I adapted this from the Wikipedia article".', $crawler->filter('#pt-credit-claim')->text(), 'the rider\'s adaptation claim, as sent, as the hint');
        $client->submit($crawler->filter('#place-text-form')->form(['text' => 'Ptext A is flat and windy.']));
        self::assertResponseRedirects();
        $this->approve($this->latestText((int) $rider->getId()), $curatorA, true);

        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        /** @var array<string, array<string, mixed>> $curated */
        $curated = json_decode((string) $db->fetchOne('SELECT context_curated FROM region WHERE id = :id', ['id' => $a->getId()]), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Ptext A is flat and windy.', $curated['en']['text']);
        self::assertTrue($curated['en']['derived']);
        self::assertSame($rider->getId(), $curated['en']['userId']);
    }

    public function testOnlyACuratorOfTheAreaCorrectsAndOnlyWhileItWaits(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a, $b] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider25@example.com', []);
        $curatorA = $this->user('ptext-cur-a25@example.com', ['ROLE_CURATOR'], $a);
        $curatorB = $this->user('ptext-cur-b25@example.com', ['ROLE_CURATOR'], $b);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp heeft een moolen.', 'nl');
        $sub = $this->latestText((int) $rider->getId());

        // A rider has no desk.
        $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertResponseStatusCodeSame(403);

        // A curator of another region is refused, reading and writing.
        $client->loginUser($curatorB);
        $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/moderate/text/'.$sub->getId(), ['_token' => 'x', 'text' => 'Van elders.']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/moderate/text/'.$sub->getId(), ['_token' => 'x', 'text' => 'Van elders.', 'do' => 'approve']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(SubmissionStatus::Pending, $this->latestText((int) $rider->getId())->getStatus(), 'nor approved from elsewhere');
        self::assertSame('Testdorp heeft een moolen.', $this->latestText((int) $rider->getId())->getPayload()['text']);
        self::assertFalse(static::getContainer()->get(DeskSeen::class)->isSeen((int) $curatorB->getId(), SeenSubject::Submission, (int) $sub->getId()), 'a refused page opens nothing');

        // No such submission, or one that is no text.
        $client->loginUser($curatorA);
        $client->request('GET', '/moderate/text/999999999');
        self::assertResponseStatusCodeSame(404);

        // Settled: the form is refused and the text stays as decided.
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        $form = $crawler->filter('#place-text-form')->form(['text' => 'Testdorp heeft een molen.']);
        $this->moderation()->decide((int) $sub->getId(), 'reject', $curatorA, 'Geen bron.');
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('no longer waiting for a decision', (string) $client->getResponse()->getContent());
        self::assertSame('Testdorp heeft een moolen.', $this->latestText((int) $rider->getId())->getPayload()['text']);
        $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertResponseRedirects();
    }

    public function testApprovingATextWithAnArticleNeedsTheCuratorsCreditDecision(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider30@example.com', [], null, 'Kerkfietser');
        $curatorA = $this->user('ptext-cur-a30@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        // The rider's form asks their own claim where the town has an article.
        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang=nl&name=Testdorp&lat='.self::LAT.'&lng='.self::LNG);
        self::assertCount(1, $crawler->filter('fieldset#pt-source'), 'the town form asks the writer\'s claim');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'nl', 'text' => 'Testdorp heeft een kerk.', 'derived' => 'own']));
        $sub = $this->latestText((int) $rider->getId());
        self::assertFalse($sub->getPayload()['derived'] ?? null, 'the writer says they wrote it fresh');

        // The correction form asks the curator, with no default, and shows the writer's claim.
        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertResponseIsSuccessful();
        $fieldset = $crawler->filter('fieldset#pt-credit');
        self::assertCount(1, $fieldset);
        self::assertSame('Wikipedia credit', trim(str_replace('*', '', $fieldset->filter('legend')->text())));
        self::assertSame('Keep the Wikipedia credit: the text is based on the Wikipedia article', $fieldset->filter('label[for=pt-credit-keep]')->text());
        self::assertSame('Drop the Wikipedia credit: the text is written fresh', $fieldset->filter('label[for=pt-credit-drop]')->text());
        foreach (['keep', 'drop'] as $v) {
            $radio = $fieldset->filter('input#pt-credit-'.$v);
            self::assertNotNull($radio->attr('required'), 'Approve needs the choice');
            self::assertNull($radio->attr('checked'), 'no default');
        }
        self::assertSame('The writer says: "I wrote my own text".', $crawler->filter('#pt-credit-claim')->text());
        self::assertNotNull($crawler->filter('#place-text-form button[value=save]')->attr('formnovalidate'), 'saving a correction does not need it');

        // Without the choice the approval is sent back, nothing changed.
        $client->submit($crawler->selectButton('Approve')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose whether the Wikipedia credit stays', (string) $client->getResponse()->getContent());
        self::assertSame(SubmissionStatus::Pending, $this->latestText((int) $rider->getId())->getStatus());
        self::assertSame('Testdorp is een dorp.', $this->towns()->find(self::TOWN, 'nl')['extract'] ?? null);

        // Saving a correction needs no choice.
        $client->submit($crawler->filter('#place-text-form')->form(['text' => 'Testdorp heeft een kerk en een plein.']));
        self::assertResponseRedirects();
        self::assertSame(SubmissionStatus::Pending, $this->latestText((int) $rider->getId())->getStatus());

        // Drop: no Wikipedia link, the writer's credit alone.
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        $client->submit($crawler->selectButton('Approve')->form(['credit' => 'drop']));
        self::assertResponseRedirects();
        $sub = $this->latestText((int) $rider->getId());
        self::assertSame(SubmissionStatus::Approved, $sub->getStatus());
        self::assertFalse($sub->getPayload()['derived']);
        self::assertSame($curatorA->getId(), $sub->getPayload()['_credit']['by'] ?? null, 'who decided the credit');
        self::assertFalse($sub->getPayload()['_credit']['claim'], 'what the writer had claimed, kept');
        $row = $this->towns()->find(self::TOWN, 'nl');
        self::assertFalse($row['derived'] ?? null);
        $client->request('GET', '/map/town/'.self::TOWN.'?lang=nl');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($data['derived'], 'the card drops the Wikipedia link and "after Wikipedia"');
        self::assertSame(['name' => 'Kerkfietser'], $data['editedBy']);
    }

    public function testKeepingTheCreditKeepsTheWikipediaLinkOnARegion(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $rider = $this->user('ptext-rider31@example.com', [], null, 'Heuvelfietser');
        $curatorA = $this->user('ptext-cur-a31@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/ptext-a/text?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'Ptext A has one hill.', 'derived' => 'own']));
        $sub = $this->latestText((int) $rider->getId());

        // The curator keeps the credit against the writer's claim.
        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        $client->submit($crawler->selectButton('Approve')->form(['credit' => 'keep']));
        self::assertResponseRedirects();
        $client->request('GET', '/regions/ptext-a');
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Adapted from Wikipedia by Heuvelfietser:', $page);
        self::assertStringContainsString('https://en.wikipedia.org/wiki/Ptext', $page);

        // Dropped on a second text: no citation, the writer alone.
        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/ptext-a/text?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'Ptext A has two hills.', 'derived' => 'own']));
        $sub = $this->latestText((int) $rider->getId());
        $this->approve($sub, $curatorA, false);
        $client->request('GET', '/regions/ptext-a');
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Written by Heuvelfietser,', $page);
        self::assertCount(1, $client->getCrawler()->filter('.rg-about-attrib a[href="https://creativecommons.org/licenses/by-sa/4.0/"]'), 'a fresh text carries the licence too');
        self::assertStringNotContainsString('https://en.wikipedia.org/wiki/Ptext', $page);
    }

    public function testTheGenericDecisionRefusesATextWithAnArticleAndNoCreditDecision(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider32@example.com', []);
        $curatorA = $this->user('ptext-cur-a32@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $this->propose($client, 'Testdorp heeft een brug.', 'nl');
        $sub = $this->latestText((int) $rider->getId());

        try {
            $this->moderation()->decide((int) $sub->getId(), 'approve', $curatorA, null);
            self::fail('a text with an article is not approved without the credit decision');
        } catch (TextCreditUndecidedException) {
        }
        self::assertSame(SubmissionStatus::Pending, $this->latestText((int) $rider->getId())->getStatus());

        // The map's decision POST says why, and where to approve it.
        $client->loginUser($curatorA);
        $client->request('GET', '/map');
        $token = $this->decisionToken($client);
        $client->request('POST', '/moderate/decide', ['moderation_decision' => [
            'submission_id' => (string) $sub->getId(), 'decision' => 'approve', 'note' => '', 'media_reject' => '[]', '_token' => $token,
        ]], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        /** @var array<string, mixed> $json */
        $json = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('text_credit_undecided', $json['error']);
        self::assertStringEndsWith('/moderate/text/'.$sub->getId(), (string) $json['form']);
        self::assertSame(SubmissionStatus::Pending, $this->latestText((int) $rider->getId())->getStatus());

        // Reject and needs-info stay on the map. A refused decision closes
        // the request's entity manager, as any refused transaction does.
        static::getContainer()->get('doctrine')->resetManager();
        $this->moderation()->decide((int) $sub->getId(), 'reject', $curatorA, 'Geen bron.');
        self::assertSame(SubmissionStatus::Rejected, $this->latestText((int) $rider->getId())->getStatus());
    }

    public function testATextWithNoArticleIsApprovedWithoutTheQuestion(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('nl', 'Testdorp is een dorp.');
        $rider = $this->user('ptext-rider33@example.com', [], null, 'Nieuwschrijver');
        $curatorA = $this->user('ptext-cur-a33@example.com', ['ROLE_CURATOR'], $a);

        // French: nobody has opened it, so there is no article to credit.
        $client->loginUser($rider);
        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang=fr&name=Testdorp&lat='.self::LAT.'&lng='.self::LNG);
        $source = $crawler->filter('fieldset#pt-source');
        self::assertNotNull($source->attr('hidden'), 'no claim to make');
        self::assertNotNull($source->attr('disabled'), 'so its required radios never block the send');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'fr', 'text' => 'Testdorp a une église.']));
        $sub = $this->latestText((int) $rider->getId());

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertCount(0, $crawler->filter('fieldset#pt-credit'), 'no question');
        $client->submit($crawler->selectButton('Approve')->form());
        self::assertResponseRedirects();
        $row = $this->towns()->find(self::TOWN, 'fr');
        self::assertSame('Testdorp a une église.', $row['extract'] ?? null);
        self::assertFalse($row['derived'] ?? null);

        // The generic decision needs no credit decision either.
        $client->loginUser($rider);
        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang=fr&name=Testdorp');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'fr', 'text' => 'Testdorp a une église et un pont.']));
        $sub = $this->latestText((int) $rider->getId());
        $this->moderation()->decide((int) $sub->getId(), 'approve', $curatorA, null);
        self::assertSame('Testdorp a une église et un pont.', $this->towns()->find(self::TOWN, 'fr')['extract'] ?? null);
    }

    public function testACuratorsOwnTextAppliesWithTheirOwnAnswerAsTheDecision(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->fetchedTown('en', 'Testville is a village.');
        $curatorA = $this->user('ptext-cur-a34@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang=en&name=Testville&lat='.self::LAT.'&lng='.self::LNG);
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'Testville, written fresh by its curator.', 'derived' => 'own']));
        self::assertStringContainsString('Text updated', (string) $client->getResponse()->getContent());
        $sub = $this->latestText((int) $curatorA->getId());
        self::assertSame(SubmissionStatus::Approved, $sub->getStatus());
        self::assertSame($curatorA->getId(), $sub->getPayload()['_credit']['by'] ?? null, 'their answer recorded as the decision');
        self::assertFalse($this->towns()->find(self::TOWN, 'en')['derived'] ?? null);

        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang=en&name=Testville');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'text' => 'Testville, adapted from the article by its curator.', 'derived' => 'adapted']));
        self::assertTrue($this->towns()->find(self::TOWN, 'en')['derived'] ?? null, 'adapted: the credit stays');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** Approve on the text's form, with the curator's decision on the Wikipedia credit. */
    private function approve(Submission $submission, User $curator, bool $keepCredit): void
    {
        /** @var PlaceTextProposals $proposals */
        $proposals = static::getContainer()->get(PlaceTextProposals::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = $em->find(Submission::class, (int) $submission->getId());
        self::assertInstanceOf(Submission::class, $submission);
        $proposals->approve($submission, $curator, (string) $submission->getPayload()['text'], $keepCredit, '');
    }

    /** The decision token the map hands a curator (templates/map/index.html.twig). */
    private function decisionToken(KernelBrowser $client): string
    {
        self::assertSame(1, preg_match('/window\.CC_MOD_TOKEN = "([^"]+)"/', (string) $client->getResponse()->getContent(), $m), 'the map carries the decision token');

        return $m[1];
    }

    /** Sends the town form; `$source` answers "Where your text comes from" (adapted or own). */
    private function propose(KernelBrowser $client, string $text, string $lang, string $note = '', string $source = 'adapted'): void
    {
        $crawler = $client->request('GET', '/town/'.self::TOWN.'/text?lang='.$lang.'&name=Testdorp&lat='.self::LAT.'&lng='.self::LNG);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => $lang, 'text' => $text, 'note' => $note, 'derived' => $source]));
    }

    /** @return array{0: Region, 1: Region} */
    private function twoRegions(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = (new Region())->setSlug('ptext-a')->setName('Ptext A')->setCountryCode('PN')->setAreaKm2(1.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-141,-41],[-140,-41],[-140,-40],[-141,-40],[-141,-41]]]]}');
        $b = (new Region())->setSlug('ptext-b')->setName('Ptext B')->setCountryCode('CK')->setAreaKm2(1.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-121,-41],[-120,-41],[-120,-40],[-121,-40],[-121,-41]]]]}');
        $em->persist($a);
        $em->persist($b);
        $em->flush();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('UPDATE region SET context = :c WHERE id = :id', [
            'c' => '{"en":{"title":"Ptext","extract":"Wikipedia says Ptext.","url":"https://en.wikipedia.org/wiki/Ptext"}}',
            'id' => (int) $a->getId(),
        ]);

        return [$a, $b];
    }

    private function fetchedTown(string $lang, string $extract): void
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('DELETE FROM town_summary WHERE osm_ref = :r', ['r' => self::TOWN]);
        $db->executeStatement('DELETE FROM town_place WHERE osm_ref = :r', ['r' => self::TOWN]);
        $this->towns()->claim(self::TOWN, $lang);
        $this->towns()->record(self::TOWN, $lang, 'Q99999931', ['title' => 'Testdorp', 'extract' => $extract, 'url' => 'https://'.$lang.'.wikipedia.org/wiki/Testdorp', 'lang' => $lang], []);
        /** @var TownPlaceRepository $places */
        $places = static::getContainer()->get(TownPlaceRepository::class);
        $places->record(self::TOWN, self::LAT, self::LNG);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles, ?Region $area = null, ?string $publicName = null): User
    {
        $c = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($publicName ?? (strstr($email, '@', true) ?: $email));
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if (null !== $publicName) {
            $user->setPublicProfile(true);
        }
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        if (null !== $area) {
            $em->persist(new ModeratorArea((int) $user->getId(), (int) $area->getId(), null));
            $em->flush();
        }

        return $user;
    }

    private function latestText(int $userId): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $sub = $em->getRepository(Submission::class)->findOneBy(['userId' => $userId, 'type' => SubmissionType::Text], ['id' => 'DESC']);
        self::assertInstanceOf(Submission::class, $sub, 'a Text submission was filed');

        return $sub;
    }

    /** @return list<int> */
    private function queueIds(User $curator): array
    {
        $c = static::getContainer();
        /** @var ModerationScopeProvider $scopes */
        $scopes = $c->get(ModerationScopeProvider::class);
        /** @var SubmissionQueue $queue */
        $queue = $c->get(SubmissionQueue::class);

        return array_map(static fn (array $r): int => $r['id'], $queue->pendingForMap($scopes->scopeFor($curator)));
    }

    private function towns(): TownSummaryRepository
    {
        /** @var TownSummaryRepository $towns */
        $towns = static::getContainer()->get(TownSummaryRepository::class);

        return $towns;
    }

    private function moderation(): ModerationService
    {
        /** @var ModerationService $m */
        $m = static::getContainer()->get(ModerationService::class);

        return $m;
    }
}
