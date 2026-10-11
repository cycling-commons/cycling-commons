<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Moderation\Entity\ModeratorArea;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A curator's word on a photo's description applies at once only inside their
 * own areas. Outside them it is a suggestion like anyone's, which is the rule
 * for every other curator edit (moderation-and-contribution.md §1.6; audit
 * 2026-10-04).
 *
 * @see docs/specs/photo-uploads.md §5e
 */
final class PhotoAltScopeTest extends WebTestCase
{
    private function token(KernelBrowser $client): string
    {
        $client->request('GET', '/media/token');
        /** @var array{token?: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return (string) ($data['token'] ?? '');
    }

    private function region(string $slug): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, admin_level, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_MakeEnvelope(5.0, 50.0, 6.0, 51.0), 4326), 100, 'BE', 4, NOW(), NOW())
             RETURNING id",
            [$slug, $slug],
        );
    }

    public function testACuratorOutsideTheirAreaCannotWriteADescriptionStraightThrough(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $mine = $this->region('alt-scope-mine');
        $theirs = $this->region('alt-scope-theirs');

        $owner = (new User())->setEmail('alt-scope-owner@example.test')->setDisplayName('Uploader');
        $owner->setPassword('x');
        $em->persist($owner);
        $curator = (new User())->setEmail('alt-scope-curator@example.test')->setDisplayName('Area Curator');
        $curator->setPassword('x');
        $curator->setRoles(['ROLE_CURATOR']);
        $curator->setTotpSecret('JBSWY3DPEHPK3PXP');
        $curator->setTwoFaEnabled(true);
        $em->persist($curator);
        $em->flush();
        $em->persist(new ModeratorArea((int) $curator->getId(), $mine, null));

        $item = (new Item())->setLetter('A')->setName('Fontaine outside the area')
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('BE')->setRegionId($theirs)
            ->setSourceRef('alt-scope-test')
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $em->persist($item);
        $em->flush();

        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $upload->approve($item->getId());
        $upload->setAltText('The uploader\'s words.');
        $em->flush();
        $item->setAttributes(['photos' => [static::getContainer()->get(MediaDecisionService::class)->describe($upload)]]);
        $em->flush();

        $client->loginUser($curator);
        $client->request('POST', '/media/photos/'.$upload->getId()->toRfc4122().'/alt', ['_token' => $this->token($client), 'alt' => 'Out-of-area words.']);
        self::assertResponseStatusCodeSame(404, 'outside their area the direct route answers as for anyone');

        $em->clear();
        $fresh = $em->find(Item::class, $item->getId());
        self::assertInstanceOf(Item::class, $fresh);
        self::assertSame('The uploader\'s words.', $fresh->getAttributes()['photos'][0]['alt'] ?? null, 'the gallery is untouched');

        // The suggestion route is open to them, as to any rider.
        $client->request('POST', '/media/photos/'.$upload->getId()->toRfc4122().'/alt-suggestion', ['_token' => $this->token($client), 'alt' => 'Out-of-area words.']);
        self::assertResponseIsSuccessful();

        // And the edit page offers them the suggestion, not the direct write.
        $page = $client->request('GET', '/improve?item='.$item->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('.cur-alt[data-photo="'.$upload->getId()->toRfc4122().'"]'));
        self::assertSame('0', $page->filter('.cur-alt')->attr('data-mine'));
    }
}
