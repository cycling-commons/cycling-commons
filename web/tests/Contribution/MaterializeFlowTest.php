<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Materialize-on-edit (osm-data-architecture.md §6, owner decision
 * 2026-07-30): a coverage POI is improved like any other place — the same
 * wizard, location already given — except submit CREATES the item, carrying
 * the OSM ref so provenance is honest and the map dedupes the pair. An
 * already-served materialization binds the existing item instead (plain
 * edit, never a duplicate).
 */
final class MaterializeFlowTest extends WebTestCase
{
    use CoverageSchema;

    private const REF = 'node/424242';

    private function loginFreshUser(KernelBrowser $client, string $tag): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail("mat-{$tag}@example.com");
        $user->setDisplayName('Materializer');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form(['_username' => "mat-{$tag}@example.com", '_password' => 'securepass12345!']));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function seedWaterPoi(): void
    {
        $db = static::getContainer()->get(Connection::class);
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, [
            'ref' => self::REF,
            'letter' => 'C',
            'name' => 'Waterpunt Geestmerambacht',
            'lat' => 52.66,
            'lng' => 4.77,
            'country_code' => 'NL',
        ]);
    }

    public function testRefOpensThePrefilledAddWizard(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'render');
        $this->seedWaterPoi();

        $client->request('GET', '/improve?ref='.self::REF.'&type=water-food&lat=52.66&lng=4.77');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-improve-unbound]');
        self::assertSelectorExists('form[name="improve"]');
        self::assertSame(
            'Waterpunt Geestmerambacht',
            $client->getCrawler()->filter('input[name="improve[details][name]"]')->attr('value'),
            'the OSM name prefills the required name field',
        );
    }

    public function testUnknownAndMalformedRefsFallBackToTheExplainer(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'unknown');
        $this->seedWaterPoi();

        $client->request('GET', '/improve?ref=node/999999999&type=water-food');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-improve-unbound]', 'a ref the coverage cache does not hold');

        $client->request('GET', '/improve?ref=relation/12&type=water-food');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-improve-unbound]', 'only node|way refs are valid');
    }

    public function testServedMaterializationBindsTheExistingItem(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'bound');
        $this->seedWaterPoi();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('C')->setName('Bestaand waterpunt')
            ->setGeom('{"type":"Point","coordinates":[4.77,52.66]}')->setCountryCode('NL')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)
            ->setSourceRef(self::REF)->setAttributes([]);
        $em->persist($item);
        $em->flush();

        $client->request('GET', '/improve?ref='.self::REF.'&type=water-food');
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.disp', 'Bestaand waterpunt', 'plain edit of the existing item, never a duplicate');
    }

    public function testPostMaterializesTheItemCarryingTheOsmRef(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'post');
        $this->seedWaterPoi();

        $crawler = $client->request('GET', '/improve?ref='.self::REF.'&type=water-food&lat=52.66&lng=4.77');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Next →')->form([
            'improve[details][potable]' => 'Yes (public supply)',
            'improve[lat]' => '52.66',
            'improve[lng]' => '4.77',
            'improve[place]' => 'Geestmerambacht',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.receipt .ref', 'SUB-');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->findOneBy(['sourceRef' => self::REF]);
        self::assertNotNull($item, 'the item now exists, keyed by the OSM ref (map dedup)');
        self::assertSame(ItemSource::Osm, $item->getSource());
        self::assertSame(ItemState::Submitted, $item->getState());
        self::assertSame('Waterpunt Geestmerambacht', $item->getName());
        /** @var Submission $submission */
        $submission = $em->getRepository(Submission::class)->findOneBy(['itemId' => $item->getId()]);
        self::assertSame(SubmissionType::NewItem, $submission->getType());
    }

    public function testDuplicateMaterializationIsRejected(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'dupe');
        $this->seedWaterPoi();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // A Submitted (not yet served) twin: the GET can't bind it, so the
        // wizard renders — the SUBMIT is where the duplicate must be stopped.
        $twin = (new Item())->setLetter('C')->setName('Race twin')
            ->setGeom('{"type":"Point","coordinates":[4.77,52.66]}')->setCountryCode('NL')
            ->setState(ItemState::Submitted)->setSource(ItemSource::Osm)
            ->setSourceRef(self::REF)->setAttributes([]);
        $em->persist($twin);
        $em->flush();

        $crawler = $client->request('GET', '/improve?ref='.self::REF.'&type=water-food&lat=52.66&lng=4.77');
        $form = $crawler->selectButton('Next →')->form([
            'improve[lat]' => '52.66',
            'improve[lng]' => '4.77',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $em->getRepository(Item::class)->count(['sourceRef' => self::REF]), 'no second item minted');
    }

    public function testASurfaceLineOpensTheWizardWithBothEndsAndTheClassChosen(): void
    {
        // A clicked surface line: no coverage_poi row exists for it and never
        // will (lines are tile-only), the ends come from the clicked geometry,
        // and "This surface is correct" pre-picks the class it confirms.
        $client = static::createClient();
        $this->loginFreshUser($client, 'surface');

        $crawler = $client->request('GET', '/improve?ref=way/778899&type=road-surface&lat=50.49&lng=6.04&sa=6.04,50.49&sb=6.06,50.51&surface=gravel');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-improve-unbound]', 'a surface line must not fall through to "pick a place"');
        self::assertSelectorExists('input[name="improve[segment]"]');
        self::assertSame('Gravel', $crawler->filter('select[name="improve[details][surface]"] option[selected]')->attr('value'));
    }

    public function testAClassThatConfirmsNothingLeavesTheSurfaceUnchosen(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'unverified');

        // `unverified` is the absence of a claim, and `moon_dust` is not ours
        // at all. Neither may arrive in the form as a chosen answer.
        foreach (['unverified', 'moon_dust', 'Gravel'] as $cls) {
            $crawler = $client->request('GET', '/improve?ref=way/778899&type=road-surface&lat=50.49&lng=6.04&surface='.$cls);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('select[name="improve[details][surface]"] option[selected]'), $cls);
        }
    }
}
