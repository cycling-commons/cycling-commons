<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\PhotoAltSuggestion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A rider who did not upload a photograph can still say what it shows.
 *
 * A description belongs to its uploader, and the direct endpoint takes one only
 * from them. Before this, everybody else had nowhere to go and a wrong or
 * missing description stayed until whoever took the picture came back (owner,
 * 2026-08-30). Now it becomes a suggestion in the same queue as every other
 * edit on that place: no new moderation mechanic, which is the house rule.
 *
 * @see docs/specs/photo-uploads.md §5e
 */
final class PhotoAltSuggestionTest extends WebTestCase
{
    private function tokenFor(KernelBrowser $client): string
    {
        $client->request('GET', '/media/token');
        self::assertResponseIsSuccessful();

        /** @var array{token?: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return (string) ($data['token'] ?? '');
    }

    /** @return array{KernelBrowser, EntityManagerInterface, User, User, Item, MediaUpload} */
    private function scene(string $slug): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = (new User())->setEmail($slug.'-owner@example.test');
        $owner->setPassword('x');
        $owner->setDisplayName('Photo Owner');
        $em->persist($owner);

        $stranger = (new User())->setEmail($slug.'-other@example.test');
        $stranger->setPassword('x');
        $stranger->setDisplayName('Passing Rider');
        $em->persist($stranger);

        $item = (new Item())->setLetter('A')->setName('Fontaine du regard')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('alt-suggest-'.$slug)
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $em->persist($item);
        $em->flush();

        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $upload->approve($item->getId());
        $em->flush();

        $entry = static::getContainer()->get(MediaDecisionService::class)->describe($upload);
        $item->setAttributes(['photos' => [$entry]]);
        $em->flush();

        return [$client, $em, $owner, $stranger, $item, $upload];
    }

    public function testAStrangerSuggestionBecomesASubmissionAndChangesNothingYet(): void
    {
        [$client, $em, , $stranger, $item, $upload] = $this->scene('files');

        $client->loginUser($stranger);
        $client->request('POST', '/media/photos/'.$upload->getId()->toRfc4122().'/alt-suggestion', [
            '_token' => $this->tokenFor($client),
            'alt' => 'A stone basin under a plane tree.',
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $fresh = $em->find(MediaUpload::class, $upload->getId());
        self::assertInstanceOf(MediaUpload::class, $fresh);
        self::assertNull($fresh->getAltText(), 'nothing is live until a curator says so');

        /** @var list<Submission> $subs */
        $subs = $em->getRepository(Submission::class)->findBy(['itemId' => $item->getId()]);
        self::assertCount(1, $subs);
        $changes = $subs[0]->getChanges();
        $field = PhotoAltSuggestion::field($upload->getId());
        self::assertArrayHasKey($field, $changes);
        self::assertSame('A stone basin under a plane tree.', $changes[$field]['now']);
    }

    /** The owner has a route that works at once; sending them to a queue would be worse. */
    public function testTheOwnerIsRefusedBySuggestionRoute(): void
    {
        [$client, , $owner, , , $upload] = $this->scene('owner');

        $client->loginUser($owner);
        $client->request('POST', '/media/photos/'.$upload->getId()->toRfc4122().'/alt-suggestion', [
            '_token' => $this->tokenFor($client),
            'alt' => 'Mine, so this should go the other way.',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /** ...and the direct route still refuses everybody else. */
    public function testAStrangerIsRefusedByTheDirectRoute(): void
    {
        [$client, , , $stranger, , $upload] = $this->scene('direct');

        $client->loginUser($stranger);
        $client->request('POST', '/media/photos/'.$upload->getId()->toRfc4122().'/alt', [
            '_token' => $this->tokenFor($client),
            'alt' => 'Not mine to write.',
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
