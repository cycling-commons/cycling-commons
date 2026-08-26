<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\CatalogFinding;
use App\Catalog\Entity\Item;
use App\Catalog\FindingKind;
use App\Catalog\FindingStatus;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The curator data desk (/moderate/data).
 *
 * Machine-raised findings about catalog rows had nowhere to be seen: they were
 * console output, which in practice meant nobody saw them. This is that
 * somewhere, and it is deliberately NOT the submission queue — nobody proposed
 * these, so there is no rider owed a reply.
 *
 * The two assertions worth breaking a build over are the ones that decide
 * whether the desk is still trustworthy in six months: a dismissal must stick,
 * and a curator must not be able to act outside their own area.
 *
 * @see docs/specs/catalog-data-model.md §5c
 */
final class ModerateDataDeskTest extends WebTestCase
{
    public function testAnonymousIsSentToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/moderate/data');

        self::assertResponseRedirects();
    }

    public function testARiderMayNotOpenIt(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('data-rider@test.test', ['ROLE_USER']));
        $client->request('GET', '/moderate/data');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDeskListsAnOpenDuplicateFinding(): void
    {
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('koru');
        $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->curator('data-list@test.test'));
        $crawler = $client->request('GET', '/moderate/data');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        // Both sides named: the curator is answering "are these the same
        // thing", which cannot be done from one of them.
        self::assertStringContainsString('#'.$keeper->getId(), $text);
        self::assertStringContainsString('#'.$loser->getId(), $text);
    }

    public function testAcceptingADuplicateRetiresTheLoserAndKeepsTheOther(): void
    {
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('accept');
        $finding = $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->curator('data-accept@test.test'));
        $this->post($client, $finding, 'yes');

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertSame(ItemState::Retired, $this->reload($loser)->getState());
        self::assertSame(ItemState::Unverified, $this->reload($keeper)->getState());
    }

    public function testAcceptingAnOsmLinkWritesTheRef(): void
    {
        $client = static::createClient();
        $item = $this->item('link', 'Dom', ItemSource::Wikidata, 'P');
        $finding = $this->finding(FindingKind::OsmLink, $item, null, 'node/1350577339');

        $client->loginUser($this->curator('data-link@test.test'));
        $this->post($client, $finding, 'yes');

        $this->em()->clear();
        self::assertSame('node/1350577339', $this->reload($item)->getOsmRef());
    }

    public function testDismissingChangesNoDataAndIsRecordedWithItsReason(): void
    {
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('dismiss');
        $finding = $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->curator('data-dismiss@test.test'));
        $this->post($client, $finding, 'no', 'two different bunkers on one line');

        $this->em()->clear();
        // Nothing retired: "no" means no.
        self::assertSame(ItemState::Unverified, $this->reload($loser)->getState());

        $decided = $this->em()->getRepository(CatalogFinding::class)->find($finding->getId());
        self::assertNotNull($decided);
        self::assertSame(FindingStatus::Dismissed, $decided->getStatus());
        // The reason is the useful part: the next curator wondering why the
        // desk is quiet about Ligne KW needs to read it.
        self::assertSame('two different bunkers on one line', $decided->getNote());
        self::assertNotNull($decided->getDecidedBy());
    }

    public function testADecidedFindingCannotBeDecidedTwice(): void
    {
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('twice');
        $finding = $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->curator('data-twice@test.test'));
        // The token has to be taken BEFORE the first decision: afterwards the
        // desk is empty and carries no form, which is the correct behaviour and
        // also what a second curator's stale open tab looks like.
        $token = $this->post($client, $finding, 'no');
        // Two curators reaching the same row is normal, not an error — and the
        // second click must not overturn the first.
        $this->post($client, $finding, 'yes', token: $token);

        $this->em()->clear();
        self::assertSame(ItemState::Unverified, $this->reload($loser)->getState());
        self::assertSame(
            FindingStatus::Dismissed,
            $this->em()->getRepository(CatalogFinding::class)->find($finding->getId())?->getStatus(),
        );
    }

    public function testTheMapCanKeepTheRowTheRankingWouldHaveRetired(): void
    {
        // The desk's Yes keeps whichever row the source ranking preferred. The
        // map exists so a curator who has looked at both pins can say the other
        // one is the real record, and that answer must win.
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('keepother');
        $finding = $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->curator('data-keepother@test.test'));
        $token = $this->deskToken($client);
        $client->request('POST', '/moderate/data/decide', [
            '_token' => $token, 'finding' => (string) $finding->getId(),
            'keep' => (string) $loser->getId(),
        ]);

        $this->em()->clear();
        self::assertSame(ItemState::Unverified, $this->reload($loser)->getState());
        self::assertSame(ItemState::Retired, $this->reload($keeper)->getState());
    }

    public function testAKeepThatNamesNeitherRowChangesNothing(): void
    {
        // A stale tab or a hand-made POST. Falling back to the default row
        // would retire something the curator never chose.
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('stray');
        $stranger = $this->item('stray-other', 'Somewhere Else', ItemSource::Osm, 'O');
        $finding = $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->curator('data-stray@test.test'));
        $token = $this->deskToken($client);
        $client->request('POST', '/moderate/data/decide', [
            '_token' => $token, 'finding' => (string) $finding->getId(),
            'keep' => (string) $stranger->getId(),
        ]);

        $this->em()->clear();
        self::assertSame(ItemState::Unverified, $this->reload($loser)->getState());
        self::assertSame(ItemState::Unverified, $this->reload($keeper)->getState());
        self::assertSame(
            FindingStatus::Open,
            $this->em()->getRepository(CatalogFinding::class)->find($finding->getId())?->getStatus(),
        );
    }

    public function testTheMapEndpointServesBothRowsWithCoordinates(): void
    {
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('json');
        $finding = $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->curator('data-json@test.test'));
        $client->request('GET', '/moderate/data/finding/'.$finding->getId());

        self::assertResponseIsSuccessful();
        /** @var array{items: list<array<string, mixed>>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(2, $body['items']);
        foreach ($body['items'] as $row) {
            // Without coordinates the map cannot place the pins, which is the
            // entire reason for the link.
            self::assertIsNumeric($row['lat']);
            self::assertIsNumeric($row['lng']);
        }
    }

    public function testTheMapEndpointIsClosedToRiders(): void
    {
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('jsonrider');
        $finding = $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->user('data-jsonrider@test.test', ['ROLE_USER']));
        $client->request('GET', '/moderate/data/finding/'.$finding->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheMapEndpointIsQuietAboutADecidedFinding(): void
    {
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('jsondone');
        $finding = $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->curator('data-jsondone@test.test'));
        $this->post($client, $finding, 'no');
        $client->request('GET', '/moderate/data/finding/'.$finding->getId());

        // A follower of a stale link gets nothing to resolve, not a panel that
        // would post into a closed finding.
        self::assertResponseStatusCodeSame(404);
    }

    public function testABadCsrfTokenIsForbidden(): void
    {
        $client = static::createClient();
        [$keeper, $loser] = $this->pair('csrf');
        $finding = $this->finding(FindingKind::Duplicate, $loser, $keeper);

        $client->loginUser($this->curator('data-csrf@test.test'));
        $client->request('POST', '/moderate/data/decide', [
            '_token' => 'nope', 'finding' => (string) $finding->getId(), 'verdict' => 'accept',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame(ItemState::Unverified, $this->reload($loser)->getState());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function reload(Item $item): Item
    {
        $fresh = $this->em()->getRepository(Item::class)->find($item->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * Decide through the real page.
     *
     * The desk's CSRF token is session-backed, so it cannot be minted outside a
     * request the way a stateless one can. Reading it off the rendered form is
     * not a workaround: it also proves the page a curator actually gets carries
     * a usable token, which minting one behind the controller's back would not.
     */
    private function post(KernelBrowser $client, CatalogFinding $finding, string $verdict, string $note = '', ?string $token = null): string
    {
        $token ??= $this->deskToken($client);

        $client->request('POST', '/moderate/data/decide', [
            '_token' => $token,
            'finding' => (string) $finding->getId(),
            'verdict' => $verdict,
            'note' => $note,
        ]);

        return $token;
    }

    /** The token off a rendered desk form. Only obtainable while a form exists. */
    private function deskToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/moderate/data');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form input[name="_token"]')->first()->attr('value');
        self::assertNotEmpty($token);

        return (string) $token;
    }

    /** @return array{0: Item, 1: Item} keeper, loser */
    private function pair(string $slug): array
    {
        return [
            $this->item($slug.'-keep', 'Hôtel Koru', ItemSource::Pivot, 'O'),
            $this->item($slug.'-lose', 'Hôtel Koru', ItemSource::Osm, 'O'),
        ];
    }

    private function item(string $slug, string $name, ItemSource $source, string $letter): Item
    {
        $item = (new Item())
            ->setLetter($letter)
            ->setName($name)
            ->setGeom('{"type":"Point","coordinates":[4.90664,50.66887]}')
            ->setCountryCode('BE')
            ->setSource($source)
            ->setSourceRef('test:desk:'.$slug)
            ->setState(ItemState::Unverified)
            ->setAttributes([]);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function finding(FindingKind $kind, Item $item, ?Item $related, ?string $osmRef = null): CatalogFinding
    {
        $finding = new CatalogFinding($kind, $item, ['distanceM' => 180.0, 'osmName' => 'Dom']);
        if (null !== $related) {
            $finding->setRelatedItem($related);
        }
        $finding->setOsmRef($osmRef);
        $this->em()->persist($finding);
        $this->em()->flush();

        return $finding;
    }

    /**
     * ROLE_CURATOR sits behind the 2FA enforcer, so a curator without a TOTP
     * secret is redirected to /2fa/setup and never reaches the desk at all.
     * Same fixture shape as ModerateScopeGuardTest, for the same reason.
     */
    private function curator(string $email): User
    {
        return $this->user($email, ['ROLE_CURATOR'], 'JBSWY3DPEHPK3PXP');
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles, ?string $totpSecret = null): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password1234'),
        );
        if (null !== $totpSecret) {
            $user->setTotpSecret($totpSecret);
            $user->setTwoFaEnabled(true);
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }
}
