<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\MissingReasonException;
use App\Moderation\RouteModerationService;
use App\Moderation\StatementDecision;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A rejected, retired or trashed route proposal, and a trashed correction,
 * tell their rider why (DSA Article 17); spam tells nobody.
 *
 * @see docs/specs/content-reports.md §7
 */
final class RouteStatementTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testARejectionWithoutANoteIsRefused(): void
    {
        $route = $this->route(ItemState::Submitted, (int) $this->user()->getId());

        $this->expectException(MissingReasonException::class);
        $this->routes()->reject((int) $route->getId(), $this->user(), ' ');
    }

    public function testRejectAndRetireCarryTheirStatements(): void
    {
        $proposer = $this->user();
        $submitted = $this->route(ItemState::Submitted, (int) $proposer->getId());
        $live = $this->route(ItemState::Verified, (int) $proposer->getId());

        $this->routes()->reject((int) $submitted->getId(), $this->user(), 'Same loop as route 12.');
        $this->routes()->retire((int) $live->getId(), $this->user(), 'The bridge is gone for good.');

        $rejected = $this->statementOn($proposer, UserMessageKind::RouteRejected);
        self::assertSame(StatementDecision::NotPublished, $rejected->decision);
        self::assertSame('Same loop as route 12.', $rejected->facts);
        self::assertSame('route-'.$submitted->getId(), $rejected->reference);
        $retired = $this->statementOn($proposer, UserMessageKind::RouteRetired);
        self::assertSame(StatementDecision::Retired, $retired->decision);
        self::assertSame(StatementGround::NotAccepted, $retired->ground);
        self::assertSame('The bridge is gone for good.', $retired->facts);
    }

    public function testTrashAsAbuseTellsTheProposerAndTheCorrectorAndSpamTellsNobody(): void
    {
        $proposer = $this->user();
        $corrector = $this->user();
        $spammer = $this->user();
        $route = $this->route(ItemState::Submitted, (int) $proposer->getId());
        $live = $this->route(ItemState::Verified, null);
        $correction = $this->correction($live, $corrector);
        $junk = $this->route(ItemState::Submitted, (int) $spammer->getId());

        $this->routes()->trashProposal((int) $route->getId(), $this->user(), StatementGround::Abuse, 'The name insults a named person.');
        $this->routes()->trashSuggestion((int) $correction->getId(), $this->user(), StatementGround::Abuse, 'Threatens the route\'s author.');
        $this->routes()->trashProposal((int) $junk->getId(), $this->user(), StatementGround::Spam);

        foreach ([$proposer, $corrector] as $rider) {
            $statement = $this->statementOn($rider, UserMessageKind::StatementOfReasons);
            self::assertSame(StatementDecision::Removed, $statement->decision);
            self::assertSame(StatementGround::Abuse, $statement->ground);
        }
        self::assertSame([], $this->em->getRepository(UserMessage::class)->findBy(['userId' => $spammer->getId()]));
    }

    private function statementOn(User $rider, UserMessageKind $kind): StatementOfReasons
    {
        $message = $this->em->getRepository(UserMessage::class)->findOneBy(['userId' => $rider->getId(), 'kind' => $kind, 'trashedAt' => null]);
        self::assertInstanceOf(UserMessage::class, $message, $kind->value);
        if (UserMessageKind::StatementOfReasons === $kind) {
            self::assertSame(MessageService::STATEMENT_CHANNEL, $message->getChannel());
        }
        $statement = StatementOfReasons::fromArray($message->getBodyParams()[StatementOfReasons::PARAM] ?? null);
        self::assertNotNull($statement, $kind->value);

        return $statement;
    }

    private function routes(): RouteModerationService
    {
        return static::getContainer()->get(RouteModerationService::class);
    }

    private function route(ItemState $state, ?int $proposedBy): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Boucle des Motifs')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:route-sor-'.bin2hex(random_bytes(8)))->setRegionId(1)
            ->setProposedBy($proposedBy);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function correction(RecommendedRoute $route, User $rider): RouteSuggestion
    {
        $s = new RouteSuggestion((int) $route->getId(), (int) $rider->getId(), RouteSuggestionReason::Other, 'Abusive text');
        $this->em->persist($s);
        $this->em->flush();

        return $s;
    }

    private function user(): User
    {
        $u = (new User())->setEmail('route-sor-'.uniqid('', true).'@statement.test');
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }
}
