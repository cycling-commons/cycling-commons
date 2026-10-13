<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The denylist walk of public-api-personal-data-boundary.md §5.2: every
 * readable /v1 endpoint, asked about rows a real person touched, answers
 * without a key that names personal data and without that person's
 * address or name anywhere in the body. The exact-key tests pin each DTO;
 * this one catches what no DTO test thought to look for, on every route,
 * including one added later.
 */
final class PersonalDataWalkTest extends WebTestCase
{
    use ApiSeeding;

    /**
     * Words no key of a public response may hold, at any depth. A key is
     * read as words (`contactEmail` and `contact_email` are "contact",
     * "email"), so a place kind such as "worship" is not taken for "ip".
     */
    private const array DENYLIST = ['email', 'password', 'totp', 'secret', 'token', 'ip', 'locale', 'base_point', 'base_place', 'deletion', 'proposed_by', 'trashed_by', 'user_id', 'changed_by', 'uploader', 'pseudonym', 'display_name', 'author', 'contact'];

    public function testNoReadableEndpointCarriesPersonalData(): void
    {
        $client = static::createClient();
        $this->seedCatalog($client);
        $person = $this->person();

        $itemId = $this->item('B', 'Tap by the church', 4.5, 50.5, 'verified', ['note' => 'Ring Lena Visser', 'potable' => 'Unknown']);
        $this->db()->executeStatement(
            "INSERT INTO submission (type, letter, item_id, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('new', 'B', :item, :user, 'approved', 'Tap by the church', ST_SetSRID(ST_MakePoint(4.5, 50.5), 4326), 'BE', '{}', '{}', now())",
            ['item' => $itemId, 'user' => $person->getId()],
        );
        $routeId = $this->route('Lena\'s loop', [[4.2, 50.2], [4.4, 50.4]], 'verified', ['note' => 'Lena Visser rides it', 'uploader' => ['name' => 'Lena Visser']], (int) $person->getId());

        $samples = ['{id}' => (string) $itemId];
        $urls = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($name, 'api_v1_') || !\in_array('GET', $route->getMethods(), true)) {
                continue;
            }
            $path = $route->getPath();
            if (str_starts_with($path, '/v1/contributions')) {
                continue;   // a partner app's own rows, behind its key (ContributionApiTest)
            }
            $path = str_starts_with($path, '/v1/regions/') ? str_replace('{id}', 'test-square', $path)
                : (str_starts_with($path, '/v1/routes/') ? str_replace('{id}', (string) $routeId, $path) : strtr($path, $samples));
            $urls[] = $path.match ($path) {
                '/v1/search' => '?bbox=4,50,5,51&routes=include&hydrate=osm',
                '/v1/coverage/counts', '/v1/routes' => '?bbox=4,50,5,51',
                default => '',
            };
        }
        self::assertGreaterThanOrEqual(10, \count($urls), 'the walk lost its routes');

        foreach ($urls as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $body = (string) $client->getResponse()->getContent();
            foreach (['lena@example.test', 'Lena Visser', 'Lena V.'] as $value) {
                self::assertStringNotContainsString($value, $body, $url.' carries a person');
            }
            if (str_ends_with($url, '.gpx')) {
                continue;
            }
            $this->walk(json_decode($body, true, 512, \JSON_THROW_ON_ERROR), $url);
        }
    }

    private function walk(mixed $node, string $where): void
    {
        if (!\is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (\is_string($key)) {
                $words = '_'.strtolower((string) preg_replace(['~([a-z0-9])([A-Z])~', '~[^A-Za-z0-9]+~'], ['$1_$2', '_'], $key)).'_';
                foreach (self::DENYLIST as $term) {
                    self::assertStringNotContainsString('_'.$term.'_', $words, $where.' answers a key "'.$key.'"');
                }
            }
            $this->walk($value, $where.'.'.$key);
        }
    }

    private function person(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('lena@example.test');
        $user->setDisplayName('Lena V.');
        $user->setEmailVerified(true);
        $user->setPublicProfile(true);
        $user->setPassword('not-a-hash');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
