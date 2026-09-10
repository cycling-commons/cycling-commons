<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\Import\OsmLinker;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\ModerationService;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An approved pin move that lands on top of an OSM object links the row to
 * it, for every letter (catalog-data-model.md §5b, owner 2026-09-05).
 *
 * Found when a nameless RIVM tap was moved onto the spot of a deleted CC-row:
 * the row said "not in OSM" from its old spot, and the raw OSM drop came back
 * beside the moved pin.
 */
final class MovedPinLinksOsmTest extends KernelTestCase
{
    use CoverageSchema;

    private const float LAT = 50.222222;
    private const float LNG = 4.333333;

    protected function setUp(): void
    {
        self::bootKernel();
        self::ensureCoverageSchema($this->db());
        $this->db()->executeStatement("DELETE FROM coverage_poi WHERE ref LIKE 'node/9977%'");
        $this->db()->executeStatement("DELETE FROM item WHERE source_ref LIKE 'moved-pin-test:%'");
    }

    public function testAMoveOntoAnUnclaimedOsmObjectLinksTheRow(): void
    {
        // A public toilet (letter C): the rule is not a water rule.
        $this->coveragePoi('node/99770001', 'C', self::LAT + 0.0004, self::LNG);
        $item = $this->item('C', 'moved-pin-test:toilet', self::LAT, self::LNG, answeredNotInOsm: true);

        $this->approveMove($item, self::LAT + 0.0004 + 0.00005, self::LNG);   // about 5 m from the node

        $this->em()->refresh($item);
        self::assertSame('node/99770001', $item->getOsmRef());
        self::assertTrue($item->osmAnswered());
        self::assertSame(1, (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM change_history WHERE item_id = :id AND field = 'osmRef' AND new_value::text = '\"node/99770001\"'",
            ['id' => $item->getId()],
        ), 'the link is a recorded change');
    }

    public function testAMoveNearButNotOnTopLeavesTheAnswerAlone(): void
    {
        $this->coveragePoi('node/99770002', 'B', self::LAT + 0.0004, self::LNG);
        $item = $this->item('B', 'moved-pin-test:far', self::LAT, self::LNG, answeredNotInOsm: true);

        // About 35 m from the node: near, but not on top of it.
        $this->approveMove($item, self::LAT + 0.0004 + 0.00032, self::LNG);

        $this->em()->refresh($item);
        self::assertNull($item->getOsmRef(), OsmLinker::ON_TOP_M.' m is the rule; 35 m is a neighbour, not the same thing');
    }

    public function testAnObjectAnotherServedRowClaimsIsNotTakenTwice(): void
    {
        $this->coveragePoi('node/99770003', 'B', self::LAT + 0.0004, self::LNG);
        $holder = $this->item('B', 'moved-pin-test:holder', self::LAT + 0.0004, self::LNG, answeredNotInOsm: false);
        $holder->answerOsm('node/99770003');
        $this->em()->flush();
        $item = $this->item('B', 'moved-pin-test:second', self::LAT, self::LNG, answeredNotInOsm: true);

        $this->approveMove($item, self::LAT + 0.0004, self::LNG);

        $this->em()->refresh($item);
        self::assertNull($item->getOsmRef(), 'two served rows pointing at one OSM object is a duplicate wearing a link');
    }

    private function approveMove(Item $item, float $lat, float $lng): void
    {
        $em = $this->em();
        $curator = $this->user('moved-pin-curator-'.uniqid('', true).'@example.com', ['ROLE_CURATOR']);
        $rider = $this->user('moved-pin-rider-'.uniqid('', true).'@example.com');
        $sub = (new Submission())
            ->setType(SubmissionType::Edit)
            ->setLetter($item->getLetter())
            ->setItemId((int) $item->getId())
            ->setUserId((int) $rider->getId())
            ->setTitle('Moved')
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')
            ->setChanges([Item::LOCATION_FIELD => ['was' => sprintf('%.5f, %.5f', self::LAT, self::LNG), 'now' => sprintf('%.5f, %.5f', $lat, $lng)]])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();

        static::getContainer()->get(ModerationService::class)->decide((int) $sub->getId(), 'approve', $curator, null);
    }

    private function item(string $letter, string $ref, float $lat, float $lng, bool $answeredNotInOsm): Item
    {
        $item = (new Item())
            ->setLetter($letter)
            ->setName('')
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')
            ->setRegionId(null)
            ->setState(ItemState::Verified)
            ->setSource(ItemSource::Authority)
            ->setSourceRef($ref)
            ->setAttributes([]);
        if ($answeredNotInOsm) {
            $item->answerOsm(null);
        }
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function coveragePoi(string $ref, string $letter, float $lat, float $lng): void
    {
        $this->db()->executeStatement(
            "INSERT INTO coverage_poi (ref, letter, name, geom, tags, country_code)
             VALUES (:ref, :letter, NULL, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), '{}', 'BE')",
            ['ref' => $ref, 'letter' => $letter, 'lat' => $lat, 'lng' => $lng],
        );
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName(strstr($email, '@', true) ?: $email)
            ->setEmailVerified(true)
            ->setEmailVerifiedAt(new \DateTimeImmutable())
            ->setRoles($roles)
            ->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
