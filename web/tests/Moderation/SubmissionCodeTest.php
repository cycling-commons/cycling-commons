<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Moderation\SubmissionQueue;
use App\Moderation\SubmissionRef;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A submission is named by its code, `#k7m2x9qa`, never by its running
 * number: the database draws eight random characters on every insert, the
 * label puts `#` in front, and the desk search finds a row by it.
 */
final class SubmissionCodeTest extends KernelTestCase
{
    public function testEveryInsertGetsItsOwnCode(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sub = (new Submission())->setLetter('B')->setTitle('A tap')->setStatus(SubmissionStatus::Pending)
            ->setGeom('{"type":"Point","coordinates":[4.5,50.5]}')->setCountryCode('BE');
        $em->persist($sub);
        $em->flush();

        $raw = (string) $this->db()->fetchOne(
            "INSERT INTO submission (type, letter, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('new', 'B', 0, 'pending', 'A raw tap', ST_SetSRID(ST_MakePoint(4.5, 50.5), 4326), 'BE', '{}', '{}', now()) RETURNING code",
        );

        self::assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{8}$/', (string) $sub->getCode(), 'Doctrine reads back the code the database drew');
        self::assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{8}$/', $raw, 'a raw insert gets one too');
        self::assertNotSame($sub->getCode(), $raw);
        self::assertSame('#'.$sub->getCode(), SubmissionRef::of($sub));
    }

    public function testTheDeskFindsASubmissionByItsCode(): void
    {
        self::bootKernel();
        [$id, $code] = $this->db()->fetchNumeric(
            "INSERT INTO submission (type, letter, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('new', 'B', 0, 'pending', 'A coded tap', ST_SetSRID(ST_MakePoint(4.5, 50.5), 4326), 'BE', '{}', '{}', now()) RETURNING id, code",
        ) ?: [0, ''];

        self::assertSame([(int) $id], $this->found('#'.$code));
        self::assertSame([(int) $id], $this->found(strtoupper((string) $code)), 'typed without # and in capitals');
        self::assertSame([(int) $id], $this->found('SUB-'.$id), 'the running number still finds it');
    }

    /** @return list<int> */
    private function found(string $q): array
    {
        $where = [];
        $params = [];
        $filter = new \ReflectionMethod(SubmissionQueue::class, 'searchFilter');
        $filter->invokeArgs(null, [$q, false, &$where, &$params]);

        return array_map(intval(...), $this->db()->fetchFirstColumn('SELECT s.id FROM submission s WHERE '.implode(' AND ', $where), $params));
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
