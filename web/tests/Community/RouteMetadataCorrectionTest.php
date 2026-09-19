<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteChangeHistory;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteMetadata;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A rider asking for one of a route's details to be corrected, from the
 * drawer's correction box (docs/specs/route-domain.md §7.1). Any rider may
 * ask, on any route; the curator decides on the Routes desk with the same
 * done/dismissed verbs every correction uses.
 */
final class RouteMetadataCorrectionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function rider(string $tag): User
    {
        $u = (new User())->setEmail('rmc-'.$tag.'-'.bin2hex(random_bytes(4)).'@test.test');
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function curator(): User
    {
        $u = (new User())->setEmail('rmc-curator-'.bin2hex(random_bytes(4)).'@test.test')->setDisplayName('C');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @param array<string, mixed> $attributes */
    private function route(?User $by = null, array $attributes = []): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Correction loop '.bin2hex(random_bytes(3)))
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(14000)->setState(ItemState::Unverified)->setSource(ItemSource::User)
            ->setSourceRef('user:'.bin2hex(random_bytes(8)))->setRegionId(1)
            ->setProposedBy(null === $by ? null : (int) $by->getId())
            ->setAttributes($attributes);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    /** The stateless correction token, minted the way the drawer mints it. */
    private function token(int $routeId): string
    {
        $this->client->request('GET', '/routes/'.$routeId.'/community');

        return json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function suggest(RecommendedRoute $route, array $extra): void
    {
        $this->client->request('POST', '/routes/'.$route->getId().'/suggest', [
            '_token' => $this->token((int) $route->getId()),
            ...$extra,
        ]);
    }

    /** Any rider may ask, on any route: this is a suggestion, not an edit. */
    public function testAnyRiderMayAskForADetailToBeChanged(): void
    {
        $route = $this->route($this->rider('owner'), ['gradientLimited' => '≤9%']);
        $stranger = $this->rider('stranger');
        $this->client->loginUser($stranger);

        $this->suggest($route, ['reason' => 'metadata', 'field' => 'gradientLimited', 'value' => '≤6%']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $pending = $this->em->getRepository(RouteSuggestion::class)->findBy(['routeId' => $route->getId()]);
        self::assertCount(1, $pending);
        self::assertSame(RouteSuggestionReason::Metadata, $pending[0]->getReason());
        self::assertSame(RouteSuggestionStatus::Pending, $pending[0]->getStatus());
        self::assertSame((int) $stranger->getId(), $pending[0]->getUserId());
        self::assertEquals(['was' => '≤9%', 'now' => '≤6%'], $pending[0]->getChanges()['gradientLimited']);
        // Nothing moves on the route until a curator says so.
        self::assertSame('≤9%', $this->em->find(RecommendedRoute::class, $route->getId())->getAttributes()['gradientLimited']);
    }

    /** One field per correction, and only a field the registry knows. */
    public function testAFieldOutsideTheRegistryIsRefused(): void
    {
        $route = $this->route();
        $this->client->loginUser($this->rider('r'));

        $this->suggest($route, ['reason' => 'metadata', 'field' => 'surfaces', 'value' => 'anything']);
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        self::assertSame([], $this->em->getRepository(RouteSuggestion::class)->findBy(['routeId' => $route->getId()]));
    }

    /** A value outside the vocabulary is not a change, so there is nothing to ask. */
    public function testAValueOutsideTheVocabularyIsRefused(): void
    {
        $route = $this->route();
        $this->client->loginUser($this->rider('r'));

        $this->suggest($route, ['reason' => 'metadata', 'field' => 'gradientLimited', 'value' => 'BOGUS']);
        self::assertResponseStatusCodeSame(422);
    }

    /** Asking for what the route already says is not a correction. */
    public function testAskingForTheCurrentValueIsRefused(): void
    {
        $route = $this->route(null, ['bestDirection' => 'Clockwise']);
        $this->client->loginUser($this->rider('r'));

        $this->suggest($route, ['reason' => 'metadata', 'field' => 'bestDirection', 'value' => 'Clockwise']);
        self::assertResponseStatusCodeSame(422);
    }

    /** A multi-select field arrives as a list and is stored as one. */
    public function testAMultiSelectFieldIsAskedForAsAList(): void
    {
        $route = $this->route(null, ['bikeTypes' => ['Road']]);
        $this->client->loginUser($this->rider('r'));

        $this->suggest($route, ['reason' => 'metadata', 'field' => 'bikeTypes', 'value' => ['Road', 'Handbike']]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $s = $this->em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => $route->getId()]);
        self::assertEquals(['was' => ['Road'], 'now' => ['Road', 'Handbike']], $s->getChanges()['bikeTypes']);
    }

    /** Clearing a field is a correction too: an empty value asks for it unset. */
    public function testAskingForAFieldToBeClearedIsRecordedAsARemoval(): void
    {
        $route = $this->route(null, ['gradientLimited' => '≤6%']);
        $this->client->loginUser($this->rider('r'));

        $this->suggest($route, ['reason' => 'metadata', 'field' => 'gradientLimited', 'value' => '']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $s = $this->em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => $route->getId()]);
        self::assertEquals(['was' => '≤6%', 'now' => null], $s->getChanges()['gradientLimited']);
    }

    /**
     * The note is the rider's word to the curator, kept apart from the public
     * "Note for riders", which is one of the fields they can ask to change.
     */
    public function testTheNoteToTheCuratorIsNotTheRoutesPublicNote(): void
    {
        $route = $this->route(null, ['note' => 'Old public note']);
        $this->client->loginUser($this->rider('r'));

        $this->suggest($route, [
            'reason' => 'metadata', 'field' => 'note', 'value' => 'New public note',
            'note' => 'I rode it last Sunday, the old wording was stale.',
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $s = $this->em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => $route->getId()]);
        self::assertSame('I rode it last Sunday, the old wording was stale.', $s->getNote(), 'private to the curator');
        self::assertEquals(['was' => 'Old public note', 'now' => 'New public note'], $s->getChanges()['note'], 'route content');
    }

    /** Done applies it through the desk, credited to the rider who asked. */
    public function testTheCuratorMarksItDoneAndTheValueLands(): void
    {
        $route = $this->route();
        $rider = $this->rider('r');
        $this->client->loginUser($rider);
        $this->suggest($route, ['reason' => 'metadata', 'field' => 'bestDirection', 'value' => 'Either']);

        $this->em->clear();
        $s = $this->em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => $route->getId()]);

        $this->client->loginUser($this->curator());
        $desk = $this->client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();
        $form = $desk->filter('#rs-done-'.$s->getId())->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues());

        $this->em->clear();
        self::assertSame('Either', $this->em->find(RecommendedRoute::class, $route->getId())->getAttributes()['bestDirection']);
        $rows = $this->em->getRepository(RouteChangeHistory::class)->findBy(['routeId' => $route->getId()]);
        self::assertCount(1, $rows);
        self::assertSame((int) $rider->getId(), $rows[0]->getChangedBy(), 'credited to the rider, not the approver');
        self::assertSame((int) $s->getId(), $rows[0]->getSuggestionId());
    }

    /** Dismissing applies nothing. */
    public function testDismissingAppliesNothing(): void
    {
        $route = $this->route();
        $this->client->loginUser($this->rider('r'));
        $this->suggest($route, ['reason' => 'metadata', 'field' => 'bestDirection', 'value' => 'Either']);

        $this->em->clear();
        $s = $this->em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => $route->getId()]);

        $this->client->loginUser($this->curator());
        $desk = $this->client->request('GET', '/moderate/routes');
        $form = $desk->filter('#rs-done-'.$s->getId())->form();
        $values = $form->getPhpValues();
        $values['status'] = 'dismissed';
        $this->client->request('POST', $form->getUri(), $values);

        $this->em->clear();
        self::assertArrayNotHasKey('bestDirection', $this->em->find(RecommendedRoute::class, $route->getId())->getAttributes());
        self::assertSame([], $this->em->getRepository(RouteChangeHistory::class)->findBy(['routeId' => $route->getId()]));
    }

    /** The desk card shows what is being asked for, field by field. */
    public function testTheDeskCardShowsTheProposedValue(): void
    {
        $route = $this->route(null, ['gradientLimited' => '≤9%']);
        $this->client->loginUser($this->rider('r'));
        $this->suggest($route, ['reason' => 'metadata', 'field' => 'gradientLimited', 'value' => '≤6%']);

        $this->em->clear();
        $s = $this->em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => $route->getId()]);

        $this->client->loginUser($this->curator());
        $desk = $this->client->request('GET', '/moderate/routes');
        $card = $desk->filter('[data-suggestion-id="'.$s->getId().'"]');
        self::assertSame(1, $card->count());
        self::assertStringContainsString('Route details', $card->text());
        self::assertStringContainsString('Whole route ≤ 6%', $card->text());
    }

    /**
     * A correction's thread runs both ways: the curator writes, the rider
     * answers, and the answer comes back to the desk card.
     */
    public function testTheCorrectionThreadRunsBothWays(): void
    {
        $route = $this->route();
        $rider = $this->rider('r');
        $this->client->loginUser($rider);
        $this->suggest($route, ['reason' => 'metadata', 'field' => 'bestDirection', 'value' => 'Either']);

        $this->em->clear();
        $s = $this->em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => $route->getId()]);

        // The curator asks, through the desk card's own Message box.
        $curator = $this->curator();
        $this->client->loginUser($curator);
        $desk = $this->client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();
        $msg = $desk->filter('[data-suggestion-id="'.$s->getId().'"] form[action*="/moderate/message"]')->form();
        $msg->setValues(['body' => 'Which way round did you ride it?']);
        $this->client->submit($msg);

        $this->em->clear();
        $question = $this->em->getRepository(UserMessage::class)->findOneBy([
            'userId' => (int) $rider->getId(), 'channel' => 'correction', 'kind' => UserMessageKind::CuratorMessage,
        ]);
        self::assertNotNull($question, 'the curator can write to the rider about a correction');

        // The rider answers it from their messages page.
        $this->client->loginUser($rider);
        $page = $this->client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();
        $reply = $page->filter('form[action*="/account/messages/'.$question->getId().'/reply"]')->form();
        $reply->setValues(['body' => 'Anticlockwise, from the square.']);
        $this->client->submit($reply);
        self::assertResponseRedirects();

        $this->em->clear();
        $answer = $this->em->getRepository(UserMessage::class)->findOneBy([
            'channel' => 'correction', 'kind' => UserMessageKind::RiderReply,
        ]);
        self::assertNotNull($answer, 'the rider can answer');
        self::assertSame((int) $curator->getId(), $answer->getUserId(), 'it reaches the curator who asked');

        // And the desk card carries it.
        $this->client->loginUser($curator);
        $desk = $this->client->request('GET', '/moderate/routes');
        $card = $desk->filter('[data-suggestion-id="'.$s->getId().'"]');
        self::assertStringContainsString('Anticlockwise, from the square.', $card->text());
    }

    /** The photo reason still has its own form and is refused here. */
    public function testThePhotoReasonIsStillRefusedOnThisEndpoint(): void
    {
        $route = $this->route();
        $this->client->loginUser($this->rider('r'));

        $this->suggest($route, ['reason' => 'photo', 'note' => 'photos']);
        self::assertResponseStatusCodeSame(422);
    }

    /** Every editable field is offered to the drawer, with its own widget. */
    public function testTheDrawerIsToldEveryFieldItMayOffer(): void
    {
        $this->client->loginUser($this->rider('r'));
        $this->client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('window.CC_ROUTE_FIELDS', $html);
        foreach (RouteMetadata::EDITABLE_FIELDS as $field) {
            self::assertStringContainsString('"'.$field.'"', $html, $field.' is offered');
        }
    }
}
