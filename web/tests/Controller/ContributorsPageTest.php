<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\ContributorWallProvider;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ContributorsPageTest extends WebTestCase
{
    private function rider(string $email, string $name, bool $publicProfile): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail($email)->setDisplayName($name);
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPublicProfile($publicProfile);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function approvedSubmission(int $userId): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($userId)
            ->setTitle('Wall submission')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $sub->setStatus(SubmissionStatus::Approved);
        $em->persist($sub);
        $em->flush();
    }

    public function testWallShowsOnlyOptInContributorsWithRealCounts(): void
    {
        $client = static::createClient();
        $public = $this->rider('wall-public@test.test', 'Wall Rider', true);
        $private = $this->rider('wall-private@test.test', 'Hidden Rider', false);
        $this->approvedSubmission((int) $public->getId());
        $this->approvedSubmission((int) $private->getId());
        // Opted in but nothing contributed — not on the wall either.
        $this->rider('wall-idle@test.test', 'Idle Rider', true);

        $client->request('GET', '/contributors');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Wall Rider', $html);
        self::assertStringContainsString('/riders/'.$public->getUuid()->toRfc4122(), $html, 'wall rows link the public profile');
        self::assertStringNotContainsString('Hidden Rider', $html, 'no opt-in, no wall — regardless of contributions');
        self::assertStringNotContainsString('Idle Rider', $html, 'opt-in without contributions stays off the wall');
        self::assertStringNotContainsString('rider#', $html, 'the sample handles are gone');
    }

    public function testEmptyWallIsHonest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contributors');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('rider#', $html);
        self::assertStringNotContainsString('312k', $html, 'demo stats are gone');
    }

    /**
     * The wall is paged, and the pager agrees with the rows it is paging:
     * the page query and the count query share one source builder.
     */
    public function testTheWallPagesAndCountsTheSameSet(): void
    {
        static::createClient();
        $wall = static::getContainer()->get(ContributorWallProvider::class);

        foreach (['Paged Alpha', 'Paged Bravo', 'Paged Charlie'] as $i => $name) {
            $rider = $this->rider(sprintf('wall-page-%d@test.test', $i), $name, true);
            $this->approvedSubmission((int) $rider->getId());
        }

        self::assertSame(3, $wall->wallCount('Paged '));

        $first = $wall->wall('Paged ', null, 1, 2);
        $second = $wall->wall('Paged ', null, 2, 2);
        self::assertCount(2, $first);
        self::assertCount(1, $second);
        // Alphabetical, exactly as the page copy promises, and the split does
        // not repeat or drop a rider across the boundary.
        self::assertSame(['Paged Alpha', 'Paged Bravo', 'Paged Charlie'], [
            ...array_column($first, 'name'),
            ...array_column($second, 'name'),
        ]);
    }

    /**
     * The search moved to the server when the wall became paged. A filter
     * that only saw the rendered page would tell a rider on page 4 that they
     * are not on the wall at all — so this asserts the filter reaches riders
     * the current page does NOT hold.
     */
    public function testTheSearchSpansTheWholeWallNotJustThePage(): void
    {
        $client = static::createClient();
        $wall = static::getContainer()->get(ContributorWallProvider::class);

        $needle = $this->rider('wall-needle@test.test', 'Zzz Needlerider', true);
        $this->approvedSubmission((int) $needle->getId());
        for ($i = 0; $i < 3; ++$i) {
            $other = $this->rider(sprintf('wall-hay-%d@test.test', $i), sprintf('Aaa Haystack %d', $i), true);
            $this->approvedSubmission((int) $other->getId());
        }

        // Last alphabetically, so a page of one cannot be holding them.
        self::assertSame([], array_filter(
            $wall->wall(null, null, 1, 1),
            static fn (array $r): bool => 'Zzz Needlerider' === $r['name'],
        ));

        $client->request('GET', '/contributors?q=Needlerider');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Zzz Needlerider', $html);
        self::assertStringNotContainsString('Aaa Haystack', $html, 'the filter narrows, it does not merely highlight');
    }

    /** A search that matches nobody says so, and does not read as an empty wall. */
    public function testANonMatchingSearchSaysNoMatchNotEmptyWall(): void
    {
        $client = static::createClient();
        $rider = $this->rider('wall-somebody@test.test', 'Somebody Real', true);
        $this->approvedSubmission((int) $rider->getId());

        $client->request('GET', '/contributors?q=nobody-by-that-name');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Somebody Real', $html);
        self::assertStringNotContainsString('Nobody on the wall yet', $html, 'a filtered-out wall is not an empty project');
    }

    /**
     * A name holding `%` searches for itself. Without escaping, one rider
     * called "100%" would match the entire wall.
     */
    public function testWildcardCharactersInASearchAreLiteral(): void
    {
        $client = static::createClient();
        $percent = $this->rider('wall-percent@test.test', 'Cent Percent 100%', true);
        $this->approvedSubmission((int) $percent->getId());
        $plain = $this->rider('wall-plain@test.test', 'Plain Namerider', true);
        $this->approvedSubmission((int) $plain->getId());

        $client->request('GET', '/contributors?q=100%25');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Cent Percent 100%', $html);
        self::assertStringNotContainsString('Plain Namerider', $html);
    }
}
