<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\Partner\ApiApps;
use App\Entity\User;
use App\Moderation\DeskRider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The write path (public-api.md §8): a registered partner app's
 * contribution enters the same review queue as the site's form, filed under
 * the app and its author, never a rider. The first test is the scope: one
 * app never reads, or forgets, another app's rows.
 */
final class ContributionApiTest extends WebTestCase
{
    use ApiSeeding;

    private const array NEW_PLACE = [
        'letter' => 'B',
        'name' => 'Fountain on the square',
        'geometry' => ['type' => 'Point', 'coordinates' => [4.5, 50.5]],
        'fields' => ['potable' => 'Yes (public supply)', 'note' => "Behind the church.\nLow flow."],
        'author' => ['ref' => 'u-8f3a', 'name' => 'Sam'],
        'licence' => 'ODbL-1.0',
    ];

    public function testOneAppNeverSeesOrForgetsAnotherAppsContributions(): void
    {
        $client = static::createClient();
        $mine = $this->app('Mine');
        $theirs = $this->app('Theirs');

        $id = $this->post($client, $theirs, self::NEW_PLACE)['submission_id'];

        $this->call($client, 'GET', '/v1/contributions/'.$id, $mine);
        self::assertResponseStatusCodeSame(404, 'another app\'s contribution is as unknown as one that never existed');
        $this->call($client, 'GET', '/v1/contributions/'.$id, $theirs);
        self::assertResponseIsSuccessful();

        $this->call($client, 'DELETE', '/v1/authors/u-8f3a', $mine);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('Sam', $this->db()->fetchOne('SELECT external_author_name FROM submission WHERE id = :id', ['id' => $id]), 'the same reference at another app is another person');

        // A rider's own submission is not an app's to read either.
        $riderRow = (int) $this->db()->fetchOne(
            "INSERT INTO submission (type, letter, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('new', 'B', 0, 'pending', 'A rider''s tap', ST_SetSRID(ST_MakePoint(4.5, 50.5), 4326), 'BE', '{}', '{}', now()) RETURNING id",
        );
        $this->call($client, 'GET', '/v1/contributions/'.$riderRow, $mine);
        self::assertResponseStatusCodeSame(404);
    }

