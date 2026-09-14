<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Commons\CommonsApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Where the camera stood, read off Commons.
 *
 * Only the file's primary coordinate of type "camera" counts. An "object"
 * coordinate is where the subject is, which for a mountain is its summit, and
 * a round point is the centre of a satellite scene, not a place anybody stood.
 */
final class CommonsApiCameraTest extends TestCase
{
    public function testACameraCoordinateIsReturned(): void
    {
        $info = $this->api($this->fileInfoBody([['lat' => 45.93574, 'lon' => 7.62781, 'primary' => true, 'type' => 'camera']]))
            ->fileInfo('Matterhorn from Breuil.jpg');

        self::assertNotNull($info);
        self::assertSame(45.93574, $info['cameraLat']);
        self::assertSame(7.62781, $info['cameraLng']);
    }

    public function testAnObjectCoordinateIsNotACamera(): void
    {
        $info = $this->api($this->fileInfoBody([['lat' => 45.97639, 'lon' => 7.65861, 'primary' => true, 'type' => 'object']]))
            ->fileInfo('Matterhorn from Breuil.jpg');

        self::assertNotNull($info);
        self::assertNull($info['cameraLat']);
        self::assertNull($info['cameraLng']);
    }

    public function testARoundCameraPointIsASceneCentreNotACamera(): void
    {
        $info = $this->api($this->fileInfoBody([['lat' => 37.7, 'lon' => 15, 'primary' => true, 'type' => 'camera']]))
            ->fileInfo('Etna from space.jpg');

        self::assertNotNull($info);
        self::assertNull($info['cameraLat'], 'fewer than three decimals in either axis is refused');
    }

    public function testAFileWithNoCoordinatesHasNoCamera(): void
    {
        $info = $this->api($this->fileInfoBody(null))->fileInfo('Somewhere.jpg');

        self::assertNotNull($info);
        self::assertNull($info['cameraLat']);
    }

    public function testTheMetadataCallAsksForThePrimaryCoordinate(): void
    {
        $seen = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen = $url;

            return new MockResponse($this->fileInfoBody(null), ['http_code' => 200]);
        });
        (new CommonsApi($client, 'CyclingCommons-test/1.0'))->fileInfo('Somewhere.jpg');

        parse_str((string) parse_url($seen, \PHP_URL_QUERY), $query);
        self::assertSame('imageinfo|coordinates', $query['prop'] ?? null);
        self::assertSame('primary', $query['coprimary'] ?? null);
        self::assertStringContainsString('type', (string) ($query['coprop'] ?? ''));
    }

    public function testABatchMapsEachAskedFileToItsCamera(): void
    {
        $method = '';
        $body = '';
        $agent = '';
        $client = new MockHttpClient(static function (string $m, string $url, array $options) use (&$method, &$body, &$agent): MockResponse {
            $method = $m;
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            foreach ($options['headers'] ?? [] as $header) {
                if (\is_string($header) && str_starts_with(strtolower($header), 'user-agent:')) {
                    $agent = trim(substr($header, 11));
                }
            }

            return new MockResponse(json_encode(['query' => [
                'normalized' => [['from' => 'File:lower case.jpg', 'to' => 'File:Lower case.jpg']],
                'pages' => [
                    ['title' => 'File:Lower case.jpg', 'coordinates' => [['lat' => 50.12345, 'lon' => 5.54321, 'primary' => true, 'type' => 'camera']]],
                    ['title' => 'File:Object only.jpg', 'coordinates' => [['lat' => 50.12345, 'lon' => 5.54321, 'primary' => true, 'type' => 'object']]],
                    ['title' => 'File:Gone.jpg', 'missing' => true],
                ],
            ]], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $cameras = (new CommonsApi($client, 'CyclingCommons-test/1.0'))
            ->cameraLocations(['lower case.jpg', 'Object only.jpg', 'Gone.jpg']);

        self::assertSame('POST', $method, 'a batch of titles goes in the body, not the URL');
        self::assertSame('CyclingCommons-test/1.0', $agent);
        parse_str($body, $form);
        self::assertSame('File:lower case.jpg|File:Object only.jpg|File:Gone.jpg', $form['titles'] ?? null);
        self::assertSame(['lower case.jpg' => [50.12345, 5.54321], 'Object only.jpg' => null, 'Gone.jpg' => null], $cameras);
    }

    public function testABatchIsCappedAtTwentyTitles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->api('{}')->cameraLocations(array_map(static fn (int $i): string => "F{$i}.jpg", range(1, 21)));
    }

    private function api(string $body): CommonsApi
    {
        return new CommonsApi(new MockHttpClient(new MockResponse($body, ['http_code' => 200])), 'CyclingCommons-test/1.0');
    }

    /** @param list<array<string, mixed>>|null $coordinates */
    private function fileInfoBody(?array $coordinates): string
    {
        $page = ['title' => 'File:X.jpg', 'imageinfo' => [[
            'thumburl' => 'https://upload.wikimedia.org/thumb/X.jpg',
            'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY-SA 4.0'], 'Artist' => ['value' => 'Somebody']],
        ]]];
        if (null !== $coordinates) {
            $page['coordinates'] = $coordinates;
        }

        return json_encode(['query' => ['pages' => [$page]]], \JSON_THROW_ON_ERROR);
    }
}
