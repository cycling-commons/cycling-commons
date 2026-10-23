<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Contribution\PlaceTextProposals;
use App\Contribution\PlaceTextRefused;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Moderation\Entity\ModeratorArea;
use App\Tests\Town\FakeTownPointSource;
use App\Town\TownPlaceRepository;
use App\Town\TownPointSource;
use App\Town\TownSummaryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A translation knows its original (moderation-and-contribution.md §3.1e):
 * the writer says which language they translated, the approved text keeps
 * that original's language and stamp, readers see "Translated from ...",
 * and when the original changes the translator is told and readers see it
 * changed since. The same for a town card's text.
 *
 * One region far from any real one. DAMA wraps each test in a rolled-back
 * transaction.
 */
final class TextLineageTest extends WebTestCase
{
    private const string TOWN = 'node/999990777';

    public function testATranslationKnowsItsOriginalAndItsTranslatorHearsWhenItChanges(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$region, $curator] = $this->scene();
        $writer = $this->user('lin-writer@example.com', []);
        $translator = $this->user('lin-translator@example.com', []);

        // The English original, written and approved.
        $en = $this->proposals()->proposeStory($writer, $this->info(), 'en', 'Polders everywhere.', '')['submission'];
        $this->proposals()->approve($this->fresh($en), $curator, 'Polders everywhere.', null, '');

