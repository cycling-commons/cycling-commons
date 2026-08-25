<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ModerateDecideAjaxTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function login(KernelBrowser $client, string $email, array $roles, bool $curator): void
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Ajax User');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        if ($curator) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }

    /**
     * Seed a real pending submission row so the queue renders a decision
     * form. The submitter is a genuinely persisted user: a successful
     * decide() now also writes it a message, and user_message.user_id
     * carries a real DB FK to users(id).
     */
    private function seedSubmission(): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $submitter = new User();
        $submitter->setEmail('ajax-submitter-'.uniqid('', true).'@example.com');
        $submitter->setDisplayName('Ajax Submitter');
        $submitter->setPassword('x');
        $em->persist($submitter);
        $em->flush();

        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('B')->setUserId((int) $submitter->getId())
            ->setTitle('Ajax queue item')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode('BE')
            ->setChanges([])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();

        return $sub;
    }

    public function testAjaxDecisionReturnsJsonReceipt(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-curator@example.com', ['ROLE_CURATOR'], true);
        $sub = $this->seedSubmission();

        // Grab the CSRF token (stateless, id "submit") from window.CC_MOD_TOKEN
        // on the map page — the decision form now lives in the map drawer,
        // not on /moderate.
        $client->request('GET', '/map');
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', $html, $m));
        $token = $m[1];

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => (string) $sub->getId(), 'decision' => 'approve', '_token' => $token]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{persisted:bool,reference:string,kind:string,decision:string,submission_id:int} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($data['persisted']);
        self::assertSame('SUB-'.$sub->getId(), $data['reference']);
        self::assertSame('moderation_decision', $data['kind']);
        self::assertSame('approve', $data['decision']);
        self::assertSame($sub->getId(), $data['submission_id']);
    }

    /**
     * An invalid decision must return JSON 422 {"error":"invalid_decision"}.
     *
     * Failure mode exercised: form VALIDATION (not CSRF) — a real extracted
     * _token and same-origin Referer are supplied, but 'destroy' is not in the
     * ChoiceType's choice list (approve/reject/needs_info), so the form is
     * submitted-but-invalid. Only Accept: application/json is sent (no
     * X-Requested-With), covering the Accept-header trigger of $wantsJson.
     */
    public function testAjaxInvalidDecisionReturnsJsonError(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-curator-invalid@example.com', ['ROLE_CURATOR'], true);
        $this->seedSubmission();

        $client->request('GET', '/map');
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', $html, $m));
        $token = $m[1];
        self::assertNotSame('', $token);

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => '1', 'decision' => 'destroy', '_token' => $token]],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{error:string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['error' => 'invalid_decision'], $data);
    }

    /**
     * "Needs info" with an empty note is refused with its own error code.
     *
     * Not `invalid_decision` and not `undecidable_submission`: the submission
     * is perfectly decidable and the decision is a real one — the QUESTION is
     * missing. The drawer tells the two apart to decide whether to put the
     * cursor in the note box or report a failure, and the rider must never be
     * sent "a curator needs more information" with nothing to answer.
     */
    public function testAjaxNeedsInfoWithoutANoteIsRefused(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-curator-needsinfo@example.com', ['ROLE_CURATOR'], true);
        $sub = $this->seedSubmission();

        $client->request('GET', '/map');
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', $html, $m));
        $token = $m[1];

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => (string) $sub->getId(), 'decision' => 'needs_info', 'note' => '   ', '_token' => $token]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseStatusCodeSame(422);
        /** @var array{error:string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['error' => 'needs_info_note_required'], $data);

        // And the submission is untouched — still pending, still in the queue.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $fresh = $em->find(Submission::class, $sub->getId());
        self::assertNotNull($fresh);
        self::assertSame(SubmissionStatus::Pending, $fresh->getStatus());
    }

    public function testAjaxNeedsInfoWithAQuestionIsRecorded(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-curator-asks@example.com', ['ROLE_CURATOR'], true);
        $sub = $this->seedSubmission();

        $client->request('GET', '/map');
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', $html, $m));

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => (string) $sub->getId(), 'decision' => 'needs_info', 'note' => 'Which side of the path is the tap on?', '_token' => $m[1]]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseIsSuccessful();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $fresh = $em->find(Submission::class, $sub->getId());
        self::assertNotNull($fresh);
        self::assertSame(SubmissionStatus::NeedsInfo, $fresh->getStatus());
    }

    /**
     * An approval carries the approved item back, as the catalog's own feature.
     *
     * The drawer removes the pending pin the moment a decision lands; without
     * this the place it just approved appeared nowhere until the curator
     * reloaded, because the map's pools are built once from the boot fetch.
     */
    public function testAnApprovalCarriesTheItemBackForTheMap(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-curator-feature@example.com', ['ROLE_CURATOR'], true);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item()->answerOsm(null)   /* pre-gate fixture: the curator answered "not in OSM" (catalog-data-model.md §5b) */)->setLetter('C')->setName('Fontaine du Décide')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Submitted)->setSource(ItemSource::User)->setSourceRef('sub:ajax-feature')
            ->setAttributes(['potable' => 'yes']);
        $em->persist($item);
        $em->flush();

        $sub = $this->seedSubmission();
        $sub->setLetter('C')->setItemId($item->getId());
        $em->flush();

        $client->request('GET', '/map');
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', (string) $client->getResponse()->getContent(), $m));

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => (string) $sub->getId(), 'decision' => 'approve', '_token' => $m[1]]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseIsSuccessful();
        /** @var array{item: ?array{letter: string, feature: array{type: string, properties: array<string, mixed>, geometry: array<string, mixed>}}} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertNotNull($data['item'], 'an approved C item must come back for the map');
        self::assertSame('C', $data['item']['letter']);
        self::assertSame('Feature', $data['item']['feature']['type']);
        self::assertSame($item->getId(), $data['item']['feature']['properties']['id']);
        self::assertSame('Fontaine du Décide', $data['item']['feature']['properties']['n']);
        self::assertSame('yes', $data['item']['feature']['properties']['potable']);
        // Approved is not confirmed: it joins the map as a community pin, and
        // only a rider's confirmation flips `v`.
        self::assertArrayNotHasKey('v', $data['item']['feature']['properties']);
        self::assertSame([5.86, 50.47], $data['item']['feature']['geometry']['coordinates']);
    }

    public function testARejectionCarriesNoItem(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-curator-reject@example.com', ['ROLE_CURATOR'], true);
        $sub = $this->seedSubmission();

        $client->request('GET', '/map');
        self::assertSame(1, preg_match('/CC_MOD_TOKEN\s*=\s*"([^"]+)"/', (string) $client->getResponse()->getContent(), $m));

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => (string) $sub->getId(), 'decision' => 'reject', 'note' => 'duplicate', '_token' => $m[1]]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseIsSuccessful();
        /** @var array{item: mixed} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNull($data['item']);
    }

    public function testAjaxDecisionForbiddenForPlainRider(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-rider@example.com', [], false);

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => '1', 'decision' => 'approve']],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseStatusCodeSame(403);
    }
}
