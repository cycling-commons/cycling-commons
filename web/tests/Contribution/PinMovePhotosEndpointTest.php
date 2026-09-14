<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The edit form asks, while the pin is dragged, how many rider photos the new
 * spot would hide on a scenic view (docs/specs/scenic-views.md §8,
 * docs/specs/photo-uploads.md §5g).
 */
final class PinMovePhotosEndpointTest extends WebTestCase
{
    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function item(string $letter): Item
    {
        $here = [52.4, 4.9];
        $item = (new Item())->setLetter($letter)->setName('Pin move view')
            ->setGeom('{"type":"Point","coordinates":[4.9,52.4]}')->setCountryCode('NL')
            ->setSourceRef('pin-move-photos-'.bin2hex(random_bytes(3)))
            ->setSource(ItemSource::User)->setState(ItemState::Verified)
            ->setAttributes(['photos' => [
                ['id' => 'near', 'sm' => 'x', 'license' => 'CC BY-SA 4.0', 'credit' => '', 'distanceM' => 40, 'distancePin' => $here],
                ['id' => 'edge', 'sm' => 'x', 'license' => 'CC BY-SA 4.0', 'credit' => '', 'distanceM' => 240, 'distancePin' => $here],
            ]]);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function login(): void
    {
        $user = (new User())->setEmail('pin-move-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setPassword('x');
        $user->setDisplayName('Pin mover');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user);
    }

    /** @return array<string, mixed> */
    private function ask(Item $item, string $lat, string $lng): array
    {
        $this->client->request('GET', '/contribute/pin-move-photos?item='.$item->getId().'&lat='.$lat.'&lng='.$lng);
        self::assertResponseIsSuccessful();

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testItCountsTheRiderPhotosTheNewSpotHides(): void
    {
        $item = $this->item('P');
        $this->login();

        self::assertSame(0, $this->ask($item, '52.400000', '4.900000')['hidden']);
        // About 20 m north, then about 400 m north.
        self::assertSame(1, $this->ask($item, '52.400180', '4.900000')['hidden']);
        $far = $this->ask($item, '52.403600', '4.900000');
        self::assertSame(2, $far['hidden']);
        self::assertSame(250, $far['withinM'], 'the limit the warning explains');
        self::assertIsInt($far['farthestM']);
        self::assertGreaterThan(250, $far['farthestM'], 'why: the farthest a hidden photo may have been taken from the new spot');
    }

    public function testAnotherLetterAndANonsensePointHideNothing(): void
    {
        $this->login();

        $none = ['hidden' => 0, 'farthestM' => null];
        self::assertSame($none, array_intersect_key($this->ask($this->item('Q'), '52.403600', '4.900000'), $none));
        self::assertSame($none, array_intersect_key($this->ask($this->item('P'), 'north', '4.900000'), $none));
        self::assertSame($none, array_intersect_key($this->ask($this->item('P'), '95', '4.900000'), $none));
    }

    public function testAnUnknownItemIsNotFound(): void
    {
        $this->login();

        $this->client->request('GET', '/contribute/pin-move-photos?item=999999999&lat=52.4&lng=4.9');

        self::assertResponseStatusCodeSame(404);
    }

    public function testASignedOutVisitorIsNotAnswered(): void
    {
        $item = $this->item('P');

        $this->client->request('GET', '/contribute/pin-move-photos?item='.$item->getId().'&lat=52.4036&lng=4.9');

        self::assertFalse($this->client->getResponse()->isSuccessful());
    }
}
