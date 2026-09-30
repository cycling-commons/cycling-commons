<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Moderation\RouteModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Route-domain spec §5: /propose-route is the rider intake for R.
 * The old improve?type=quality-rides&mode=add entry is repointed here.
 */
final class ProposeRouteFlowTest extends WebTestCase
{
    /** @var list<string> temp upload files to unlink after each test */
    private static array $tmpFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach (self::$tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        self::$tmpFiles = [];
        parent::tearDown();
    }

    /**
     * Build a temp upload with the given extension/content. `tempnam()` creates
     * an extension-less file we don't use, so drop it immediately and keep only
     * the suffixed sibling — registered for cleanup in tearDown().
     */
    private static function tmpUpload(string $suffix, string $content): string
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'cc-');
        $path = $base.$suffix;
        @unlink($base);
        file_put_contents($path, $content);
        self::$tmpFiles[] = $path;

        return $path;
    }

    private static function gpxFixture(): string
    {
        $pts = '';
        for ($i = 0; $i <= 100; ++$i) {
            $pts .= sprintf('<trkpt lat="%.4f" lon="5.3000"><ele>%d</ele></trkpt>', 50.0 + $i * 0.001, 100 + $i);
        }

        return self::tmpUpload('.gpx', '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
            .'<trk><trkseg>'.$pts.'</trkseg></trk></gpx>');
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/propose-route');
        self::assertResponseRedirects();
    }

    public function testFormRendersForRiders(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('route-form@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/propose-route');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[name="propose_route"]')->count());
        self::assertSame(1, $crawler->filter('input[type="file"][name="propose_route[gpx]"]')->count());
    }

    public function testValidProposalPersistsAndShowsReceipt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('route-submit@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/propose-route');
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[rName]'] = 'Condroz · flow test';
        $form['propose_route[difficulty]'] = 'Moderate';
        $form['propose_route[season][1]']->tick();   // Summer — season is a multi-select now
        $form['propose_route[dominantSurface]'] = 'Asphalt';
        // This DomCrawler's FileFormField::upload() takes a path (?string), not
        // an UploadedFile, so attach the file via the documented $files-array
        // request recipe: the UploadedFile (original name + mime) reaches the
        // controller unchanged (HttpKernelBrowser::filterFiles passes it through).
        $client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'propose_route' => ['gpx' => new UploadedFile(self::gpxFixture(), 'condroz.gpx', 'application/gpx+xml', null, true)],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('CC-R', (string) $client->getResponse()->getContent(), 'receipt reference shown');

        $route = $em->getRepository(RecommendedRoute::class)->findOneBy(['name' => 'Condroz · flow test']);
        self::assertNotNull($route);
        self::assertSame(ItemState::Submitted, $route->getState());
        self::assertSame($user->getId(), $route->getProposedBy());

        // The reference is the link to this route's card under the Routes chip
        // of the rider's Contributions, beside "Back to contributing" in one row.
        $receipt = new Crawler((string) $client->getResponse()->getContent());
        $ref = $receipt->filter('.receipt-acts a.ref-link');
        self::assertSame(1, $ref->count(), 'reference renders as a link');
        self::assertSame('/account/contributions?letter=R#route-'.$route->getId(), $ref->attr('href'));
        self::assertStringContainsString(sprintf('CC-R%05d', (int) $route->getId()), $ref->text());
        self::assertStringContainsString('Your reference', $ref->text(), 'the label stays with the code');
        self::assertStringStartsWith('Your reference CC-R', (string) $ref->attr('aria-label'));
        self::assertSame(1, $receipt->filter('.receipt-acts a[href$="/contribute"]')->count(), 'way on sits in the same row');
    }

    public function testInvalidGpxShowsFormErrorAndPersistsNothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('route-invalid@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $path = self::tmpUpload('.gpx', '<gpx><trk><trkseg>'); // malformed

        $client->loginUser($user);
        $crawler = $client->request('GET', '/propose-route');
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[rName]'] = 'Broken upload';
        // See testValidProposalPersistsAndShowsReceipt: attach via the $files array.
        $client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'propose_route' => ['gpx' => new UploadedFile($path, 'bad.gpx', 'application/gpx+xml', null, true)],
        ]);

        // A submitted-but-invalid form re-renders with a 422 (Symfony's
        // AbstractController::render() sets it for any submitted+invalid form in
        // the template params — same house behaviour as AddClimbController). The
        // malformed GPX is surfaced as a FormError; the key guarantee below is
        // that nothing was persisted.
        self::assertResponseStatusCodeSame(422);
        // The FormError message must render translated, not as the raw key: the
        // controller runs $e->getMessage() through the translator before display.
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('The file could not be read as a GPX track.', $content);
        self::assertStringNotContainsString('propose_route.error.', $content);
        self::assertSame(0, $em->getRepository(RecommendedRoute::class)->count(['name' => 'Broken upload']));
    }

    public function testWrongExtensionRendersTranslatedConstraintMessage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('route-badext@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/propose-route');
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[rName]'] = 'Wrong extension';
        // A .txt upload trips the File(extensions: ['gpx' => …]) constraint. Its
        // message key (propose_route.error.gpx_type) lives in the `messages`
        // domain; with framework.validation.translation_domain: messages the
        // rendered form error must be the English sentence, never the raw key.
        $txt = self::tmpUpload('.txt', 'this is plainly not a gpx track');
        $client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'propose_route' => ['gpx' => new UploadedFile($txt, 'notes.txt', 'text/plain', null, true)],
        ]);

        self::assertResponseStatusCodeSame(422);
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('That does not look like a GPX file (.gpx).', $content);
        self::assertStringNotContainsString('propose_route.error.', $content);
    }

    /**
     * Regression guard for the framework.validation.translation_domain: messages
     * switch (commit 670d8a5): constraint messages now resolve against the
     * `messages` catalogue instead of Symfony's built-in `validators` one, so every
     * user-facing constraint must carry an explicit message KEY or fr/nl/de users
     * fall back to the English default. On the French locale a blank required field
     * must render the French copy, not the English built-in default.
     */
    public function testFrenchLocaleRendersTranslatedConstraintMessage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('route-fr-blank@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/fr/propose-route');
        self::assertResponseIsSuccessful();

        // Everything valid EXCEPT the required name, so only the rName NotBlank
        // fires. Its key (propose_route.error.name_required) must resolve to the
        // French copy via the messages domain.
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[rName]'] = '';
        $form['propose_route[difficulty]'] = 'Moderate';
        $form['propose_route[season][1]']->tick();   // Summer — season is a multi-select now
        $form['propose_route[dominantSurface]'] = 'Asphalt';
        // See testValidProposalPersistsAndShowsReceipt: attach via the $files array.
        $client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'propose_route' => ['gpx' => new UploadedFile(self::gpxFixture(), 'condroz.gpx', 'application/gpx+xml', null, true)],
        ]);

        self::assertResponseStatusCodeSame(422);
        $content = (string) $client->getResponse()->getContent();
        // French name_required copy is 'Veuillez nommer l'itinéraire.'; Twig
        // HTML-escapes the apostrophe (&#039;), so match the apostrophe-free prefix.
        self::assertStringContainsString('Veuillez nommer', $content);
        self::assertStringNotContainsString('This value should not be blank.', $content);
        self::assertStringNotContainsString('propose_route.error.', $content);
    }

    public function testContributeHubCardPointsToProposeRoute(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contribute');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('a[href*="type=quality-rides"]')->count(), 'old improve deep-link is gone');
        self::assertGreaterThan(0, $crawler->filter('a[href$="/propose-route"]')->count(), 'hub card targets the propose flow');
    }

    public function testProfileListsMyRouteProposals(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('route-profile@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/propose-route');
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[rName]'] = 'Condroz · profile test';
        $form['propose_route[difficulty]'] = 'Moderate';
        $form['propose_route[season][1]']->tick();   // Summer — season is a multi-select now
        $form['propose_route[dominantSurface]'] = 'Asphalt';
        // See testValidProposalPersistsAndShowsReceipt: attach via the $files array.
        $client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'propose_route' => ['gpx' => new UploadedFile(self::gpxFixture(), 'condroz.gpx', 'application/gpx+xml', null, true)],
        ]);

        $crawler = $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Condroz · profile test', (string) $client->getResponse()->getContent());
        // Route proposals render as shared record cards carrying the route tag
        // (profile rework 2026-07-14 replaced the old .acct-route-list markup).
        self::assertGreaterThan(0, $crawler->filter('.q-item .q-tag--route')->count());

        // The receipt's link target: the Routes chip view, with the card anchored.
        $route = $em->getRepository(RecommendedRoute::class)->findOneBy(['name' => 'Condroz · profile test']);
        self::assertNotNull($route);
        $crawler = $client->request('GET', '/account/contributions?letter=R');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#route-'.$route->getId().'.q-item')->count(), 'route card carries the receipt anchor');
        self::assertSame(0, $crawler->filter('#p-contrib .empty-state')->count(), 'a rider with only routes is not told they have no contributions');
    }

    /** @param list<string> $roles */
    private function rider(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $u = (new User())->setEmail($email)->setDisplayName('Edit Rider');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles($roles);
        $u->setPassword('x');
        if ([] !== $roles) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function proposal(EntityManagerInterface $em, User $by, ItemState $state = ItemState::Submitted): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Edit me · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(24000)->setState($state)
            ->setSource(ItemSource::User)->setSourceRef('user:edit-'.bin2hex(random_bytes(6)))->setRegionId(1)
            ->setAttributes(['difficulty' => ['score' => 2, 'label' => 'Moderate'], 'dominantSurface' => 'Asphalt', 'season' => ['Summer'], 'photos' => [['sm' => '/x.jpg']]])
            ->setProposedBy((int) $by->getId());
        $em->persist($r);
        $em->flush();

        return $r;
    }

    /**
     * route-domain.md §4.6: the proposer reopens the proposal form, prefilled,
     * while the route waits for review. Saving without a GPX keeps the track
     * and its photos, and the curator's desk shows the saved version.
     */
    public function testProposerEditsTheirSubmittedProposal(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->rider($em, 'route-edit-own@test.test');
        $route = $this->proposal($em, $user);
        $id = (int) $route->getId();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/propose-route/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="propose_route"]')->form();
        self::assertSame('Edit me · Condroz', $form['propose_route[rName]']->getValue(), 'name prefilled');
        self::assertSame('Moderate', $form['propose_route[difficulty]']->getValue(), 'difficulty prefilled');
        self::assertSame('Asphalt', $form['propose_route[dominantSurface]']->getValue(), 'surface prefilled');
        self::assertSame(1, $crawler->filter('input[name="propose_route[mediaIds]"]')->count(), 'the edit carries the photo field');
        self::assertNotNull($crawler->filter('input[type="file"][name="propose_route[gpx]"]')->attr('name'));
        self::assertNull($crawler->filter('input[type="file"][name="propose_route[gpx]"]')->attr('required'), 'GPX optional');

        $form['propose_route[rName]'] = 'Edited · Condroz';
        $form['propose_route[note]'] = 'Coffee stop at the church.';
        $bike = $form['propose_route[bikeTypes][0]'];
        self::assertInstanceOf(ChoiceFormField::class, $bike);
        $bike->tick();
        $client->submit($form);
        self::assertResponseRedirects('/account/contributions?letter=R#route-'.$id, 303);

        $em->clear();
        $saved = $em->find(RecommendedRoute::class, $id);
        self::assertNotNull($saved);
        self::assertSame('Edited · Condroz', $saved->getName());
        self::assertSame(ItemState::Submitted, $saved->getState());
        self::assertSame('Coffee stop at the church.', $saved->getAttributes()['note'] ?? null);
        self::assertNotEmpty($saved->getAttributes()['bikeTypes'] ?? null);
        self::assertSame([['sm' => '/x.jpg']], $saved->getAttributes()['photos'] ?? null, 'photos kept');
        self::assertSame(24000, $saved->getDistanceM(), 'track kept without a new GPX');

        $client->followRedirect();
        self::assertSelectorTextContains('#p-contrib .flash-success', 'Route proposal saved');

        // The desk reads the row itself: the curator sees the saved version.
        $client->loginUser($this->rider($em, 'route-edit-curator@test.test', ['ROLE_CURATOR']));
        $client->request('GET', '/moderate/routes/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Edited · Condroz');
    }

    /** A new GPX replaces the track and what is derived from it. */
    public function testProposerReplacesTheGpx(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->rider($em, 'route-edit-gpx@test.test');
        $id = (int) $this->proposal($em, $user)->getId();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/propose-route/'.$id.'/edit');
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'propose_route' => ['gpx' => new UploadedFile(self::gpxFixture(), 'new.gpx', 'application/gpx+xml', null, true)],
        ]);
        self::assertResponseRedirects('/account/contributions?letter=R#route-'.$id, 303);

        $em->clear();
        $saved = $em->find(RecommendedRoute::class, $id);
        self::assertNotNull($saved);
        self::assertNotSame(24000, $saved->getDistanceM(), 'distance recomputed from the new track');
        self::assertStringContainsString('5.3', (string) $saved->getGeom());
        self::assertSame('Edit me · Condroz', $saved->getName());
    }

    /** Another rider's proposal is not there for anyone else: 404 on GET and POST. */
    public function testAnotherRiderCannotEditTheProposal(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->rider($em, 'route-edit-owner@test.test');
        $id = (int) $this->proposal($em, $owner)->getId();

        $client->loginUser($this->rider($em, 'route-edit-other@test.test'));
        $client->request('GET', '/propose-route/'.$id.'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/propose-route/'.$id.'/edit', ['propose_route' => ['rName' => 'Hijacked']]);
        self::assertResponseStatusCodeSame(404);

        $em->clear();
        self::assertSame('Edit me · Condroz', $em->find(RecommendedRoute::class, $id)?->getName());
    }

    /** A POST without the form's CSRF token changes nothing. */
    public function testEditWithoutCsrfTokenChangesNothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->rider($em, 'route-edit-csrf@test.test');
        $id = (int) $this->proposal($em, $user)->getId();

        $client->loginUser($user);
        $client->request('POST', '/propose-route/'.$id.'/edit', ['propose_route' => [
            'rName' => 'No token', 'difficulty' => 'Moderate', 'dominantSurface' => 'Asphalt',
        ]]);
        self::assertResponseStatusCodeSame(422);

        $em->clear();
        self::assertSame('Edit me · Condroz', $em->find(RecommendedRoute::class, $id)?->getName());
    }

    /**
     * route-domain.md §4.6: a curator's desk edit made while the proposer's
     * form is open survives the proposer's later save of other fields. The
     * save writes only what the proposer changed from what the form showed.
     */
    public function testACuratorsDeskEditSurvivesTheProposersLaterSave(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->rider($em, 'route-edit-race@test.test');
        $id = (int) $this->proposal($em, $user)->getId();

        $client->loginUser($user);
        $form = $client->request('GET', '/propose-route/'.$id.'/edit')->filter('form[name="propose_route"]')->form();

        // The curator sets the difficulty on the Routes desk while the form is open.
        $curator = $this->rider($em, 'route-edit-race-curator@test.test', ['ROLE_CURATOR']);
        static::getContainer()->get(RouteModerationService::class)->editMetadata($id, ['difficulty' => 'Hard'], $curator);

        $form['propose_route[note]'] = 'Coffee stop at the church.';
        $client->submit($form);
        self::assertResponseRedirects('/account/contributions?letter=R#route-'.$id, 303);

        $em->clear();
        $saved = $em->find(RecommendedRoute::class, $id);
        self::assertNotNull($saved);
        self::assertSame('Hard', $saved->getAttributes()['difficulty']['label'] ?? null, 'the curator\'s difficulty stands');
        self::assertSame('Coffee stop at the church.', $saved->getAttributes()['note'] ?? null, 'the proposer\'s own change is saved');
        self::assertSame('Edit me · Condroz', $saved->getName());
    }

    /**
     * A field a curator has edited stays the curator's while the proposal
     * waits, even when the proposer changes it too: the save keeps the
     * curator's value and says so, and the reopened form shows it locked.
     */
    public function testAFieldTheCuratorEditedStaysTheirs(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->rider($em, 'route-edit-held@test.test');
        $id = (int) $this->proposal($em, $user)->getId();

        $client->loginUser($user);
        $form = $client->request('GET', '/propose-route/'.$id.'/edit')->filter('form[name="propose_route"]')->form();

        $curator = $this->rider($em, 'route-edit-held-curator@test.test', ['ROLE_CURATOR']);
        static::getContainer()->get(RouteModerationService::class)->editMetadata($id, ['difficulty' => 'Hard'], $curator);

        $form['propose_route[difficulty]'] = 'Easy';
        $form['propose_route[note]'] = 'Coffee stop at the church.';
        $client->submit($form);
        self::assertResponseRedirects('/account/contributions?letter=R#route-'.$id, 303);

        $em->clear();
        $saved = $em->find(RecommendedRoute::class, $id);
        self::assertNotNull($saved);
        self::assertSame('Hard', $saved->getAttributes()['difficulty']['label'] ?? null);
        self::assertSame('Coffee stop at the church.', $saved->getAttributes()['note'] ?? null);

        $client->followRedirect();
        self::assertSelectorTextContains('#p-contrib .flash-success', 'A curator changed Difficulty while you were editing');

        $crawler = $client->request('GET', '/propose-route/'.$id.'/edit');
        self::assertSame('disabled', $crawler->filter('select[name="propose_route[difficulty]"]')->attr('disabled'), 'the curator\'s field is locked');
        self::assertNull($crawler->filter('select[name="propose_route[dominantSurface]"]')->attr('disabled'), 'the others stay open');
        self::assertStringContainsString('A curator changed Difficulty while reviewing', $crawler->filter('[data-curator-held]')->text());

        // A save from the locked form leaves the curator's field alone and has nothing to report.
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[note]'] = 'Second visit.';
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('#p-contrib .flash-success', 'Route proposal saved. The curator reviews this version.');
        $em->clear();
        $saved = $em->find(RecommendedRoute::class, $id);
        self::assertNotNull($saved);
        self::assertSame('Hard', $saved->getAttributes()['difficulty']['label'] ?? null);
        self::assertSame('Second visit.', $saved->getAttributes()['note'] ?? null);
    }

    /** The Routes desk card says when the proposer changed the proposal after sending it. */
    public function testTheDeskCardShowsTheProposerRevisedIt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->rider($em, 'route-edit-marker@test.test');
        $id = (int) $this->proposal($em, $user)->getId();
        $curator = $this->rider($em, 'route-edit-marker-curator@test.test', ['ROLE_CURATOR']);

        $client->loginUser($curator);
        $crawler = $client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-item-id="'.$id.'"] [data-revised]')->count(), 'never revised: no marker');

        $client->loginUser($user);
        $form = $client->request('GET', '/propose-route/'.$id.'/edit')->filter('form[name="propose_route"]')->form();
        $form['propose_route[note]'] = 'Coffee stop at the church.';
        $client->submit($form);
        self::assertResponseRedirects('/account/contributions?letter=R#route-'.$id, 303);

        $client->loginUser($curator);
        $crawler = $client->request('GET', '/moderate/routes');
        $marker = $crawler->filter('[data-item-id="'.$id.'"] [data-revised]');
        self::assertSame(1, $marker->count(), 'the card carries the revised marker');
        self::assertStringContainsString('Revised by the proposer', $marker->text());
        self::assertNotSame('', (string) $marker->attr('datetime'), 'with the time');
    }

    /** Once a curator has decided, the edit sends the proposer back with a notice. */
    public function testDecidedProposalIsNoLongerEditable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->rider($em, 'route-edit-late@test.test');
        $id = (int) $this->proposal($em, $user, ItemState::Unverified)->getId();

        $client->loginUser($user);
        $client->request('GET', '/propose-route/'.$id.'/edit');
        self::assertResponseRedirects('/account/contributions?letter=R#route-'.$id, 303);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('can no longer be edited', $crawler->filter('.cc-notice')->text());
        self::assertSame(0, $crawler->filter('#route-'.$id.' a[href$="/edit"]')->count(), 'no Edit link on a decided proposal');
    }
}
