<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Add a new place" (moderation-and-contribution.md §1.1: `mode=add`) — the
 * generic add wizard the /contribute cards link for every non-climb type.
 * The frontend (improve.js ADD mode, ImproveType hidden fields) always
 * supported this; these tests pin the restored server side: the wizard
 * renders instead of the unbound explainer, and a valid POST persists a real
 * NewItem submission + its Submitted item (§3.3), exactly like /add-climb.
 *
 * Test isolation: DAMA wraps each test in a rolled-back transaction.
 */
final class AddPlaceFlowTest extends WebTestCase
{
    private function createUser(string $email, string $plain): void
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Add Contributor');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));
        $em->persist($user);
        $em->flush();
    }

    private function loginFreshUser(KernelBrowser $client, string $tag): void
    {
        $email = "addplace-{$tag}@example.com";
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form(['_username' => $email, '_password' => $plain]);
        $client->submit($form);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testAnonAddModeRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/improve?type=water-food&mode=add');
        self::assertResponseRedirects('/login', 302);
    }

    public function testAddModeRendersTheWizardNotTheExplainer(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'render');

        $client->request('GET', '/improve?type=water-food&mode=add');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-improve-unbound]');
        self::assertSelectorExists('form[name="improve"]');
        // The injected required name field (water-food's registry set has none).
        self::assertSelectorExists('input[name="improve[details][name]"]');
        // Type-aware details still render (water-food's own fields).
        self::assertSelectorExists('select[name="improve[details][potable]"]');
    }

    public function testQualityRidesAndClimbsStayOutOfAddMode(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'excluded');

        $client->request('GET', '/improve?type=quality-rides&mode=add');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-improve-unbound]', 'K routes go through /propose-route');

        $client->request('GET', '/improve?type=climbs&mode=add');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-improve-unbound]', 'climbs keep the dedicated /add-climb flow');
    }

    public function testValidPostPersistsNewItemSubmission(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'post');

        $crawler = $client->request('GET', '/improve?type=water-food&mode=add');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Next →')->form([
            'improve[details][name]' => 'Fontaine du marché',
            'improve[details][type]' => 'Public fountain',
            'improve[details][potable]' => 'Yes (public supply)',
            'improve[lat]' => '50.426',
            'improve[lng]' => '6.027',
            'improve[place]' => 'Malmedy, Wallonia',
            'improve[mode]' => 'add',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.receipt .ref', 'SUB-');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Submission $submission */
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Fontaine du marché']);
        self::assertNotNull($submission);
        self::assertSame(SubmissionType::NewItem, $submission->getType());
        self::assertSame('C', $submission->getLetter());

        /** @var Item $item */
        $item = $em->getRepository(Item::class)->find($submission->getItemId());
        self::assertNotNull($item, 'NewItem intake creates the Submitted item row');
        self::assertSame(ItemState::Submitted, $item->getState());
        self::assertSame('Fontaine du marché', $item->getName());
        self::assertSame('Public fountain', $item->getAttributes()['type'] ?? null);
        self::assertArrayNotHasKey('name', $item->getAttributes(), 'name lives on Item::name, never in attributes');
    }

    public function testMissingNameIsRejectedAndNothingPersists(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'noname');

        $crawler = $client->request('GET', '/improve?type=water-food&mode=add');
        $form = $crawler->selectButton('Next →')->form([
            'improve[details][type]' => 'Public fountain',
            'improve[lat]' => '50.426',
            'improve[lng]' => '6.027',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('.receipt');
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Submission::class)->count([]));
    }

    public function testSegmentTypeCarriesDrawnEndpointsIntoAttributes(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'segment');

        $crawler = $client->request('GET', '/improve?type=road-surface&mode=add');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="improve[segment]"]');

        $form = $crawler->selectButton('Next →')->form([
            'improve[details][name]' => 'Gravel stretch · Hautes Fagnes',
            'improve[lat]' => '50.50',
            'improve[lng]' => '6.05',
            'improve[segment]' => '{"a":[6.04,50.49],"b":[6.06,50.51]}',
            'improve[mode]' => 'add',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Submission $submission */
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Gravel stretch · Hautes Fagnes']);
        self::assertNotNull($submission);
        self::assertSame('A', $submission->getLetter());
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->find($submission->getItemId());
        self::assertSame([6.04, 50.49], $item->getAttributes()['segment']['a'] ?? null);
        self::assertSame([6.06, 50.51], $item->getAttributes()['segment']['b'] ?? null);
    }

    public function testMalformedSegmentIsRejected(): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'badseg');

        $crawler = $client->request('GET', '/improve?type=road-surface&mode=add');
        $form = $crawler->selectButton('Next →')->form([
            'improve[details][name]' => 'Broken segment',
            'improve[lat]' => '50.50',
            'improve[lng]' => '6.05',
            'improve[segment]' => '{"a":[999,50.49],"b":"nope"}',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Submission::class)->count([]));
    }

    public function testASnappedRoadPathBecomesTheItemGeometry(): void
    {
        // The rider taps two points; the router returns the road between them.
        // The ITEM has to carry that road — a straight chord across the fields
        // between two bends is a visibly wrong line on the map.
        $client = static::createClient();
        $this->loginFreshUser($client, 'snapped');

        $line = [[6.04, 50.49], [6.045, 50.497], [6.052, 50.503], [6.06, 50.51]];
        $crawler = $client->request('GET', '/improve?type=road-surface&mode=add');
        $form = $crawler->selectButton('Next →')->form([
            'improve[details][name]' => 'Bending gravel track',
            'improve[lat]' => '50.49',
            'improve[lng]' => '6.04',
            'improve[segment]' => json_encode(['a' => [6.04, 50.49], 'b' => [6.06, 50.51], 'line' => $line]),
            'improve[mode]' => 'add',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Bending gravel track']);
        self::assertNotNull($submission);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->find($submission->getItemId());
        $geom = json_decode($em->getConnection()->fetchOne(
            'SELECT ST_AsGeoJSON(geom) FROM item WHERE id = ?', [$item->getId()]
        ), true);
        self::assertSame('LineString', $geom['type']);
        self::assertCount(4, $geom['coordinates'], 'the road path, not the two taps');
        self::assertSame($line, $item->getAttributes()['segment']['line']);
    }

    public function testWithoutARoadTheStraightChordIsStillAccepted(): void
    {
        // No route, no router, or an older client: the chord is a worse shape
        // but never a wrong one, so it must not be refused.
        $client = static::createClient();
        $this->loginFreshUser($client, 'chord');

        $crawler = $client->request('GET', '/improve?type=road-surface&mode=add');
        $form = $crawler->selectButton('Next →')->form([
            'improve[details][name]' => 'Unrouted stretch',
            'improve[lat]' => '50.49',
            'improve[lng]' => '6.04',
            'improve[segment]' => '{"a":[6.04,50.49],"b":[6.06,50.51]}',
            'improve[mode]' => 'add',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Unrouted stretch']);
        self::assertNotNull($submission);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->find($submission->getItemId());
        self::assertArrayNotHasKey('line', $item->getAttributes()['segment']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badLines(): iterable
    {
        // Every one of these would draw SOMETHING on the map under a name and a
        // surface the rider chose, which is why `line` cannot be a free channel.
        yield 'a line that ends nowhere near the pin' => ['{"a":[6.04,50.49],"b":[6.06,50.51],"line":[[6.04,50.49],[7.90,51.80]]}'];
        yield 'a single point' => ['{"a":[6.04,50.49],"b":[6.06,50.51],"line":[[6.04,50.49]]}'];
        yield 'a pair that is not a pair' => ['{"a":[6.04,50.49],"b":[6.06,50.51],"line":[[6.04,50.49],["x",50.51]]}'];
        yield 'coordinates off the planet' => ['{"a":[6.04,50.49],"b":[6.06,50.51],"line":[[6.04,50.49],[999,50.51]]}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badLines')]
    public function testAPathThatIsNotTheStretchIsRejected(string $segment): void
    {
        $client = static::createClient();
        $this->loginFreshUser($client, 'badline'.substr(md5($segment), 0, 6));

        $crawler = $client->request('GET', '/improve?type=road-surface&mode=add');
        $form = $crawler->selectButton('Next →')->form([
            'improve[details][name]' => 'Hand-crafted',
            'improve[lat]' => '50.49',
            'improve[lng]' => '6.04',
            'improve[segment]' => $segment,
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
    }
}
