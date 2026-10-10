<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogProvider;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\Links\LinkVerdictStore;
use App\Catalog\Links\SafeBrowsing;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Safe Browsing check protects curators and nobody else
 * (catalog-data-model.md §7 `links`, owner 2026-10-10): a link in a
 * submission is checked when it is sent in, and the curator who reviews it is
 * warned. An approved link is shown as approved, whatever its verdict says;
 * a visitor's own browser does its own Safe Browsing check.
 */
final class UnsafeLinkShownTest extends WebTestCase
{
    private const string URL = 'https://unsafe-shown.example/page';

    public function testAnApprovedLinkWithAnUnsafeVerdictIsOnTheMapAndInTheCatalogDocument(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $item = (new Item())->setLetter('D')->setName('Unsafe link shop')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('unsafe-shown-'.bin2hex(random_bytes(4)))
            ->setSource(ItemSource::User)->setState(ItemState::Verified)
            ->setAttributes(['t' => 'Bike shop', 'links' => [['label' => 'Site', 'urls' => [['url' => self::URL]]]]]);
        $this->em()->persist($item);
        $this->em()->flush();
        static::getContainer()->get(LinkVerdictStore::class)->record([self::URL => SafeBrowsing::UNSAFE]);

        $feature = static::getContainer()->get(CatalogProvider::class)->featureForItem((int) $item->getId());
        self::assertNotNull($feature);
        // assertEquals: jsonb stores an object's keys in its own order.
        self::assertEquals(
            [['label' => 'Site', 'urls' => [['url' => self::URL]]]],
            $feature['feature']['properties']['links'] ?? null,
            'the drawer shows the link as it was approved',
        );

        $regionId = $this->em()->getConnection()->fetchOne('SELECT region_id FROM item WHERE id = :id', ['id' => $item->getId()]);
        $client->request('GET', '/map/catalog/region/'.(null === $regionId ? 0 : (int) $regionId).'.json');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            json_encode(self::URL, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            (string) $client->getResponse()->getContent(),
            'the catalog document the map loads carries the link',
        );
    }

    public function testTheCuratorReviewingASubmissionWithAnUnsafeLinkIsWarned(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = (new User())->setEmail('unsafe-shown-'.bin2hex(random_bytes(4)).'@example.test')->setDisplayName('Link Curator');
        $curator->setEmailVerified(true);
        $curator->setEmailVerifiedAt(new \DateTimeImmutable());
        $curator->setRoles(['ROLE_CURATOR']);
        $curator->setPassword('x');
        $curator->setTotpSecret('JBSWY3DPEHPK3PXP');
        $curator->setTwoFaEnabled(true);
        $rider = (new User())->setEmail('unsafe-shown-rider-'.bin2hex(random_bytes(4)).'@example.test')->setDisplayName('Link Rider');
        $rider->setPassword('x');
        $this->em()->persist($curator);
        $this->em()->persist($rider);
        $this->em()->flush();
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('D')->setUserId((int) $rider->getId())
            ->setTitle('Unsafe link proposal')->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges(['links' => ['was' => null, 'now' => [['label' => 'Site', 'urls' => [['url' => self::URL]]]]]])
            ->setPayload([]);
        $this->em()->persist($sub);
        $this->em()->flush();
        static::getContainer()->get(LinkVerdictStore::class)->record([self::URL => SafeBrowsing::UNSAFE]);

        $client->loginUser($curator);
        $crawler = $client->request('GET', '/moderate/submissions?q=Unsafe+link+proposal');
        self::assertResponseIsSuccessful();
        $flag = $crawler->filter('.q-item[data-item-id="'.$sub->getId().'"] .q-link-flag.q-link-unsafe');
        self::assertCount(1, $flag);
        self::assertSame('A link in this submission is on a known-unsafe list. Do not click it.', trim($flag->text()));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
