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
    private static function gpxFixture(): string
    {
        $pts = '';
        for ($i = 0; $i <= 100; ++$i) {
            $pts .= sprintf('<trkpt lat="%.4f" lon="5.3000"><ele>%d</ele></trkpt>', 50.0 + $i * 0.001, 100 + $i);
        }
        $path = tempnam(sys_get_temp_dir(), 'cc-gpx-').'.gpx';
        file_put_contents($path, '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
            .'<trk><trkseg>'.$pts.'</trkseg></trk></gpx>');

        return $path;
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
        $form['propose_route[surface]'] = 'Asphalt';
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

        $path = tempnam(sys_get_temp_dir(), 'cc-bad-').'.gpx';
        file_put_contents($path, '<gpx><trk><trkseg>'); // malformed

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
        $form['propose_route[surface]'] = 'Asphalt';
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
