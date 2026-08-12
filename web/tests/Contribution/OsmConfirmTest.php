<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\CatalogProvider;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One tap on an OSM place (owner decision 2026-08-12: "add the option to make
 * it in service and potable even when it is still an OSM point — in the
 * background we then just pretend it is a submitted form and we create the new
 * item").
 *
 * It is the wizard of MaterializeFlowTest with the typing removed, so the
 * invariants it proves are the same ones: the item carries the ref, it lands
 * Submitted rather than on the map, and a second tap never mints a twin.
 */
final class OsmConfirmTest extends WebTestCase
{
    use CoverageSchema;

    private const REF = 'node/515151';
    private const NAMELESS = 'node/525252';

    private function login(KernelBrowser $client, string $tag): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail("tap-{$tag}@example.com");
        $user->setDisplayName('One Tap');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    private function seedPois(): void
    {
        $db = static::getContainer()->get(Connection::class);
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, [
            'ref' => self::REF,
            'letter' => 'C',
            'name' => 'Fontein Grote Markt',
            'lat' => 51.05,
            'lng' => 3.72,
            'country_code' => 'BE',
        ]);
        self::insertCoveragePoi($db, [
            'ref' => self::NAMELESS,
            'letter' => 'C',
            'name' => null,
            'lat' => 51.06,
            'lng' => 3.73,
            'country_code' => 'BE',
        ]);
    }

    /**
     * The token as the drawer gets it: off the map page. Reading it there
     * rather than minting one in the container is the point — it proves the
     * page still carries what the buttons need, which is the wiring that broke
     * everything else if it were missing.
     */
    private function mapToken(KernelBrowser $client): string
    {
        $client->request('GET', '/map');
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('~window\.CC_CONFIRM_TOKEN = "([^"]+)"~', $html, $m), 'the map page carries the confirm token');

        return $m[1];
    }

    private function post(KernelBrowser $client, string $ref, string $stance, ?string $token = null): void
    {
        $client->request('POST', '/osm/confirm', [
            '_token' => $token ?? 'not-a-token',
            'ref' => $ref,
            'stance' => $stance,
        ]);
    }

    public function testATapMintsASubmittedItemCarryingTheRefAndTheAnswer(): void
    {
        $client = static::createClient();
        $this->login($client, 'mint');
        $this->seedPois();

        $this->post($client, self::REF, 'potable', $this->mapToken($client));

        self::assertResponseIsSuccessful();
        /** @var array{ok: bool, reference: string, stance: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);
        self::assertStringStartsWith('SUB-', $body['reference']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->findOneBy(['sourceRef' => self::REF]);
        self::assertNotNull($item, 'the tap materialized the place, keyed by its OSM ref');
        self::assertSame(ItemSource::Osm, $item->getSource());
        self::assertSame(ItemState::Submitted, $item->getState(), 'a tap proposes; a curator decides');
        self::assertSame('Fontein Grote Markt', $item->getName());
        self::assertSame(['potable' => 'Yes (public supply)'], $item->getAttributes());
        self::assertNotNull(
            $em->getRepository(Submission::class)->findOneBy(['itemId' => $item->getId()]),
            'it goes through the ordinary queue — no new moderation mechanic',
        );
    }

    public function testTheNotPotableAnswerIsRecordedAsItsOwnClaim(): void
    {
        $client = static::createClient();
        $this->login($client, 'nope');
        $this->seedPois();

        $this->post($client, self::REF, 'not_potable', $this->mapToken($client));

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->findOneBy(['sourceRef' => self::REF]);
        self::assertSame(['potable' => 'No / non-potable'], $item->getAttributes());
    }

    public function testAnUnnamedPlaceTakesItsLayersLabel(): void
    {
        // Most taps in OSM have no name, and a required field cannot be left
        // empty — the layer's own words beat an invented "Unnamed place",
        // because a curator reads the title first.
        $client = static::createClient();
        $this->login($client, 'nameless');
        $this->seedPois();

        $this->post($client, self::NAMELESS, 'potable', $this->mapToken($client));

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->findOneBy(['sourceRef' => self::NAMELESS]);
        self::assertSame('Water & food', $item->getName());
    }

    public function testASecondTapNeverMintsATwin(): void
    {
        $client = static::createClient();
        $this->login($client, 'twin');
        $this->seedPois();

        $token = $this->mapToken($client);
        $this->post($client, self::REF, 'potable', $token);
        self::assertResponseIsSuccessful();
        $this->post($client, self::REF, 'potable', $token);

        self::assertResponseStatusCodeSame(409);
        /** @var array{error: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('pending_review', $body['error'], 'the second rider is told what happened, not "try again"');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $em->getRepository(Item::class)->count(['sourceRef' => self::REF]));
    }

    /**
     * The three ways a mapped place stops being true (owner 2026-08-12: "we
     * must also add a button for broken and closed and not there anymore").
     * They write the edit form's own field, so a rider who then opens the form
     * finds their answer already chosen instead of a second record of it.
     */
    public function testTheThreeStateAnswersWriteTheConditionField(): void
    {
        // One client, one place per answer: a kernel is booted once per test,
        // and one item per OSM ref is the rule the endpoint enforces anyway.
        $client = static::createClient();
        $this->login($client, 'states');
        $this->seedPois();
        $db = static::getContainer()->get(Connection::class);
        foreach (['node/531001', 'node/531002'] as $extra) {
            self::insertCoveragePoi($db, [
                'ref' => $extra, 'letter' => 'C', 'name' => 'Tap '.$extra,
                'lat' => 51.07, 'lng' => 3.74, 'country_code' => 'BE',
            ]);
        }
        $token = $this->mapToken($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        foreach ([
            [self::REF, 'out_of_order', 'Out of order'],
            ['node/531001', 'closed', 'Closed'],
            ['node/531002', 'gone', 'Not there anymore'],
        ] as [$ref, $stance, $value]) {
            $this->post($client, $ref, $stance, $token);

            self::assertResponseIsSuccessful($stance);
            /** @var Item $item */
            $item = $em->getRepository(Item::class)->findOneBy(['sourceRef' => $ref]);
            self::assertSame(['condition' => $value], $item->getAttributes(), $stance);
            self::assertSame(ItemState::Submitted, $item->getState(), 'a report proposes; a curator decides');
        }
    }

    /**
     * "Not there anymore" is the one with teeth. Once served, the item is NOT
     * drawn - and its OSM ref stays claimed, so the reference point does not
     * reappear in the hole it left. Both halves, or the button does nothing a
     * rider can see.
     */
    public function testAPlaceReportedGoneLeavesTheMapAndDoesNotHandItBackToOsm(): void
    {
        $client = static::createClient();
        $this->login($client, 'gone');
        $this->seedPois();
        $this->post($client, self::REF, 'gone', $this->mapToken($client));
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->findOneBy(['sourceRef' => self::REF]);
        $item->setState(ItemState::Unverified);   // as approval leaves it
        $em->flush();
        $em->clear();

        $payload = static::getContainer()->get(CatalogProvider::class)->payload();
        self::assertContains(self::REF, $payload['refs'], 'the ref stays claimed, so the OSM point stays hidden');

        $drawn = [];
        foreach ($payload as $value) {
            if (\is_array($value) && 'FeatureCollection' === ($value['type'] ?? null)) {
                foreach ($value['features'] as $feature) {
                    $drawn[] = $feature['properties']['id'] ?? null;
                }
            }
        }
        self::assertNotContains($item->getId(), $drawn, 'a place reported gone is not a place on the map');
    }

    public function testUnknownRefsAndStancesAreRefused(): void
    {
        $client = static::createClient();
        $this->login($client, 'bad');
        $this->seedPois();

        $token = $this->mapToken($client);
        $this->post($client, 'relation/12', 'potable', $token);
        self::assertResponseStatusCodeSame(400, 'only node|way refs exist in the coverage index');

        $this->post($client, 'node/999999999', 'potable', $token);
        self::assertResponseStatusCodeSame(404);

        $this->post($client, self::REF, 'delicious', $token);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Becoming ours must not freeze a place (owner 2026-08-12: "a water point
     * once added as existing and potable can later be removed or out of order
     * etc"). The same three answers reach an item we already hold - as an
     * ordinary edit, through the ordinary queue.
     */
    public function testAPlaceWeAlreadyHoldTakesTheSameThreeAnswers(): void
    {
        $client = static::createClient();
        $this->login($client, 'ours');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('C')->setName('Fontein Vrijdagmarkt')
            ->setGeom('{"type":"Point","coordinates":[3.72,51.05]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)
            ->setSourceRef('node/909090')->setAttributes(['potable' => 'Yes (public supply)']);
        $em->persist($item);
        $em->flush();
        $id = (int) $item->getId();

        $client->request('POST', '/items/'.$id.'/condition', [
            '_token' => $this->mapToken($client),
            'stance' => 'out_of_order',
        ]);

        self::assertResponseIsSuccessful();
        /** @var Submission $submission */
        $submission = $em->getRepository(Submission::class)->findOneBy(['itemId' => $id]);
        self::assertNotNull($submission, 'it travels as an ordinary edit, not a new mechanic');
        self::assertSame('Out of order', $submission->getChanges()['condition']['now']);
        self::assertSame(
            ['potable' => 'Yes (public supply)'],
            $em->getRepository(Item::class)->find($id)->getAttributes(),
            'a report proposes; nothing changes on the item until a curator says so',
        );
    }

    public function testTheSameReportTwiceIsNotTwoRowsInTheQueue(): void
    {
        $client = static::createClient();
        $this->login($client, 'twice');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('H')->setName('Schuilhut')
            ->setGeom('{"type":"Point","coordinates":[3.73,51.06]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Manual)->setAttributes([]);
        $em->persist($item);
        $em->flush();
        $id = (int) $item->getId();
        $token = $this->mapToken($client);

        $client->request('POST', '/items/'.$id.'/condition', ['_token' => $token, 'stance' => 'gone']);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/items/'.$id.'/condition', ['_token' => $token, 'stance' => 'gone']);

        self::assertResponseStatusCodeSame(409, 'the map is not a tally of who else saw it go');
        self::assertSame(1, $em->getRepository(Submission::class)->count(['itemId' => $id]));
    }

    public function testAnItemReportIsRefusedForUnknownIdsAndAnswers(): void
    {
        $client = static::createClient();
        $this->login($client, 'itembad');
        $token = $this->mapToken($client);

        $client->request('POST', '/items/99999999/condition', ['_token' => $token, 'stance' => 'gone']);
        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('C')->setName('Tap')
            ->setGeom('{"type":"Point","coordinates":[3.74,51.07]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Manual)->setAttributes([]);
        $em->persist($item);
        $em->flush();

        // 'As mapped' is the letter's own confirmation, which has its own
        // button; this endpoint only takes the ways a place stops being true.
        $client->request('POST', '/items/'.$item->getId().'/condition', ['_token' => $token, 'stance' => 'exists']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnAnonymousTapIsRefused(): void
    {
        $client = static::createClient();
        $this->seedPois();

        $this->post($client, self::REF, 'potable');

        self::assertTrue(
            $client->getResponse()->isRedirect() || 401 === $client->getResponse()->getStatusCode(),
            'confirming is an account action — the drawer shows the log-in line instead',
        );
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(Item::class)->count(['sourceRef' => self::REF]));
    }
}
