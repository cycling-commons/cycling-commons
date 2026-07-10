<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Community\RouteCommunityService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class RouteSuggestFlowTest extends WebTestCase
{
    private function rider(EntityManagerInterface $em, string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName('R');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function route(EntityManagerInterface $em): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Reportable · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState(ItemState::Verified)
            ->setSource(ItemSource::User)->setSourceRef('user:sug-'.uniqid())->setRegionId(1)->setProposedBy(9);
        $em->persist($r);
        $em->flush();

        return $r;
    }

    private function token(KernelBrowser $client, int $routeId): string
    {
        $client->request('GET', '/routes/'.$routeId.'/community');

        return json_decode((string) $client->getResponse()->getContent(), true)['token'];
    }

    public function testSuggestionIsStoredPending(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em);
        $rid = $route->getId();
        $u = $this->rider($em, 'sug@test.test');

        $client->loginUser($u);
        $client->request('POST', '/routes/'.$rid.'/suggest', [
            'reason' => 'broken-track',
            'note' => 'The forest section is gated now.',
            '_token' => $this->token($client, $rid),
        ]);
        self::assertResponseIsSuccessful();

        $row = $em->getConnection()->fetchAssociative(
            'SELECT reason, note, status, user_id FROM route_suggestion WHERE route_id = :r',
            ['r' => $rid],
        );
        self::assertSame('broken-track', $row['reason']);
        self::assertSame(RouteSuggestionStatus::Pending->value, $row['status']);
        self::assertSame($u->getId(), (int) $row['user_id']);
    }

    public function testSixthSuggestionInADayIsRateLimited(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em);
        $rid = $route->getId();
        $u = $this->rider($em, 'flood@test.test');

        // Exhaust the daily limit via 5 direct service calls first, not via 5
        // looped $client->request() calls. The route_suggest test cache pool
        // is the array adapter, which is ResetInterface-tagged; Symfony's
        // Kernel::boot() resets every kernel.reset-tagged service at the start
        // of each top-level HTTP request after the first one in a test, so the
        // limiter's counter can never be observed accumulating across separate
        // $client->request() calls (confirmed: a value set before request #2
        // is gone by the time request #2 runs, even with disableReboot() — that
        // flag only controls KernelBrowser's own shutdown/reboot, not this
        // internal per-request reset). Direct calls don't go through
        // Kernel::handle(), so they don't trigger it — this still produces 5
        // real persisted rows, and mirrors how RouteProposalServiceTest tests
        // the identical route_propose limiter (by direct calls, never HTTP).
        $community = static::getContainer()->get(RouteCommunityService::class);
        for ($i = 0; $i < 5; ++$i) {
            $community->recordSuggestion($route, $u, RouteSuggestionReason::Other, "n$i");
        }

        // Exactly one real HTTP request — the first in this test, so no reset
        // has fired yet — proves the controller itself maps the limiter's
        // rejection to a 429 JSON response. The CSRF token is fetched straight
        // from the container (not via a GET /community request) because that
        // extra request would itself be the trigger that resets the pool
        // before the POST below ever runs. `route-community` is a stateless
        // CSRF token id (config/packages/csrf.yaml): its value is a fixed
        // placeholder and SameOriginCsrfTokenManager validates same-origin-ness
        // from request headers instead — normally satisfied by a real
        // browser's automatic Sec-Fetch-Site header (or, in the other two
        // tests here, by browser-kit's auto-Referer from a preceding request
        // in the same client's history). With no preceding request to supply
        // that, set Sec-Fetch-Site explicitly so the single POST validates.
        $client->loginUser($u);
        $token = static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('route-community')->getValue();
        $client->request(
            'POST',
            '/routes/'.$rid.'/suggest',
            ['reason' => 'other', 'note' => 'n6', '_token' => $token],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        self::assertResponseStatusCodeSame(429);

        self::assertSame(5, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM route_suggestion WHERE route_id = :r',
            ['r' => $rid],
        ));
    }

    public function testInvalidReasonIs422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em);
        $rid = $route->getId();
        $u = $this->rider($em, 'badreason@test.test');

        $client->loginUser($u);
        $client->request('POST', '/routes/'.$rid.'/suggest', ['reason' => 'not-a-reason', 'note' => 'x', '_token' => $this->token($client, $rid)]);
        self::assertResponseStatusCodeSame(422);
    }
}
