<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Town\TownSummaryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A curator writes over a town card's fetched text; the row is local from
 * then on (map-and-search.md §6.5, owner 2026-09-08: "once updated we can't
 * connect to online anymore, it is then local info only").
 */
final class ModerateTownControllerTest extends WebTestCase
{
    public function testACuratorsWordsReplaceTheFetchedTextAndMarkTheRowLocal(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, 'town-curator@example.com', ['ROLE_CURATOR']);
        $this->clear('node/999990201');
        /** @var TownSummaryRepository $towns */
        $towns = self::getContainer()->get(TownSummaryRepository::class);
        $towns->claim('node/999990201', 'nl');
        $towns->record('node/999990201', 'nl', 'Q99999901', ['title' => 'Testdorp', 'extract' => 'Testdorp is een dorp.', 'url' => 'https://nl.wikipedia.org/wiki/Testdorp', 'lang' => 'nl'], []);

        $crawler = $client->request('GET', '/moderate/town/node/999990201');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Testdorp is een dorp.', (string) $client->getResponse()->getContent(), 'the fetched text is shown to write over');

        $nl = $crawler->filter('form.townform')->reduce(static fn ($node) => 'nl' === $node->filter('input[name=lang]')->attr('value'))->first();
        // The licence question where there is an article: checked while the box starts from it.
        self::assertNotNull($nl->filter('input[name=derived]')->attr('checked'));
        self::assertCount(0, $crawler->filter('form.townform')->reduce(static fn ($node) => 'fr' === $node->filter('input[name=lang]')->attr('value'))->filter('input[name=derived]'), 'no article, no question');
        $form = $nl->form([
            'text' => 'Testdorp: vlak, kasseien in het centrum, start van de dorpsronde.',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Town text saved', (string) $client->getResponse()->getContent());

        $row = $towns->find('node/999990201', 'nl');
        self::assertNotNull($row);
        self::assertTrue($row['edited']);
        self::assertStringStartsWith('Testdorp: vlak', (string) $row['extract']);
        self::assertSame('Testdorp', $row['title'], 'the title stays');

        // The map endpoint says so, and the credit line follows it.
        $client->request('GET', '/map/town/node/999990201?lang=nl');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($data['edited']);
        self::assertTrue($data['derived'], 'adapted from the article: the Wikipedia credit stays');
        self::assertStringStartsWith('Testdorp: vlak', $data['text']['extract']);

        // Unticked: written fresh, so the card credits our curators alone.
        $crawler = $client->request('GET', '/moderate/town/node/999990201');
        $form = $crawler->filter('form.townform')->reduce(static fn ($node) => 'nl' === $node->filter('input[name=lang]')->attr('value'))->first()->form([
            'text' => 'Testdorp: een dorp met een molen.',
        ]);
        $form['derived']->untick();
        $client->submit($form);
        self::assertFalse($towns->find('node/999990201', 'nl')['derived'] ?? null);
    }

    public function testALanguageNobodyOpenedYetCanStillBeWritten(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, 'town-curator-2@example.com', ['ROLE_CURATOR']);
        $this->clear('node/999990202');

        $crawler = $client->request('GET', '/moderate/town/node/999990202');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form.townform input[name=_token]')->first()->attr('value');
        $client->request('POST', '/moderate/town/node/999990202', [
            '_token' => $token, 'lang' => 'fr', 'text' => 'Village test.', 'title' => 'Village',
        ]);
        self::assertResponseRedirects();
        /** @var TownSummaryRepository $towns */
        $towns = self::getContainer()->get(TownSummaryRepository::class);
        $row = $towns->find('node/999990202', 'fr');
        self::assertNotNull($row);
        self::assertTrue($row['answered'] && $row['edited']);
        self::assertSame('Village', $row['title']);
        self::assertFalse($towns->claim('node/999990202', 'fr'), 'and no fetch will ever claim it');
    }

    public function testARiderCannotOpenThePen(): void
    {
        $client = static::createClient();
        $this->login($client, 'town-rider@example.com', []);
        $client->request('GET', '/moderate/town/node/999990203');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheReportFormTakesATown(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/report/town/node-999990204?name=Zwaag%20%3Cb%3Ex%3C%2Fb%3E');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('A town card', $crawler->text());
        // The name is ours to read, never the link's (ContentReportTest).
        self::assertStringNotContainsString('This report is about', $crawler->text(), 'no town text read yet, so no name');
        self::assertCount(0, $crawler->filter('input[name="name"]'));
        $client->request('GET', '/report/town/node/999990204');
        self::assertResponseStatusCodeSame(404, 'the id is one segment, never a path');
    }

    /** @param list<string> $roles */
    private function login(KernelBrowser $client, string $email, array $roles): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Town pen');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }

    private function clear(string $ref): void
    {
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $db->executeStatement('DELETE FROM town_summary WHERE osm_ref = :r', ['r' => $ref]);
    }
}
