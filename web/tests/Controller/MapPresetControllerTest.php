<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Map presets (docs/specs/map-presets.md section 5): a signed-in rider
 * lists, saves, defaults and deletes their own presets as JSON, and nobody
 * else's. The stateless-JSON pattern (security-architecture.md section
 * 5.1): a clean 401 for a visitor, a stateless `X-CC-Token` on the writes,
 * a 403 for a bad or cross-site token, cheap validation before any budget
 * is spent, at most six presets, one default.
 */
final class MapPresetControllerTest extends WebTestCase
{
    private static function user(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private static function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private static function token(string $id): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($id)->getValue();
    }

    /** @param array<string, mixed> $body */
    private static function post(KernelBrowser $client, string $path, array $body, ?string $token = null, string $site = 'same-origin'): void
    {
        $client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CC_TOKEN' => $token ?? self::token('map-preset'),
            'HTTP_SEC_FETCH_SITE' => $site,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function settings(array $overrides = []): array
    {
        return $overrides + [
            'mode' => 'curated',
            'layers' => ['climbs', 'water', 'stays', 'scenic', 'experience'],
            'overlays' => ['surface', 'routes'],
            'seasons' => ['summer'],
            'bikes' => ['Gravel'],
            'facets' => ['effortf' => ['Steady', 'Challenging']],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function body(array $overrides = []): array
    {
        return $overrides + ['name' => 'Gravel weekend', 'settings' => self::settings()];
    }

    private static function limit(string $limiter): int
    {
        $config = Yaml::parseFile(__DIR__.'/../../config/packages/rate_limiter.yaml');

        return (int) $config['framework']['rate_limiter'][$limiter]['limit'];
    }

    /* ---- scope first ---- */

    public function testARidersPresetIsNeverSeenChangedDefaultedOrDeletedByAnotherRiderOrAVisitor(): void
    {
        $client = static::createClient();
        $owner = self::user('preset-owner@test.test');
        $client->loginUser($owner);
        self::post($client, '/map/presets/save', self::body());
        self::assertResponseStatusCodeSame(201);
        $id = (int) self::json($client)['id'];

        $other = self::user('preset-other@test.test');
        $client->loginUser($other);
        $client->request('GET', '/map/presets');
        self::assertResponseIsSuccessful();
        self::assertSame([], self::json($client)['presets'], 'another rider lists nothing of the owner');
        self::post($client, '/map/presets/save', self::body(['id' => $id, 'name' => 'Stolen']));
        self::assertResponseStatusCodeSame(404);
        self::post($client, '/map/presets/'.$id.'/use', ['context' => 'plan']);
        self::assertResponseStatusCodeSame(404);
        self::post($client, '/map/presets/'.$id.'/delete', []);
        self::assertResponseStatusCodeSame(404);

        // The same browser, signed out: a visitor.
        $client->getCookieJar()->clear();
        $client->request('GET', '/map/presets');
        self::assertResponseStatusCodeSame(401);
        self::post($client, '/map/presets/'.$id.'/delete', []);
        self::assertResponseStatusCodeSame(401);

        self::assertSame('Gravel weekend', self::db()->fetchOne('SELECT name FROM map_preset WHERE id = ?', [$id]), 'untouched');
    }

    public function testACrossSiteSaveWithoutATokenIs403(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('preset-csrf@test.test'));
        self::post($client, '/map/presets/save', self::body(), 'not-a-token', 'cross-site');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSaveInsertsAndListsThePresetWithItsSettings(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('preset-save@test.test'));

        self::post($client, '/map/presets/save', self::body(['uses' => ['map', 'plan']]));

        self::assertResponseStatusCodeSame(201);
        $id = self::json($client)['id'];
        $client->request('GET', '/map/presets');
        $list = self::json($client)['presets'];
        self::assertCount(1, $list);
        self::assertSame($id, $list[0]['id']);
        self::assertSame('Gravel weekend', $list[0]['name']);
        self::assertSame(['map', 'plan'], $list[0]['uses']);
        // JSONB stores keys in its own order; the values are what count.
        self::assertEquals(self::settings(), $list[0]['settings']);
    }

    public function testSaveWithAnIdUpdatesAndAddsNothing(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('preset-update@test.test'));
        self::post($client, '/map/presets/save', self::body());
        $id = self::json($client)['id'];

        self::post($client, '/map/presets/save', self::body(['id' => $id, 'name' => 'Gravel Sunday', 'settings' => self::settings(['mode' => 'all'])]));

        self::assertResponseStatusCodeSame(200);
        $client->request('GET', '/map/presets');
        $list = self::json($client)['presets'];
        self::assertCount(1, $list);
        self::assertSame('Gravel Sunday', $list[0]['name']);
        self::assertSame('all', $list[0]['settings']['mode']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badBodies(): iterable
    {
        yield 'no name' => [['name' => '']];
        yield 'name too long' => [['name' => str_repeat('x', 41)]];
        yield 'unknown mode' => [['settings' => self::settings(['mode' => 'best'])]];
        yield 'unknown layer' => [['settings' => self::settings(['layers' => ['pending']])]];
        yield 'unknown overlay' => [['settings' => self::settings(['overlays' => ['traffic']])]];
        yield 'unknown bike' => [['settings' => self::settings(['bikes' => ['Unicycle']])]];
        yield 'unknown season' => [['settings' => self::settings(['seasons' => ['monsoon']])]];
        yield 'unknown facet group' => [['settings' => self::settings(['facets' => ['colour' => ['Red']]])]];
        yield 'a facet value that is not a string' => [['settings' => self::settings(['facets' => ['sqf' => [1]]])]];
        yield 'settings not an object' => [['settings' => 'curated']];
        yield 'unknown surface class' => [['settings' => self::settings(['surfaces' => ['lava']])]];
        yield 'unknown route type' => [['settings' => self::settings(['routes' => ['motorway']])]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('badBodies')]
    public function testABadBodyIs400(array $overrides): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('preset-bad-'.md5(serialize($overrides)).'@test.test'));
        self::post($client, '/map/presets/save', self::body($overrides));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('bad_request', self::json($client)['error']);
    }

    public function testAFacetValueTheMapNoLongerHasIsDroppedNotRefused(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('preset-oldfacet@test.test'));
        self::post($client, '/map/presets/save', self::body(['settings' => self::settings(['facets' => ['sqf' => ['Smooth', 'Cobbled']]])]));
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', '/map/presets');
        self::assertSame(['sqf' => ['Smooth']], self::json($client)['presets'][0]['settings']['facets']);
    }

    public function testTheSeventhPresetIs409ButAnUpdateStillLands(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('preset-cap@test.test'));
        $first = 0;
        for ($i = 1; $i <= 6; ++$i) {
            self::post($client, '/map/presets/save', self::body(['name' => 'Preset '.$i]));
            self::assertResponseStatusCodeSame(201);
            $first = $first ?: (int) self::json($client)['id'];
        }

        self::post($client, '/map/presets/save', self::body(['name' => 'Preset 7']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('too_many', self::json($client)['error']);

        self::post($client, '/map/presets/save', self::body(['id' => $first, 'name' => 'Preset 1 again']));
        self::assertResponseStatusCodeSame(200);
    }

    /** Each tool takes one preset (map-presets.md section 1): using a preset for a tool moves the tool off the preset that had it. */
    public function testEachToolTakesOnePresetAndUsingOneMovesIt(): void
    {
        $client = static::createClient();
        $rider = self::user('preset-uses@test.test');
        $client->loginUser($rider);
        self::post($client, '/map/presets/save', self::body(['name' => 'A', 'uses' => ['map', 'plan']]));
        $a = (int) self::json($client)['id'];
        self::post($client, '/map/presets/save', self::body(['name' => 'B', 'uses' => ['ride']]));
        $b = (int) self::json($client)['id'];

        self::post($client, '/map/presets/'.$b.'/use', ['context' => 'plan']);
        self::assertResponseStatusCodeSame(204);
        self::post($client, '/map/presets/save', self::body(['name' => 'C', 'uses' => ['map']]));
        $c = (int) self::json($client)['id'];

        $uses = self::db()->fetchAllKeyValue('SELECT context, preset_id FROM map_preset_use WHERE user_id = ? ORDER BY context', [(int) $rider->getId()]);
        self::assertSame(['map' => $c, 'plan' => $b, 'ride' => $b], array_map('intval', $uses));
        $client->request('GET', '/map/presets');
        $byId = array_column(self::json($client)['presets'], 'uses', 'id');
        self::assertSame([], $byId[$a], 'A lost both its tools');
        self::assertSame(['plan', 'ride'], $byId[$b]);
    }

    public function testAnUnknownToolIs400(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('preset-badtool@test.test'));
        self::post($client, '/map/presets/save', self::body(['uses' => ['climb']]));
        self::assertResponseStatusCodeSame(400);
        self::post($client, '/map/presets/save', self::body());
        $id = (int) self::json($client)['id'];
        self::post($client, '/map/presets/'.$id.'/use', ['context' => 'climb']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testDeleteIs204AndThePresetIsGone(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('preset-delete@test.test'));
        self::post($client, '/map/presets/save', self::body());
        $id = (int) self::json($client)['id'];

        self::post($client, '/map/presets/'.$id.'/delete', []);

        self::assertResponseStatusCodeSame(204);
        self::assertFalse(self::db()->fetchOne('SELECT id FROM map_preset WHERE id = ?', [$id]));
    }

    public function testARiderOverTheSaveBudgetIs429(): void
    {
        $client = static::createClient();
        $rider = self::user('preset-budget@test.test');
        $client->loginUser($rider);
        $limiter = static::getContainer()->get('limiter.map_preset_save')->create('user-'.(string) $rider->getId());
        for ($i = 0, $n = self::limit('map_preset_save'); $i < $n; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        self::post($client, '/map/presets/save', self::body());

        self::assertResponseStatusCodeSame(429);
    }

    /** A preset holds the key's surface classes and route types too (owner 2026-10-15). */
    public function testAPresetHoldsTheSurfaceClassesAndRouteTypes(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('preset-key@test.test'));
        self::post($client, '/map/presets/save', self::body(['settings' => self::settings(['surfaces' => ['gravel', 'paved'], 'routes' => ['regional', 'knoop']])]));
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', '/map/presets');
        $settings = self::json($client)['presets'][0]['settings'];
        self::assertSame(['paved', 'gravel'], $settings['surfaces']);
        self::assertSame(['regional', 'knoop'], $settings['routes']);
    }
}
