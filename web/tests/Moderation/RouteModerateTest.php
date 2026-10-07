<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Region;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Contribution\RouteProposalService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RouteModerateTest extends WebTestCase
{
    private function curator(): User
    {
        $c = static::getContainer();
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $em = $c->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('curator@test.test')->setDisplayName('C');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword($hasher->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    // Task 4: decisions now write the proposer a message, and user_message.user_id
    // has a real FK to users(id) — proposedBy must be a persisted user, not a
    // fabricated id.
    private function proposer(EntityManagerInterface $em): User
    {
        $u = (new User())->setEmail('proposer-'.uniqid('', true).'@test.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function submittedRoute(EntityManagerInterface $em): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Desk proposal · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(24000)->setState(ItemState::Submitted)
            ->setSource(ItemSource::User)->setSourceRef('user:desk-1')->setRegionId(1)->setProposedBy((int) $this->proposer($em)->getId());
        $em->persist($r);
        $em->flush();

        return $r;
    }

    public function testRiderIsForbiddenFromTheRoutesDesk(): void
    {
        $client = static::createClient();
        $rider = (new User())->setEmail('rider@test.test')->setDisplayName('R');
        $rider->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $rider->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($rider, 'password1234'));
        static::getContainer()->get(EntityManagerInterface::class)->persist($rider);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($rider);
        $client->request('GET', '/moderate/routes');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCuratorSeesPendingProposalAndApprovesIt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);

        $client->loginUser($this->curator());
        $client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Desk proposal · Condroz');

        // The decision form moved off the queue list onto the proposal's own
        // detail page (the queue item now just links there for review).
        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Record decision')->form([
            'route_decision[route_id]' => (string) $route->getId(),
            'route_decision[decision]' => 'approve',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        self::assertSame(ItemState::Unverified, $em->find(RecommendedRoute::class, $route->getId())->getState());
    }

    private function activeRoute(EntityManagerInterface $em): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Active loop · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(18000)->setState(ItemState::Verified)
            ->setSource(ItemSource::User)->setSourceRef('user:desk-active')->setRegionId(1)->setProposedBy((int) $this->proposer($em)->getId());
        $em->persist($r);
        $em->flush();

        return $r;
    }

    public function testCuratorRetiresAnActiveRouteFromTheDetailPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->activeRoute($em);

        $client->loginUser($this->curator());
        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Retire this route')->form([
            'route_decision[note]' => 'Superseded by a better loop.',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        self::assertSame(ItemState::Retired, $em->find(RecommendedRoute::class, $route->getId())->getState());
    }

    public function testDetailShowsTrackMetadataAndRegionCapContext(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);

        $client->loginUser($this->curator());
        $client->request('GET', '/moderate/routes/'.$route->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Desk proposal · Condroz');
        self::assertSelectorTextContains('body', '24 km');
        // The region's active-vs-cap context is shown so the curator sees head-room:
        // the real configured cap (30, route.region_active_cap), not the active count itself.
        self::assertSelectorExists('[data-region-cap]');
        self::assertSelectorTextContains('[data-region-cap]', '0 / 30');
        // The curator picks the decision; nothing is preselected, and a proposal cannot be retired.
        self::assertSelectorExists('select[name="route_decision[decision]"][required] option[value=""][selected]');
        self::assertSelectorNotExists('select[name="route_decision[decision]"] option[value="retire"]');
    }

    private function wallonia(EntityManagerInterface $em): Region
    {
        $region = (new Region())->setSlug('wallonia')->setName('Wallonia registry name')->setCountryCode('BE')->setLabels(['en' => 'Wallonia', 'fr' => 'Wallonie']);
        $em->persist($region);
        $em->flush();

        return $region;
    }

    /**
     * One pseudonym rule on both desk pages (moderation-and-contribution.md):
     * a private proposer is `rider#<pseudonym>` with no link, a public one is their
     * display name linked to /riders/{uuid}, and the queue card agrees with the
     * review page.
     */
    public function testQueueAndDetailNameTheProposerByTheSameRule(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $proposer = $em->find(User::class, $route->getProposedBy());
        self::assertInstanceOf(User::class, $proposer);
        $proposer->setDisplayName('Route Rider');
        $em->flush();
        $pseudonym = 'rider#'.$proposer->getPseudonym();

        $client->loginUser($this->curator());
        $client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.rd-meta [data-meta="proposer"]', $pseudonym);
        self::assertSelectorTextNotContains('.rd-meta', 'Route Rider');
        self::assertSelectorNotExists('.rd-meta a[href*="/riders/"]');
        $client->request('GET', '/moderate/routes');
        self::assertSelectorTextContains('.q-item[data-item-id="'.$route->getId().'"] .q-submitter', $pseudonym);
        self::assertSelectorNotExists('.q-item[data-item-id="'.$route->getId().'"] a[href*="/riders/"]');

        // The client rebooted the kernel between requests, so the change goes through the live container.
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('UPDATE users SET public_profile = TRUE WHERE id = ?', [$proposer->getId()]);
        $profile = 'a[href$="/riders/'.$proposer->getUuid().'"]';
        $client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertSelectorTextContains('.rd-meta [data-meta="proposer"] '.$profile, 'Route Rider');
        self::assertSelectorTextNotContains('.rd-meta', $pseudonym);
        $client->request('GET', '/moderate/routes');
        self::assertSelectorTextContains('.q-item[data-item-id="'.$route->getId().'"] .q-submitter '.$profile, 'Route Rider');
        self::assertSelectorTextNotContains('.q-item[data-item-id="'.$route->getId().'"] .q-submitter', $pseudonym);
    }

    /** A proposer whose account is gone is the removed label, never a handle and never a link. */
    public function testARemovedProposerIsTheRemovedLabel(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $em->getConnection()->executeStatement('UPDATE recommended_route SET proposed_by = 987654 WHERE id = ?', [$route->getId()]);

        $client->loginUser($this->curator());
        $client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.rd-meta [data-meta="proposer"]', 'a removed rider');
        self::assertSelectorTextNotContains('.rd-meta [data-meta="proposer"]', 'rider#');
        self::assertSelectorNotExists('.rd-meta [data-meta="proposer"] a');
        $client->request('GET', '/moderate/routes');
        self::assertSelectorTextContains('.q-item[data-item-id="'.$route->getId().'"] .q-submitter', 'a removed rider');
        self::assertSelectorNotExists('.q-item[data-item-id="'.$route->getId().'"] .q-submitter a');
    }

    /** The Region row names the region in the page's language, never its id. */
    public function testDetailNamesTheRegionInThePageLanguage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $region = $this->wallonia($em);
        $route->setRegionId((int) $region->getId());
        $em->flush();

        $client->loginUser($this->curator());
        $client->request('GET', '/fr/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.rd-meta [data-meta="region"] td:last-child', 'Wallonie');
        $client->request('GET', '/fr/moderate/routes');
        self::assertSelectorTextContains('.q-item[data-item-id="'.$route->getId().'"] .q-submitter', 'Wallonie');
    }

    /** Every detail the rider filled in on /propose-route is on the review page, in the page's language. */
    public function testDetailShowsTheRidersRouteDetails(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $route->setAttributes([
            'difficulty' => ['score' => 5, 'label' => 'Very hard'],
            'dominantSurface' => 'Asphalt',
            'season' => ['Spring', 'Autumn'],
            'bikeTypes' => ['Road', 'MTB'],
            'gradientLimited' => '≤6%',
            'note' => 'Start at the station.',
        ]);
        $em->flush();

        $client->loginUser($this->curator());
        $client->request('GET', '/fr/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('[data-meta="difficulty"] td:last-child', 'Très difficile');
        self::assertSelectorTextContains('[data-meta="dominant_surface"] td:last-child', 'Asphalte');
        self::assertSelectorTextSame('[data-meta="season"] td:last-child', 'Printemps, Automne');
        self::assertSelectorTextSame('[data-meta="bike_types"] td:last-child', 'Route, VTT');
        self::assertSelectorTextSame('[data-meta="gradient"] td:last-child', 'Tout le parcours ≤ 6 %');
        self::assertSelectorTextSame('[data-meta="rider_note"] td:last-child', 'Start at the station.');

        // A detail the rider left unset shows a dash, the same as every other empty row.
        $route->setAttributes(['dominantSurface' => 'Gravel']);
        $em->flush();
        $client->request('GET', '/moderate/routes/'.$route->getId());
        foreach (['difficulty', 'season', 'bike_types', 'gradient', 'rider_note'] as $row) {
            self::assertSelectorTextSame('[data-meta="'.$row.'"] td:last-child', '-');
        }
    }

    /** "N / cap active" explains itself on hover and to assistive technology, on both pages. */
    public function testRegionCapExplainsItself(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $region = $this->wallonia($em);
        $route->setRegionId((int) $region->getId());
        $em->flush();
        $help = 'Live recommended routes in Wallonia: 0 of at most 30. Approving adds one; once there are 30, a route must be retired first.';

        $client->loginUser($this->curator());
        foreach (['/moderate/routes/'.$route->getId(), '/moderate/routes'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $cap = $crawler->filter('[data-region-cap]')->first();
            self::assertSame($help, $cap->attr('title'));
            $describedBy = (string) $cap->attr('aria-describedby');
            self::assertNotSame('', $describedBy);
            self::assertSame($help, trim($crawler->filter('#'.$describedBy)->text()));
        }
    }

    public function testCuratorEditsRouteNoteViaTheDetailForm(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $route->setAttributes(['note' => 'Old note']);
        $em->flush();

        $client->loginUser($this->curator());
        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        $form = $crawler->selectButton('Save changes')->form();
        $form['route_edit[note]'] = 'A quiet Condroz loop, resurfaced 2025.';
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        self::assertSame('A quiet Condroz loop, resurfaced 2025.', $em->find(RecommendedRoute::class, $route->getId())->getAttributes()['note']);
    }

    /**
     * route-domain.md §5: a desk form opened before the proposer revised the
     * proposal writes only what the curator changed, so the proposer's newer
     * field survives the curator's save of another one.
     */
    public function testAStaleDeskFormKeepsTheProposersNewerField(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $id = (int) $route->getId();

        $client->loginUser($this->curator());
        $form = $client->request('GET', '/moderate/routes/'.$id)->selectButton('Save changes')->form();

        $this->proposerRevises($id, ['difficulty' => 'Easy']);

        $form['route_edit[note]'] = 'Coffee at the church.';
        $client->submit($form);
        self::assertResponseRedirects('/moderate/routes/'.$id);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(RecommendedRoute::class, $id);
        self::assertNotNull($saved);
        self::assertSame('Easy', $saved->getAttributes()['difficulty']['label'] ?? null, 'the proposer\'s newer difficulty stands');
        self::assertSame('Coffee at the church.', $saved->getAttributes()['note'] ?? null, 'the curator\'s own change is saved');

        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Route details saved.');
    }

    /**
     * Both changed the same field: the curator's save of it is refused with a
     * notice naming it, the proposer's value stays, and the rest saves.
     */
    public function testAStaleDeskFormChangingTheProposersNewerFieldIsRefusedForThatField(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $id = (int) $route->getId();

        $client->loginUser($this->curator());
        $form = $client->request('GET', '/moderate/routes/'.$id)->selectButton('Save changes')->form();

        $this->proposerRevises($id, ['difficulty' => 'Easy']);

        $form['route_edit[difficulty]'] = 'Hard';
        $form['route_edit[note]'] = 'Coffee at the church.';
        $client->submit($form);
        self::assertResponseRedirects('/moderate/routes/'.$id);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(RecommendedRoute::class, $id);
        self::assertNotNull($saved);
        self::assertSame('Easy', $saved->getAttributes()['difficulty']['label'] ?? null, 'the proposer\'s difficulty stands');
        self::assertSame('Coffee at the church.', $saved->getAttributes()['note'] ?? null, 'the other field saves');
        $curatorRows = (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM route_change_history WHERE route_id = ? AND field = 'difficulty'", [$id]);
        self::assertSame(0, $curatorRows, 'a refused field writes no history');

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Difficulty changed while you were editing');
        self::assertSame('Easy', $crawler->selectButton('Save changes')->form()['route_edit[difficulty]']->getValue(), 'the reloaded form shows the proposer\'s value');
    }

    /** @param array<string, mixed> $fields */
    private function proposerRevises(int $routeId, array $fields): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $em->find(RecommendedRoute::class, $routeId);
        self::assertNotNull($route);
        $proposer = $em->find(User::class, $route->getProposedBy());
        self::assertInstanceOf(User::class, $proposer);
        static::getContainer()->get(RouteProposalService::class)->revise($routeId, null, ['rName' => $route->getName(), ...$fields], $proposer);
        $em->clear();
    }

    /**
     * The desk form carries every registry field, and a route that has none of
     * them set renders each one empty rather than guessing a default.
     *
     * @see docs/specs/edit-items/R-quality-rides.md
     */
    public function testTheDeskFormCarriesEveryFieldAndOffersAnUnsetRouteEmptyOnes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $route->setAttributes([]);
        $em->flush();

        $client->loginUser($this->curator());
        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save changes')->form();

        foreach (['rName', 'difficulty', 'season', 'dominantSurface', 'note', 'bikeTypes', 'gradientLimited', 'bestDirection'] as $field) {
            self::assertTrue($form->has('route_edit['.$field.']'), $field.' is on the curator form');
        }
        self::assertSame($route->getName(), $form['route_edit[rName]']->getValue());
        foreach (['difficulty', 'dominantSurface', 'gradientLimited', 'bestDirection'] as $select) {
            self::assertSame('', $form['route_edit['.$select.']']->getValue(), $select.' shows empty, not a guessed default');
        }
        self::assertSame([], self::ticked($form, 'route_edit[season]'));
        self::assertSame([], self::ticked($form, 'route_edit[bikeTypes]'));
        self::assertSame('', $form['route_edit[note]']->getValue());
    }

    /**
     * The values ticked in an expanded multi-select. DomCrawler hands such a
     * group back as one field per box, not as a single multi-valued field.
     *
     * @return list<string>
     */
    private static function ticked(Form $form, string $name): array
    {
        $out = [];
        foreach ($form[$name] as $box) {
            if (null !== $box->getValue() && '' !== $box->getValue()) {
                $out[] = (string) $box->getValue();
            }
        }

        return $out;
    }

    /** @param list<string> $values */
    private static function tick(Form $form, string $name, array $values): void
    {
        foreach ($form[$name] as $box) {
            $options = array_values(array_filter($box->availableOptionValues(), static fn (string $v): bool => '' !== $v));
            if (\in_array($options[0] ?? '', $values, true)) {
                $box->tick();
            } else {
                $box->untick();
            }
        }
    }

    /** A curator sets the seven fields, and the form reads them back on reload. */
    public function testCuratorSetsEveryFieldAndTheFormReadsThemBack(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);

        $client->loginUser($this->curator());
        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        $form = $crawler->selectButton('Save changes')->form();
        $form['route_edit[rName]'] = 'Condroz · the long way round';
        $form['route_edit[difficulty]'] = 'Hard';
        self::tick($form, 'route_edit[season]', ['Spring', 'Autumn']);
        $form['route_edit[dominantSurface]'] = 'Asphalt';
        self::tick($form, 'route_edit[bikeTypes]', ['Road', 'Gravel']);
        $form['route_edit[gradientLimited]'] = '≤6%';
        $form['route_edit[bestDirection]'] = 'Clockwise';
        $form['route_edit[note]'] = 'Gentle all the way round.';
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        $saved = $em->find(RecommendedRoute::class, $route->getId());
        self::assertSame('Condroz · the long way round', $saved->getName());
        // The canonical {score,label} shape a proposal stores, not the bare label.
        self::assertEquals(['score' => 4, 'label' => 'Hard'], $saved->getAttributes()['difficulty']);
        self::assertSame('≤6%', $saved->getAttributes()['gradientLimited']);
        self::assertSame('Clockwise', $saved->getAttributes()['bestDirection']);

        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        $form = $crawler->selectButton('Save changes')->form();
        self::assertSame('Condroz · the long way round', $form['route_edit[rName]']->getValue());
        self::assertSame('Hard', $form['route_edit[difficulty]']->getValue());
        self::assertSame(['Spring', 'Autumn'], self::ticked($form, 'route_edit[season]'));
        self::assertSame('Asphalt', $form['route_edit[dominantSurface]']->getValue());
        self::assertSame(['Road', 'Gravel'], self::ticked($form, 'route_edit[bikeTypes]'));
        self::assertSame('≤6%', $form['route_edit[gradientLimited]']->getValue());
        self::assertSame('Clockwise', $form['route_edit[bestDirection]']->getValue());
        self::assertSame('Gentle all the way round.', $form['route_edit[note]']->getValue());
    }

    /** Clearing a field on the desk form unsets it rather than storing a blank. */
    public function testCuratorClearsAFieldAndItIsUnsetNotBlank(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $route->setAttributes(['gradientLimited' => '≤9%']);
        $em->flush();

        $client->loginUser($this->curator());
        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        $form = $crawler->selectButton('Save changes')->form();
        self::assertSame('≤9%', $form['route_edit[gradientLimited]']->getValue());
        $form['route_edit[gradientLimited]'] = '';
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        self::assertArrayNotHasKey('gradientLimited', $em->find(RecommendedRoute::class, $route->getId())->getAttributes());
    }
}