    public function testANewPlaceQueuesUnderTheAppAndItsAuthor(): void
    {
        $client = static::createClient();
        $key = $this->app('Example Rides');

        $accepted = $this->post($client, $key, self::NEW_PLACE);
        self::assertSame('queued_for_review', $accepted['status']);
        self::assertSame('new', $accepted['kind']);
        self::assertSame('/v1/contributions/'.$accepted['submission_id'], $accepted['status_url']);

        $row = $this->db()->fetchAssociative('SELECT s.*, a.name AS app FROM submission s JOIN api_app a ON a.id = s.api_app_id WHERE s.id = :id', ['id' => $accepted['submission_id']]);
        self::assertIsArray($row);
        self::assertNull($row['user_id'], 'no rider: nobody here to message');
        self::assertSame('Example Rides', $row['app']);
        self::assertSame('u-8f3a', $row['external_author_ref']);
        self::assertSame('Sam', $row['external_author_name']);
        self::assertSame('pending', $row['status']);
        self::assertSame('en', $row['lang'], 'an app that names no language writes English');
        self::assertSame(['licence' => 'ODbL-1.0'], json_decode((string) $row['payload'], true)['_consent']);
        self::assertSame("Behind the church.\nLow flow.", json_decode((string) $row['changes'], true)['note']['now'], 'a note keeps its line break');

        // Waiting for a curator: not served yet.
        $this->getJson($client, '/v1/items/'.$accepted['item_id'], 404);

        $status = $this->call($client, 'GET', '/v1/contributions/'.$accepted['submission_id'], $key);
        self::assertSame('pending', $status['status']);
        self::assertSame($accepted['item_id'], $status['item_id']);
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));

        self::assertSame(['name' => 'Sam (Example Rides)', 'uuid' => null], DeskRider::ofApp('Sam', 'u-8f3a', 'Example Rides'));
        self::assertSame(['name' => '#u-8f3a (Example Rides)', 'uuid' => null], DeskRider::ofApp(null, 'u-8f3a', 'Example Rides'));
    }

    public function testAContributionCarriesTheLanguageItIsWrittenIn(): void
    {
        $client = static::createClient();
        $key = $this->app('Example Rides NL');

        $accepted = $this->post($client, $key, ['lang' => 'nl', 'name' => 'Fontein op het plein'] + self::NEW_PLACE);
        self::assertSame('nl', $this->db()->fetchOne('SELECT lang FROM submission WHERE id = :id', ['id' => $accepted['submission_id']]));

        $refused = $this->call($client, 'POST', '/v1/contributions', $key, ['lang' => 'xx'] + self::NEW_PLACE);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_lang', $refused['error'] ?? null);
    }

    public function testTheCuratorDeskNamesTheAuthorAndTheApp(): void
    {
        $client = static::createClient();
        $key = $this->app('Example Rides');
        $this->post($client, $key, self::NEW_PLACE);

        $this->loginAdmin($client);
        $client->request('GET', '/moderate/submissions');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Sam (Example Rides)', $html);
    }

    public function testAnEditSendsOnlyWhatChanges(): void
    {
        $client = static::createClient();
        $key = $this->app('Example Rides');
        $item = $this->item('B', 'Tap', 4.5, 50.5, 'verified', ['potable' => 'Unknown', 'seasonal' => 'Summer only']);

        $accepted = $this->post($client, $key, ['item_id' => $item, 'fields' => ['seasonal' => 'Year-round'], 'author' => ['ref' => 'u-1'], 'licence' => 'ODbL-1.0']);
        self::assertSame('edit', $accepted['kind']);
        self::assertSame($item, $accepted['item_id']);
        $changes = json_decode((string) $this->db()->fetchOne('SELECT changes FROM submission WHERE id = :id', ['id' => $accepted['submission_id']]), true);
        self::assertEquals(['seasonal' => ['was' => 'Summer only', 'now' => 'Year-round']], $changes);

        // The same author again amends the waiting row instead of queueing a twin.
        $again = $this->post($client, $key, ['item_id' => $item, 'fields' => ['potable' => 'No / non-potable'], 'author' => ['ref' => 'u-1'], 'licence' => 'ODbL-1.0']);
        self::assertSame($accepted['submission_id'], $again['submission_id']);
        // Another author gets their own.
        $other = $this->post($client, $key, ['item_id' => $item, 'fields' => ['potable' => 'No / non-potable'], 'author' => ['ref' => 'u-2'], 'licence' => 'ODbL-1.0']);
        self::assertNotSame($accepted['submission_id'], $other['submission_id']);
    }

    public function testAnAuthorRevisesTheirOwnWaitingPlace(): void
    {
        $client = static::createClient();
        $key = $this->app('Example Rides');
        $other = $this->app('Other app');
        $first = $this->post($client, $key, self::NEW_PLACE);
        $this->db()->executeStatement("UPDATE submission SET status = 'needs_info', decision_note = 'Which side of the church?' WHERE id = :id", ['id' => $first['submission_id']]);

        // The same author answers with an edit of the waiting place: the same row, pending again.
        $answer = $this->post($client, $key, ['item_id' => $first['item_id'], 'fields' => ['note' => 'North side, by the gate.'], 'author' => ['ref' => 'u-8f3a'], 'licence' => 'ODbL-1.0']);
        self::assertSame($first['submission_id'], $answer['submission_id']);
        self::assertSame('pending', $this->db()->fetchOne('SELECT status FROM submission WHERE id = :id', ['id' => $first['submission_id']]));

        // Nobody else may touch it: another author, or another app.
        foreach ([[$key, 'u-other'], [$other, 'u-8f3a']] as [$who, $ref]) {
            $this->call($client, 'POST', '/v1/contributions', $who, ['item_id' => $first['item_id'], 'fields' => ['potable' => 'Unknown'], 'author' => ['ref' => $ref], 'licence' => 'ODbL-1.0']);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('invalid_item', json_decode((string) $client->getResponse()->getContent(), true)['error']);
        }
    }

    public function testBadContributionsSayWhy(): void
    {
        $client = static::createClient();
        $key = $this->app('Example Rides');
        $hidden = $this->item('B', 'Waiting', 4.5, 50.5, 'submitted');

        $cases = [
            'licence_required' => ['licence' => null],
            'invalid_author' => ['author' => ['ref' => 'has spaces']],
            'invalid_letter' => ['letter' => 'R'],
            'invalid_field' => ['fields' => ['potable' => 'maybe']],
            'invalid_name' => ['name' => null],
            'invalid_geometry' => ['geometry' => ['type' => 'Point', 'coordinates' => [200, 50]]],
            'invalid_osm_ref' => ['osm_ref' => 'relation/1'],
            'invalid_body' => ['photos' => []],
        ];
        $multi = ['letter' => 'O', 'fields' => ['accessibility' => [['Step-free access']]]];
        $this->call($client, 'POST', '/v1/contributions', $key, array_replace(self::NEW_PLACE, $multi));
        self::assertResponseStatusCodeSame(400, 'a list inside a multi-pick is refused, not a crash');
        foreach ($cases as $error => $change) {
            $body = array_filter(array_replace(self::NEW_PLACE, $change), static fn (mixed $v): bool => null !== $v);
            $this->call($client, 'POST', '/v1/contributions', $key, $body);
            self::assertResponseStatusCodeSame(400, $error);
            self::assertSame($error, json_decode((string) $client->getResponse()->getContent(), true)['error']);
        }

        $this->call($client, 'POST', '/v1/contributions', $key, ['item_id' => $hidden, 'fields' => ['potable' => 'Unknown'], 'author' => ['ref' => 'u-1'], 'licence' => 'ODbL-1.0']);
        self::assertSame('invalid_item', json_decode((string) $client->getResponse()->getContent(), true)['error'], 'an app cannot edit what it cannot read');

        $client->request('POST', '/v1/contributions', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'CONTENT_TYPE' => 'application/json'], 'not json');
        self::assertResponseStatusCodeSame(400);
    }

    public function testOnlyAnActiveAppWithTheWriteScopeMayWrite(): void
    {
        $client = static::createClient();
        $apps = static::getContainer()->get(ApiApps::class);

        $client->request('POST', '/v1/contributions', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('Bearer', (string) $client->getResponse()->headers->get('WWW-Authenticate'));

        $readOnly = $apps->register('No scope', 'ops@example.test', [])['key'];
        $this->call($client, 'POST', '/v1/contributions', $readOnly, self::NEW_PLACE);
        self::assertResponseStatusCodeSame(403);

        $issued = $apps->register('Soon suspended', 'ops@example.test', [ApiApps::SCOPE_WRITE]);
        $apps->setStatus($issued['id'], 'suspended');
        $this->call($client, 'POST', '/v1/contributions', $issued['key'], self::NEW_PLACE);
        self::assertResponseStatusCodeSame(401);

        // A key in the URL is not a key: it would sit in every access log.
        $live = $this->app('Live');
        $client->request('POST', '/v1/contributions?key='.$live, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(self::NEW_PLACE));
        self::assertResponseStatusCodeSame(401);

        $rotated = $apps->rotate($issued['id']);
        self::assertIsString($rotated);
        self::assertNotSame($issued['key'], $rotated);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM api_app WHERE key_hash = :k', ['k' => $issued['key']]), 'only a hash is kept');
    }

    public function testForgettingAnAuthorBlanksTheirName(): void
    {
        $client = static::createClient();
        $key = $this->app('Example Rides');
        $id = $this->post($client, $key, self::NEW_PLACE)['submission_id'];

        $this->call($client, 'DELETE', '/v1/authors/u-8f3a', $key);
        self::assertResponseStatusCodeSame(204);
        $row = $this->db()->fetchAssociative('SELECT external_author_ref, external_author_name FROM submission WHERE id = :id', ['id' => $id]);
        self::assertSame(['external_author_ref' => 'u-8f3a', 'external_author_name' => null], $row);
    }

    public function testAWriteAnswersNoCrossOriginPreflightForWrites(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/v1/contributions');
        self::assertSame('GET', $client->getResponse()->headers->get('Access-Control-Allow-Methods'), 'a browser may not send a partner key cross-origin');
    }

    private function app(string $name): string
    {
        return static::getContainer()->get(ApiApps::class)->register($name, 'ops@example.test', [ApiApps::SCOPE_WRITE])['key'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, string $key, array $body): array
    {
        $answer = $this->call($client, 'POST', '/v1/contributions', $key, $body);
        self::assertResponseStatusCodeSame(202, (string) $client->getResponse()->getContent());

        return $answer;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(KernelBrowser $client, string $method, string $url, string $key, ?array $body = null): array
    {
        $client->request($method, $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'CONTENT_TYPE' => 'application/json'], null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $content = (string) $client->getResponse()->getContent();

        return '' === $content ? [] : (array) json_decode($content, true);
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('desk-'.bin2hex(random_bytes(4)).'@example.test');
        $user->setDisplayName('Desk admin');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles(['ROLE_ADMIN']);
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }
}
