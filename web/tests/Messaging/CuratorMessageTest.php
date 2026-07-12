<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task 8 (moderation-feedback spec M6a): a curator can send a rider a
 * free-form personal message from any desk row — the pending-submission
 * queue, a route-correction row, or a route's own detail page — through the
 * single `POST /moderate/message` endpoint.
 */
final class CuratorMessageTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function curator(): User
    {
        $c = static::getContainer();
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $em = $c->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('curator-'.uniqid('', true).'@msg.test')->setDisplayName('C');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword($hasher->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function rider(string $suffix): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('rider-'.$suffix.'-'.uniqid('', true).'@msg.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function seedSubmission(int $userId): Submission
    {
        $em = $this->em();
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($userId)
            ->setTitle('Curator-message submission')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $em->persist($sub);
        $em->flush();

        return $sub;
    }

    private function route(?int $proposedBy): RecommendedRoute
    {
        $em = $this->em();
        $r = (new RecommendedRoute())->setName('Curator-message route')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setState(ItemState::Verified)->setSource(ItemSource::User)
            ->setSourceRef('user:msg-'.bin2hex(random_bytes(8)))->setRegionId(1)
            ->setProposedBy($proposedBy);
        $em->persist($r);
        $em->flush();

        return $r;
    }

    private function suggestion(int $routeId, int $userId): RouteSuggestion
    {
        $em = $this->em();
        $s = new RouteSuggestion($routeId, $userId, RouteSuggestionReason::BrokenTrack, 'Gate blocks the path.');
        $em->persist($s);
        $em->flush();

        return $s;
    }

    /** @return list<UserMessage> */
    private function curatorMessagesFor(int $userId): array
    {
        /** @var list<UserMessage> $rows */
        $rows = $this->em()->getRepository(UserMessage::class)->findBy([
            'userId' => $userId,
            'kind' => UserMessageKind::CuratorMessage,
        ]);

        return $rows;
    }

    /**
     * `moderate-message` is a session-bound CSRF token id (not listed in
     * csrf.yaml's stateless_token_ids — same shape as route-suggestion /
     * route_edit), so it can't be minted from the container between
     * requests the way the stateless route-community token can. Fetch a
     * real value the way a browser would instead: render a desk page (any
     * page works — the token id is fixed, not page-specific) after logging
     * in and read it off the "message the rider" form it renders.
     */
    private function messageToken(KernelBrowser $client): string
    {
        $warmupRoute = $this->route(null);
        $crawler = $client->request('GET', '/moderate/routes/'.$warmupRoute->getId());

        return (string) $crawler->filter('.msg-rider input[name="_token"]')->attr('value');
    }

    public function testCuratorMessagesASubmissionsRider(): void
    {
        $client = static::createClient();
        $curator = $this->curator();
        $rider = $this->rider('sub');
        $sub = $this->seedSubmission((int) $rider->getId());

        $client->loginUser($curator);
        $token = $this->messageToken($client);
        $client->request('POST', '/moderate/message', [
            'channel' => 'submission',
            'id' => (string) $sub->getId(),
            'body' => 'Could you share a photo of the gate?',
            '_token' => $token,
        ]);
        self::assertResponseRedirects();

        $rows = $this->curatorMessagesFor((int) $rider->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame('curator', $m->getSender());
        self::assertSame((int) $curator->getId(), $m->getSenderId());
        self::assertSame('submission', $m->getChannel());
        self::assertSame((int) $sub->getId(), $m->getRefId());
        self::assertSame('SUB-'.$sub->getId(), $m->getRefLabel());
        self::assertSame('Could you share a photo of the gate?', $m->getBodyText());
    }

    public function testCuratorMessagesARouteCorrectionsRider(): void
    {
        $client = static::createClient();
        $curator = $this->curator();
        $rider = $this->rider('corr');
        $route = $this->route(null);
        $s = $this->suggestion((int) $route->getId(), (int) $rider->getId());

        $client->loginUser($curator);
        $token = $this->messageToken($client);
        $client->request('POST', '/moderate/message', [
            'channel' => 'correction',
            'id' => (string) $s->getId(),
            'body' => 'Thanks for flagging this, looking into it.',
            '_token' => $token,
        ]);
        self::assertResponseRedirects();

        $rows = $this->curatorMessagesFor((int) $rider->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame('correction', $m->getChannel());
        self::assertSame((int) $s->getId(), $m->getRefId());
        self::assertSame($route->getName(), $m->getRefLabel());
    }

    public function testCuratorMessagesARoutesProposer(): void
    {
        $client = static::createClient();
        $curator = $this->curator();
        $rider = $this->rider('route');
        $route = $this->route((int) $rider->getId());

        $client->loginUser($curator);
        $token = $this->messageToken($client);
        $client->request('POST', '/moderate/message', [
            'channel' => 'route',
            'id' => (string) $route->getId(),
            'body' => 'Great proposal, one question about the surface.',
            '_token' => $token,
        ]);
        self::assertResponseRedirects();

        $rows = $this->curatorMessagesFor((int) $rider->getId());
        self::assertCount(1, $rows);
        $m = $rows[0];
        self::assertSame('route', $m->getChannel());
        self::assertSame((int) $route->getId(), $m->getRefId());
        self::assertSame($route->getName(), $m->getRefLabel());
    }

    public function testRouteWithNoProposerWritesNoRowAndFlashesError(): void
    {
        $client = static::createClient();
        $curator = $this->curator();
        $route = $this->route(null);

        $client->loginUser($curator);
        $token = $this->messageToken($client);
        $client->request('POST', '/moderate/message', [
            'channel' => 'route',
            'id' => (string) $route->getId(),
            'body' => 'Hello?',
            '_token' => $token,
        ]);
        self::assertResponseRedirects();

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_message WHERE kind = :k', ['k' => UserMessageKind::CuratorMessage->value],
        ));
    }

    public function testBodyOverTwoThousandCharactersIsRejected(): void
    {
        $client = static::createClient();
        $curator = $this->curator();
        $rider = $this->rider('toolong');
        $sub = $this->seedSubmission((int) $rider->getId());

        $client->loginUser($curator);
        $token = $this->messageToken($client);
        $client->request('POST', '/moderate/message', [
            'channel' => 'submission',
            'id' => (string) $sub->getId(),
            'body' => str_repeat('x', 2001),
            '_token' => $token,
        ]);
        self::assertResponseRedirects();

        self::assertCount(0, $this->curatorMessagesFor((int) $rider->getId()));
    }

    public function testPlainUserIsForbidden(): void
    {
        $client = static::createClient();
        $rider = $this->rider('plain');
        $sub = $this->seedSubmission((int) $rider->getId());

        // The `^/moderate` access-control rule (security.yaml) blocks this
        // before the controller's own CSRF check ever runs, so no real
        // token is needed here.
        $client->loginUser($rider);
        $client->request('POST', '/moderate/message', [
            'channel' => 'submission',
            'id' => (string) $sub->getId(),
            'body' => 'Trying to message myself.',
            '_token' => 'irrelevant',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBadCsrfTokenIsForbidden(): void
    {
        $client = static::createClient();
        $curator = $this->curator();
        $rider = $this->rider('badcsrf');
        $sub = $this->seedSubmission((int) $rider->getId());

        $client->loginUser($curator);
        $client->request('POST', '/moderate/message', [
            'channel' => 'submission',
            'id' => (string) $sub->getId(),
            'body' => 'Hello',
            '_token' => 'not-a-real-token',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->curatorMessagesFor((int) $rider->getId()));
    }
}
