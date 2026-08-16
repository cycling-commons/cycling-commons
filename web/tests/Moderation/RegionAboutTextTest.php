<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\RegionLead;
use App\Entity\User;
use App\Moderation\Entity\ModeratorArea;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Curator-written region leads (owner 2026-08-16).
 *
 * Two things are under test and they are not the same thing. The first is
 * jurisdiction, which every moderation write shares: a curator may only edit
 * the lead of a region inside their areas, re-checked on the POST rather than
 * trusted from the form that rendered it.
 *
 * The second is the ATTRIBUTION rule, and it is the reason the feature is not
 * just a text column. A Wikipedia extract is CC BY-SA 4.0, so what the curator
 * did to it decides what the page must say: an adapted text keeps the citation
 * AND must declare that it was changed, while an original text must carry no
 * Wikipedia credit at all. Getting that backwards either strips a licence or
 * credits Wikipedia for words it never wrote.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class RegionAboutTextTest extends WebTestCase
{
    private const string WIKI = '{"en":{"title":"Aargau","extract":"Wikipedia says Aargau.","url":"https://en.wikipedia.org/wiki/Aargau"},'
        .'"nl":{"title":"Aargau","extract":"Wikipedia zegt Aargau.","url":"https://nl.wikipedia.org/wiki/Aargau"}}';

    private function curator(string $email, bool $admin = false): User
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
        $user->setRoles($admin ? ['ROLE_ADMIN'] : ['ROLE_CURATOR']);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedRegion(string $slug, ?string $wiki = self::WIKI): Region
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug($slug)->setName(ucfirst($slug))->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $em->persist($region);
        $em->flush();

        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('UPDATE region SET context = :c WHERE id = :id', ['c' => $wiki, 'id' => (int) $region->getId()]);

        return $region;
    }

    private function assignTo(User $user, Region $region): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ModeratorArea((int) $user->getId(), (int) $region->getId(), null));
        $em->flush();
    }

    /** The editor renders the token; read it back the way the browser would. */
    private function token(KernelBrowser $client, string $slug): string
    {
        $client->request('GET', '/moderate/regions/'.$slug.'/about');
        self::assertResponseIsSuccessful();
        self::assertSame(1, preg_match(
            '/name="_token" value="([^"]+)"/',
            (string) $client->getResponse()->getContent(),
            $m,
        ));

        return $m[1];
    }

    /** @return array<string, mixed>|null */
    private function stored(int $regionId): ?array
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $raw = $db->fetchOne('SELECT context_curated FROM region WHERE id = :id', ['id' => $regionId]);
        if (!\is_string($raw)) {
            return null;
        }

        /* @var array<string, mixed> */
        return json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testTheEditorShowsTheHarvestedTextSoTheCuratorCanJudgeIt(): void
    {
        $client = static::createClient();
        $region = $this->seedRegion('about-editor');
        $curator = $this->curator('about-editor@example.com', admin: true);
        $client->loginUser($curator, 'main');

        $client->request('GET', '/moderate/regions/about-editor/about');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        // Both harvested languages are quoted, so the curator decides against
        // the real text rather than against a memory of it.
        self::assertStringContainsString('Wikipedia says Aargau.', $html);
        self::assertStringContainsString('Wikipedia zegt Aargau.', $html);
        // A locale with no article says so plainly rather than showing a blank.
        self::assertStringContainsString('Wikipedia has no article for this region', $html);
        self::assertNotNull($region->getId());

        /* THE MODERATOR SHELL, and this is a regression guard rather than a
           nicety (owner-reported 2026-08-16). A desk template that omits the
           `chrome` block silently falls back to the PUBLIC header, so a curator
           who opens one region loses every way back to the desks - and it fails
           silently, because the page still renders and still works. */
        self::assertStringContainsString('dtabs-modmode', $html, 'the moderator tab bar must be here');
        self::assertStringContainsString('/moderate/regions" class="dtab-mod on', $html,
            'and the Regions tab stays lit: this is a sub-page of that desk');
    }

    /**
     * The whole point of the second column: `app:regions:import-context`
     * replaces `context` wholesale, and the override must still be there
     * afterwards. Held here rather than in the command's own test because it
     * is the OVERRIDE's promise, and a future change to the importer should
     * break this test by name.
     */
    public function testAnOverrideSurvivesAWikipediaReImport(): void
    {
        $client = static::createClient();
        $region = $this->seedRegion('about-survives');
        $curator = $this->curator('about-survives@example.com', admin: true);
        $client->loginUser($curator, 'main');
        $id = (int) $region->getId();

        $client->request('POST', '/moderate/regions/about-survives/about', [
            '_token' => $this->token($client, 'about-survives'),
            'text_en' => 'A curator wrote this lead.',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects();

        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        // Exactly what the importer does to this region.
        $db->executeStatement(
            'UPDATE region SET context = :c WHERE id = :id',
            ['c' => '{"en":{"title":"Aargau","extract":"A freshly harvested lead.","url":"https://en.wikipedia.org/wiki/Aargau"}}', 'id' => $id],
        );

        $stored = $this->stored($id);
        self::assertIsArray($stored);
        self::assertSame('A curator wrote this lead.', $stored['en']['text'] ?? null);
    }

    /**
     * Original work carries no Wikipedia credit, adapted work carries the
     * credit AND says it was changed. The two halves are one test because the
     * failure mode is swapping them.
     */
    public function testTheCreditLineFollowsWhatTheCuratorActuallyDid(): void
    {
        $wiki = json_decode(self::WIKI, true);

        $written = RegionLead::resolve($wiki, ['en' => ['text' => 'Our own words.', 'derived' => false]], 'en');
        self::assertIsArray($written);
        self::assertSame('Our own words.', $written['text']);
        self::assertNull($written['url'], 'text we wrote must not cite an article it did not come from');
        self::assertFalse($written['adapted']);

        $adapted = RegionLead::resolve($wiki, ['en' => ['text' => 'Wikipedia says Aargau, tidied.', 'derived' => true]], 'en');
        self::assertIsArray($adapted);
        self::assertTrue($adapted['adapted'], 'the page must declare that the text was changed');
        self::assertSame('https://en.wikipedia.org/wiki/Aargau', $adapted['url'], 'CC BY-SA follows a derivative');
    }

    /**
     * A curator may adapt the ENGLISH article into their own language - a
     * translation is a derivative work - so the claim is allowed where the
     * reader's locale has no article but English does. Where NEITHER exists,
     * there is nothing to adapt and the claim is dropped rather than honoured
     * with a citation pointing at nothing.
     */
    public function testAdaptationIsOnlyClaimableWhereThereIsSomethingToAdapt(): void
    {
        $wiki = json_decode(self::WIKI, true);
        self::assertTrue(RegionLead::hasSource($wiki, 'nl'), 'Dutch has its own article');
        self::assertTrue(RegionLead::hasSource($wiki, 'fr'), 'French can be adapted from the English lead');
        self::assertFalse(RegionLead::hasSource(null, 'fr'), 'a region with no harvest has nothing to adapt');

        $client = static::createClient();
        $region = $this->seedRegion('about-noclaim', wiki: null);
        $curator = $this->curator('about-noclaim@example.com', admin: true);
        $client->loginUser($curator, 'main');

        $client->request('POST', '/moderate/regions/about-noclaim/about', [
            '_token' => $this->token($client, 'about-noclaim'),
            'text_fr' => 'Un texte de curateur.',
            'derived_fr' => '1',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects();

        $stored = $this->stored((int) $region->getId());
        self::assertIsArray($stored);
        self::assertFalse($stored['fr']['derived'] ?? null, 'no article, so no citation may be claimed');
    }

    /** An emptied box removes the override; the harvest comes back by itself. */
    public function testClearingEveryBoxRemovesTheOverrideEntirely(): void
    {
        $client = static::createClient();
        $region = $this->seedRegion('about-clear');
        $curator = $this->curator('about-clear@example.com', admin: true);
        $client->loginUser($curator, 'main');
        $id = (int) $region->getId();

        $client->request('POST', '/moderate/regions/about-clear/about', [
            '_token' => $this->token($client, 'about-clear'), 'text_en' => 'Temporary.',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertIsArray($this->stored($id));

        $client->request('POST', '/moderate/regions/about-clear/about', [
            '_token' => $this->token($client, 'about-clear'), 'text_en' => '   ',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertNull($this->stored($id), 'a cleared override reads the same as one that never existed');

        // And the page falls back to the harvest with its plain citation.
        $back = RegionLead::resolve(json_decode(self::WIKI, true), null, 'en');
        self::assertIsArray($back);
        self::assertSame('Wikipedia says Aargau.', $back['text']);
        self::assertFalse($back['curated']);
    }

    /** Jurisdiction, re-checked on the POST and not trusted from the form. */
    public function testACuratorCannotEditARegionOutsideTheirAreas(): void
    {
        $client = static::createClient();
        $mine = $this->seedRegion('about-mine');
        $theirs = $this->seedRegion('about-theirs');
        $curator = $this->curator('about-scope@example.com');
        $this->assignTo($curator, $mine);
        $client->loginUser($curator, 'main');

        $client->request('GET', '/moderate/regions/about-theirs/about');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/moderate/regions/about-theirs/about', [
            '_token' => $this->token($client, 'about-mine'),
            'text_en' => 'Not my region.',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->stored((int) $theirs->getId()));
    }

    /** A lead is a paragraph; an essay is refused rather than truncated. */
    public function testAnOverlongLeadIsRefused(): void
    {
        $client = static::createClient();
        $region = $this->seedRegion('about-long');
        $curator = $this->curator('about-long@example.com', admin: true);
        $client->loginUser($curator, 'main');

        $client->request('POST', '/moderate/regions/about-long/about', [
            '_token' => $this->token($client, 'about-long'),
            'text_en' => str_repeat('a', 1201),
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects();
        self::assertNull($this->stored((int) $region->getId()));
    }

    /**
     * A Wikipedia article in the READER's language beats a curator lead
     * written in one they may not read; English is the last resort on both
     * sides. Easy to get wrong by making "curated always wins" global.
     */
    public function testTheReadersOwnLanguageWinsBeforeAnyFallback(): void
    {
        $wiki = json_decode(self::WIKI, true);
        $curated = ['en' => ['text' => 'English curator lead.', 'derived' => false]];

        $dutch = RegionLead::resolve($wiki, $curated, 'nl');
        self::assertIsArray($dutch);
        self::assertSame('Wikipedia zegt Aargau.', $dutch['text'], 'a Dutch article beats an English curator lead');

        $french = RegionLead::resolve($wiki, $curated, 'fr');
        self::assertIsArray($french);
        self::assertSame('English curator lead.', $french['text'], 'with no French of either kind, English curated wins');
    }
}