        // The form offers it as an original, and a translation from it says so.
        $client->loginUser($translator);
        $crawler = $client->request('GET', '/regions/lin-a/story?lang=nl');
        self::assertSame(['', 'en'], $crawler->filter('#pt-story-based-on option:not([disabled])')->each(static fn ($o): string => (string) $o->attr('value')), 'only a language with a text written here is offered');
        self::assertSame('Translation of: Français (no text yet)', $crawler->filter('#pt-story-based-on option[value=fr][disabled][data-none]')->text(), 'the others are listed, not offered');
        self::assertSame('Polders everywhere.', $crawler->filter('#pt-translate blockquote')->text(), 'the original shows, to translate from');
        // The geography step asks it too, with no original to pick yet.
        self::assertSame([''], $crawler->filter('#pt-based-on option:not([disabled])')->each(static fn ($o): string => (string) $o->attr('value')));
        self::assertSame('en', $crawler->filter('#pt-story-based-on option[selected]')->attr('value'), 'the text shown to translate from is chosen');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'nl', 'story' => 'Overal polders.']));
        $nl = $this->latest((int) $translator->getId());
        self::assertSame('en', $nl->getPayload()['_basedOn']['lang']);
        $this->proposals()->approve($this->fresh($nl), $curator, 'Overal polders.', null, '');
        self::assertSame('en', $this->story($region)['nl']['basedOn']['lang']);

        $crawler = $client->request('GET', '/nl/regios/lin-a');
        self::assertSame('Vertaald uit de oorspronkelijke tekst in het Engels.', trim($crawler->filter('#story .rg-translated')->text()));

        // The curator's card says it is a translation.
        $client->loginUser($curator);
        $crawler = $client->request('GET', '/moderate/submissions/history');
        self::assertSame('NL ← EN', $crawler->filter('.q-item[data-submission-id="'.$nl->getId().'"] .q-tag--lang')->text());

        // The original changes: the translator is told, the reader sees it.
        $en2 = $this->proposals()->proposeStory($writer, $this->info(), 'en', 'Polders everywhere, and dikes.', '')['submission'];
        $this->proposals()->approve($this->fresh($en2), $curator, 'Polders everywhere, and dikes.', null, '');
        $told = $this->em()->getRepository(UserMessage::class)->findBy(['userId' => $translator->getId(), 'kind' => UserMessageKind::TextOriginalChanged->value]);
        self::assertCount(1, $told, 'the translator hears the original changed');
        self::assertSame('/regions/lin-a/story?lang=nl', $told[0]->getBodyParams()['%url%'] ?? null);
        self::assertCount(0, $this->em()->getRepository(UserMessage::class)->findBy(['userId' => $writer->getId(), 'kind' => UserMessageKind::TextOriginalChanged->value]), 'not the writer of the change');

        $crawler = $client->request('GET', '/nl/regios/lin-a');
        self::assertSame('Vertaald uit de oorspronkelijke tekst in het Engels. Het origineel is sindsdien veranderd.', trim($crawler->filter('#story .rg-translated')->text()));
    }

    public function testATranslationNamesALanguageThatHasAText(): void
    {
        static::createClient()->disableReboot();
        $this->scene();
        $rider = $this->user('lin-rider@example.com', []);

        foreach (['fr', 'nl'] as $from) {
            try {
                $this->proposals()->proposeStory($rider, $this->info(), 'nl', 'Overal polders.', '', false, $from);
                self::fail($from.': no text to translate from, or its own language');
            } catch (PlaceTextRefused $e) {
                self::assertSame('place_text.error.based_on', $e->getMessage());
            }
        }
    }

    public function testATownTextKnowsItsOriginalToo(): void
    {
        static::createClient()->disableReboot();
        [, $curator] = $this->scene();
        $writer = $this->user('lin-town-writer@example.com', []);
        $translator = $this->user('lin-town-translator@example.com', []);
        $this->town();

        $en = $this->proposals()->proposeTown($writer, self::TOWN, 'en', 'A village on the dike.', '', 'Testdorp', false)['submission'];
        $this->proposals()->approve($this->fresh($en), $curator, 'A village on the dike.', false, '');
        $nl = $this->proposals()->proposeTown($translator, self::TOWN, 'nl', 'Een dorp aan de dijk.', '', 'Testdorp', null, 'en')['submission'];
        $this->proposals()->approve($this->fresh($nl), $curator, 'Een dorp aan de dijk.', null, '');
        self::assertSame('en', $this->db()->fetchOne('SELECT based_on_lang FROM town_summary WHERE osm_ref = :r AND lang = :l', ['r' => self::TOWN, 'l' => 'nl']));

        $en2 = $this->proposals()->proposeTown($writer, self::TOWN, 'en', 'A village on the old dike.', '', 'Testdorp', false)['submission'];
        $this->proposals()->approve($this->fresh($en2), $curator, 'A village on the old dike.', false, '');
        $told = $this->em()->getRepository(UserMessage::class)->findBy(['userId' => $translator->getId(), 'kind' => UserMessageKind::TextOriginalChanged->value]);
        self::assertCount(1, $told);
        self::assertStringStartsWith('/town/node/999990777/text?', (string) ($told[0]->getBodyParams()['%url%'] ?? ''));
        self::assertStringContainsString('lang=nl', (string) ($told[0]->getBodyParams()['%url%'] ?? ''));
    }

    /** @return array{0: Region, 1: User} */
    private function scene(): array
    {
        $region = (new Region())->setSlug('lin-a')->setName('Lin A')->setCountryCode('PN')->setAreaKm2(1.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-141,-41],[-140,-41],[-140,-40],[-141,-40],[-141,-41]]]]}');
        $this->em()->persist($region);
        $this->em()->flush();

        return [$region, $this->user('lin-cur@example.com', ['ROLE_CURATOR'], $region)];
    }

    /** @return array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} */
    private function info(): array
    {
        $info = $this->proposals()->region('lin-a');
        self::assertNotNull($info);

        return $info;
    }

    private function town(): void
    {
        $this->db()->executeStatement('DELETE FROM town_summary WHERE osm_ref = :r', ['r' => self::TOWN]);
        $this->db()->executeStatement('DELETE FROM town_place WHERE osm_ref = :r', ['r' => self::TOWN]);
        // A town is looked up once somebody opened it: its fetched row first.
        /** @var TownSummaryRepository $towns */
        $towns = static::getContainer()->get(TownSummaryRepository::class);
        $towns->claim(self::TOWN, 'en');
        $towns->record(self::TOWN, 'en', 'Q99999977', ['title' => 'Testdorp', 'extract' => 'Testdorp is a village.', 'url' => 'https://en.wikipedia.org/wiki/Testdorp', 'lang' => 'en'], []);
        /** @var FakeTownPointSource $osm */
        $osm = static::getContainer()->get(TownPointSource::class);
        $osm->place(self::TOWN, -40.5, -140.5);
        /** @var TownPlaceRepository $places */
        $places = static::getContainer()->get(TownPlaceRepository::class);
        self::assertNotNull($places->locate(self::TOWN));
    }

    /** @return array<string, array<string, mixed>> */
    private function story(Region $region): array
    {
        /* @var array<string, array<string, mixed>> */
        return json_decode((string) $this->db()->fetchOne('SELECT story FROM region WHERE id = :id', ['id' => $region->getId()]), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles, ?Region $area = null): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'securepass12345!'));
        $this->em()->persist($user);
        $this->em()->flush();
        if (null !== $area) {
            $this->em()->persist(new ModeratorArea((int) $user->getId(), (int) $area->getId(), null));
            $this->em()->flush();
        }

        return $user;
    }

    private function latest(int $userId): Submission
    {
        $sub = $this->em()->getRepository(Submission::class)->findOneBy(['userId' => $userId], ['id' => 'DESC']);
        self::assertInstanceOf(Submission::class, $sub);
        $this->em()->refresh($sub);

        return $sub;
    }

    private function fresh(Submission $submission): Submission
    {
        $fresh = $this->em()->find(Submission::class, (int) $submission->getId());
        self::assertInstanceOf(Submission::class, $fresh);
        $this->em()->refresh($fresh);

        return $fresh;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    private function db(): Connection
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        return $db;
    }

    private function proposals(): PlaceTextProposals
    {
        /** @var PlaceTextProposals $p */
        $p = static::getContainer()->get(PlaceTextProposals::class);

        return $p;
    }
}
