<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Discussing a photo rides the existing submission thread, and only that
 * (docs/specs/photo-uploads.md §5b): a curator's ordinary message may carry a
 * reference to one of the submission's own photos, and a reference to anything
 * else is simply dropped.
 */
final class MediaMessageTest extends WebTestCase
{
    private const string PASSWORD = 'securepass12345!';

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $container = static::getContainer();
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Someone');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if (\in_array('ROLE_CURATOR', $roles, true)) {
            // An elevated account must be FULLY enrolled before it may reach
            // /moderate, or the 2FA policy redirects it to setup.
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** loginUser(), not the form: a fully-enrolled curator would otherwise have to walk the 2FA interstitial. */
    private function login(KernelBrowser $client, User $user): void
    {
        $client->loginUser($user);
    }

    /** @return array{Submission, MediaUpload} */
    private function seed(User $rider): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $submission = (new Submission())->setType(SubmissionType::Edit)->setLetter('A')
            ->setUserId((int) $rider->getId())->setTitle('Fontaine')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $em->persist($submission);
        $em->flush();

        $consent = new ConsentRecord(Uuid::v4(), (int) $rider->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $rider->getId(), $consent->getId(), 'EU', 1200, 900, 4242, shard: 'EU-01', bucket: 'test-bucket-eu-01');
        $upload->claim((int) $submission->getId());
        $em->persist($upload);
        $em->flush();

        return [$submission, $upload];
    }

    /**
     * `moderate-message` is a session-bound CSRF token id (not in csrf.yaml's
     * stateless_token_ids), so a real token has to be read off a rendered form
     * the way a browser would — the idiom TrashTest and CuratorMessageTest
     * already use.
     */
    private function messageToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/moderate');

        return (string) $crawler->filter('form[action$="/moderate/message"] input[name="_token"]')->first()->attr('value');
    }

    public function testACuratorCanReferenceAPhotoOfThatSubmission(): void
    {
        $client = static::createClient();
        $rider = $this->user('msg-rider@example.test');
        [$submission, $upload] = $this->seed($rider);
        $curator = $this->user('msg-curator@example.test', ['ROLE_CURATOR']);
        $this->login($client, $curator);

        $client->request('POST', '/moderate/message', [
            '_token' => $this->messageToken($client),
            'channel' => 'submission',
            'id' => (string) $submission->getId(),
            'mediaId' => $upload->getId()->toRfc4122(),
            'body' => 'Is this your own shot?',
        ]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $messages = $em->getRepository(UserMessage::class)->findBy(['userId' => (int) $rider->getId()]);
        self::assertCount(1, $messages);
        self::assertSame($upload->getId()->toRfc4122(), $messages[0]->getMediaId()?->toRfc4122());
    }

    public function testAReferenceToSomebodyElsesPhotoIsDroppedNotHonoured(): void
    {
        $client = static::createClient();
        $rider = $this->user('msg-rider2@example.test');
        [$submission] = $this->seed($rider);
        $other = $this->user('msg-other@example.test');
        [, $foreignUpload] = $this->seed($other);
        $curator = $this->user('msg-curator2@example.test', ['ROLE_CURATOR']);
        $this->login($client, $curator);

        $client->request('POST', '/moderate/message', [
            '_token' => $this->messageToken($client),
            'channel' => 'submission',
            'id' => (string) $submission->getId(),
            'mediaId' => $foreignUpload->getId()->toRfc4122(),
            'body' => 'About this photo…',
        ]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $messages = $em->getRepository(UserMessage::class)->findBy(['userId' => (int) $rider->getId()]);
        self::assertCount(1, $messages, 'the message still sends');
        self::assertNull($messages[0]->getMediaId(), 'the bogus reference is dropped');
    }

    public function testAGarbageReferenceIsAlsoJustDropped(): void
    {
        $client = static::createClient();
        $rider = $this->user('msg-rider4@example.test');
        [$submission] = $this->seed($rider);
        $curator = $this->user('msg-curator4@example.test', ['ROLE_CURATOR']);
        $this->login($client, $curator);

        $client->request('POST', '/moderate/message', [
            '_token' => $this->messageToken($client),
            'channel' => 'submission',
            'id' => (string) $submission->getId(),
            'mediaId' => 'not-a-uuid-at-all',
            'body' => 'Still worth sending.',
        ]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $messages = $em->getRepository(UserMessage::class)->findBy(['userId' => (int) $rider->getId()]);
        self::assertCount(1, $messages);
        self::assertNull($messages[0]->getMediaId());
    }

    public function testTheRiderSeesTheReferencedThumbInTheirThread(): void
    {
        $client = static::createClient();
        $rider = $this->user('msg-rider3@example.test');
        [$submission, $upload] = $this->seed($rider);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new UserMessage(
            (int) $rider->getId(), UserMessageKind::CuratorMessage, 'curator', 1,
            'submission', (int) $submission->getId(), 'SUB-'.$submission->getId(),
            null, null, 'Is this your own shot?', $upload->getId(),
        ));
        $em->flush();

        $this->login($client, $rider);
        $crawler = $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('.msg-photo img')->count(),
            'the referenced photo renders inline in the thread',
        );
    }
}
