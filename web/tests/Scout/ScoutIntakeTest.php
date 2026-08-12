<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Scout;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Entity\User;
use App\Scout\ScoutTag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Scout intake: one approved tag, one ordinary submission — and a track that
 * cannot be sent even by accident.
 *
 * @see docs/specs/Dated/2026-08-09-scout-cc-tagger-plan.md §1, tasks 1a/3/7
 */
final class ScoutIntakeTest extends WebTestCase
{
    private function login(KernelBrowser $client, string $tag): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail("scout-{$tag}@example.com");
        $user->setDisplayName('Scout rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }

    /** @param array<string, mixed> $payload */
    private function post(KernelBrowser $client, array $payload): void
    {
        $client->request('POST', '/scout/tags', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    public function testAnApprovedTagBecomesAnOrdinarySubmission(): void
    {
        $client = static::createClient();
        $this->login($client, 'ok');

        $this->post($client, [
            'tag' => 'resupply',
            'letter' => 'C',
            'lat' => 50.49,
            'lng' => 6.04,
            'observedAt' => '2026-08-10T09:15:00Z',
            'details' => ['name' => 'Fontaine de Sinsin'],
        ]);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Fontaine de Sinsin']);
        self::assertNotNull($submission, 'a Scout tag lands in the ordinary queue');
        self::assertSame('C', $submission->getLetter());

        /** @var Item $item */
        $item = $em->getRepository(Item::class)->find($submission->getItemId());
        // Provenance says HOW it arrived. It must not imply verification: the
        // server never saw the ride file (plan §3).
        self::assertSame(ItemSource::Scout, $item->getSource());
    }

    public function testTheObservationDateSurvivesIntoTheSubmission(): void
    {
        // The half of a Scout tag a form cannot supply: when the rider was
        // actually there. Kept in the raw payload the moderator reads until it
        // becomes a first-class attribute (plan task 6a).
        $client = static::createClient();
        $this->login($client, 'date');

        $this->post($client, [
            'tag' => 'scenery', 'letter' => 'I', 'lat' => 50.51, 'lng' => 6.06,
            'observedAt' => '2026-01-12T14:03:00Z',
            'details' => ['name' => 'Vue sur la vallée'],
        ]);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Vue sur la vallée']);
        self::assertNotNull($submission);
        self::assertSame('2026-01-12', $submission->getPayload()['observedAt'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function trackKeys(): iterable
    {
        yield 'a track' => ['track'];
        yield 'a polyline' => ['polyline'];
        yield 'raw records' => ['records'];
        yield 'the file itself' => ['fit'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('trackKeys')]
    public function testAPayloadCarryingATraceIsRefusedRatherThanIgnored(string $key): void
    {
        /* This is the promise, in code. `/scout` and `/privacy` tell riders the
           route is read in their own browser and never uploaded; an endpoint
           that quietly ignored an unexpected field would let a future client
           change start sending traces with nobody noticing for a year. */
        $client = static::createClient();
        $this->login($client, 'guard'.$key);

        $this->post($client, [
            'tag' => 'notice', 'letter' => 'F', 'lat' => 50.5, 'lng' => 6.05,
            'details' => ['name' => 'Bad corner'],
            $key => [[6.0, 50.4], [6.1, 50.5]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('track_not_accepted', (string) $client->getResponse()->getContent());
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Submission::class)->count(['title' => 'Bad corner']));
    }

    public function testATagCannotBecomeALetterItsTypeDoesNotAllow(): void
    {
        // `scenery` is a view, not a water point. The panel offers only the
        // letters ScoutTag::LETTERS allows; the server is what enforces it.
        $client = static::createClient();
        $this->login($client, 'letter');

        $this->post($client, [
            'tag' => 'scenery', 'letter' => 'C', 'lat' => 50.5, 'lng' => 6.05,
            'details' => ['name' => 'Not a fountain'],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnUnnamedTagIsRefused(): void
    {
        $client = static::createClient();
        $this->login($client, 'noname');

        $this->post($client, [
            'tag' => 'notice', 'letter' => 'F', 'lat' => 50.5, 'lng' => 6.05,
            'details' => ['name' => '   '],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnonymousRidersCannotSubmitTags(): void
    {
        $client = static::createClient();
        $this->post($client, [
            'tag' => 'notice', 'letter' => 'F', 'lat' => 50.5, 'lng' => 6.05,
            'details' => ['name' => 'Anonymous'],
        ]);

        self::assertResponseRedirects();
    }

    public function testEveryTagTypeResolvesToLettersThatExist(): void
    {
        foreach (ScoutTag::TYPES as $type) {
            $letters = ScoutTag::lettersFor($type);
            self::assertNotEmpty($letters, "{$type} must resolve somewhere");
            foreach ($letters as $letter) {
                self::assertNotNull(ScoutTag::itemTypeFor($letter), "{$type} → {$letter}");
            }
        }
    }

    public function testAPhotoTravelsWithTheTag(): void
    {
        // A camera without GPS cannot say where a picture was taken; the tag
        // can. An unknown media id must fail the whole submission rather than
        // land a half-attached contribution.
        $client = static::createClient();
        $this->login($client, 'photo');

        $this->post($client, [
            'tag' => 'scenery', 'letter' => 'I', 'lat' => 52.0, 'lng' => 4.0,
            'details' => ['name' => 'Waterkering'],
            'mediaIds' => '00000000-0000-4000-8000-000000000000',
        ]);

        self::assertResponseStatusCodeSame(422, 'an id that claims nothing is refused');
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Submission::class)->count(['title' => 'Waterkering']));
    }

    public function testASurfaceTagArrivesWithTheClassPickedOnTheDevice(): void
    {
        // Scout writes OSM's own word; the form speaks declarable labels.
        // Translating server-side saves the rider choosing the same thing twice.
        $client = static::createClient();
        $this->login($client, 'osmsurface');

        $this->post($client, [
            'tag' => 'surface', 'letter' => 'A', 'lat' => 52.0, 'lng' => 4.0,
            'details' => ['name' => 'Cobbled stretch'],
            'osmSurface' => 'cobblestone',
        ]);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Cobbled stretch']);
        self::assertNotNull($submission);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->find($submission->getItemId());
        self::assertSame('Sett — pavé', $item->getAttributes()['surface'] ?? null);
    }

    public function testAnUnknownOsmSurfaceIsDroppedRatherThanGuessed(): void
    {
        $client = static::createClient();
        $this->login($client, 'moondust');

        $this->post($client, [
            'tag' => 'surface', 'letter' => 'A', 'lat' => 52.0, 'lng' => 4.0,
            'details' => ['name' => 'Moon stretch'],
            'osmSurface' => 'moon_dust',
        ]);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Moon stretch']);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->find($submission->getItemId());
        self::assertArrayNotHasKey('surface', $item->getAttributes(), 'the form asks instead');
    }

    public function testASceneryTagAboutHistoryBecomesHistoryAndCulture(): void
    {
        // The device's submenu says which kind of view it was, and HISTORY is
        // letter J — not the scenic-views letter the tag type alone implies.
        // Offering only I made the rider re-file their own answer.
        self::assertSame(['J', 'I'], ScoutTag::lettersFor('scenery', 2));
        self::assertSame(['I'], ScoutTag::lettersFor('scenery', 4), 'a VIEW is a scenic view');
        self::assertSame(['D'], ScoutTag::lettersFor('resupply', 3), 'REPAIR is a bike service');
        self::assertSame(['C'], ScoutTag::lettersFor('resupply', 1), 'WATER is water & food');
    }

    public function testAClosureArrivesKnowingItsOwnDuration(): void
    {
        // CLOSED FOR? WEEKS is the whole reason the map can retire a closure by
        // itself; asking the rider to pick it again at home would be asking
        // them to remember what they already answered on the road.
        $client = static::createClient();
        $this->login($client, 'closure');

        $this->post($client, [
            'tag' => 'closure', 'letter' => 'F', 'detail' => 3,
            'lat' => 52.0, 'lng' => 4.0,
            'details' => ['name' => 'Dijk dicht'],
        ]);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Dijk dicht']);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->find($submission->getItemId());
        self::assertSame('Road closed', $item->getAttributes()['hazardType'] ?? null);
        self::assertSame('Weeks', $item->getAttributes()['closedFor'] ?? null);
    }

    public function testANoticeCarriesTheHazardTheRiderPicked(): void
    {
        $client = static::createClient();
        $this->login($client, 'potholes');

        $this->post($client, [
            'tag' => 'notice', 'letter' => 'F', 'detail' => 1,
            'lat' => 52.0, 'lng' => 4.0,
            'details' => ['name' => 'Gatenweg'],
        ]);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = $em->getRepository(Submission::class)->findOneBy(['title' => 'Gatenweg']);
        /** @var Item $item */
        $item = $em->getRepository(Item::class)->find($submission->getItemId());
        self::assertSame('Potholes', $item->getAttributes()['hazardType'] ?? null);
    }

    public function testASubmenuAnswerNeverFollowsATagToAnotherLetter(): void
    {
        // A rider may re-file a notice as something else. `hazardType` must not
        // ride along: the field does not exist on that letter, and the
        // submission would be refused for an answer they never gave.
        $client = static::createClient();
        $this->login($client, 'refiled');

        $this->post($client, [
            'tag' => 'notice', 'letter' => 'I', 'detail' => 1,
            'lat' => 52.0, 'lng' => 4.0,
            'details' => ['name' => 'Refiled as a view'],
        ]);

        self::assertResponseStatusCodeSame(400, 'notice does not resolve to I at all');
    }
}
