<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Catalog\Entity\ChangeHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChangeHistoryEndpointTest extends WebTestCase
{
    public function testHistoryEndpointServesRowsAnonymouslyWithCacheHeaders(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $itemId = 555001;
        $history = (new ChangeHistory())
            ->setItemId($itemId)
            ->setField('hours')
            ->setOldValue('24/7')
            ->setNewValue('closed Sundays')
            ->setChangedBy(3);
        $em->persist($history);
        $em->flush();

        $client->request('GET', \sprintf('/map/item/%d/history', $itemId));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $response = $client->getResponse();
        self::assertNotEmpty($response->getEtag());
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));

        /** @var array{history: list<array<string, mixed>>} $data */
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['history']);
        self::assertSame('hours', $data['history'][0]['field']);
        self::assertSame('24/7', $data['history'][0]['oldValue']);
        self::assertSame('closed Sundays', $data['history'][0]['newValue']);
        self::assertMatchesRegularExpression('/^rider#[0-9a-f]{4}$/', $data['history'][0]['who']);

        // Conditional revalidation, same contract as /map/catalog.json.
        $client->request('GET', \sprintf('/map/item/%d/history', $itemId), [], [], ['HTTP_IF_NONE_MATCH' => $response->getEtag()]);
        self::assertResponseStatusCodeSame(304);
    }

    public function testUnknownItemReturnsEmptyHistoryNotFourOhFour(): void
    {
        $client = static::createClient();

        $client->request('GET', '/map/item/999999999/history');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['history' => []], $data);
    }
}
