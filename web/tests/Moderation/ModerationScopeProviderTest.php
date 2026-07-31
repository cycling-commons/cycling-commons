<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Entity\User;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationScopeProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * tools/divisions/README.md: "a moderator covers 2-4 provinces" is just
 * several moderator_area rows — the provider must UNION all of a user's rows
 * (regions and countries), not pick one. Isolation: DAMA rollback.
 */
final class ModerationScopeProviderTest extends KernelTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(): User
    {
        $u = (new User())->setEmail('msp-'.uniqid('', true).'@test.test');
        $u->setPassword('x');
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    private function region(string $slug, string $cc): Region
    {
        $r = (new Region())->setSlug($slug)->setName(ucfirst($slug))->setCountryCode($cc);
        $this->em()->persist($r);
        $this->em()->flush();

        return $r;
    }

    public function testMultipleRegionRowsUnionIntoOneScope(): void
    {
        $em = $this->em();
        $u = $this->user();
        $ids = [];
        foreach (['noord-holland', 'zuid-holland', 'utrecht'] as $slug) {
            $r = $this->region($slug, 'NL');
            $ids[] = (int) $r->getId();
            $em->persist(new ModeratorArea((int) $u->getId(), (int) $r->getId(), null));
        }
        $em->flush();

        $provider = static::getContainer()->get(ModerationScopeProvider::class);
        $scope = $provider->scopeFor($u);

        self::assertFalse($scope->global);
        self::assertEqualsCanonicalizing($ids, $scope->regionIds, 'all three province atoms union into one scope');
        self::assertTrue($provider->allowsRegion($scope, $ids[1]));

        $sibling = $this->region('drenthe', 'NL');
        self::assertFalse($provider->allowsRegion($scope, (int) $sibling->getId()), 'a province outside the assigned atoms stays out of scope');

        self::assertCount(3, $provider->describe($u, $scope), 'shell label lists every assigned atom');
    }

    public function testCountryRowCoversEveryRegionOfThatCountryOnly(): void
    {
        $em = $this->em();
        $u = $this->user();
        $nh = $this->region('noord-holland', 'NL');
        $em->persist(new ModeratorArea((int) $u->getId(), null, 'NL'));
        $em->flush();

        $provider = static::getContainer()->get(ModerationScopeProvider::class);
        $scope = $provider->scopeFor($u);

        self::assertTrue($provider->allowsRegion($scope, (int) $nh->getId()), 'NL-country curator covers any NL region row');
        $be = $this->region('wallonia', 'BE');
        self::assertFalse($provider->allowsRegion($scope, (int) $be->getId()), 'and no foreign region');
    }
}
