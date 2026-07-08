<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Route-domain spec §5: /propose-route is the rider intake for K.
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
        $form['propose_route[season]'] = 'Summer';
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
        $form['propose_route[season]'] = 'Summer';
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
        $form['propose_route[season]'] = 'Summer';
        $form['propose_route[dominantSurface]'] = 'Asphalt';
        // See testValidProposalPersistsAndShowsReceipt: attach via the $files array.
        $client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'propose_route' => ['gpx' => new UploadedFile(self::gpxFixture(), 'condroz.gpx', 'application/gpx+xml', null, true)],
        ]);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Condroz · profile test', (string) $client->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('.acct-route-list li')->count());
    }
}
