<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
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

    /** Seed a real pending submission row so the queue renders a decision form. */
    private function seedSubmission(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('B')->setUserId(3)
            ->setTitle('Ajax queue item')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode('BE')
            ->setChanges([])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();
    }

    public function testAjaxDecisionReturnsJsonStubReceipt(): void
    {
        $client = static::createClient();
        $this->login($client, 'ajax-curator@example.com', ['ROLE_CURATOR'], true);
        $this->seedSubmission();

        // Grab the CSRF token from a rendered decision form (stateless, id "submit").
        $crawler = $client->request('GET', '/moderate');
        $token = (string) $crawler->filter('form.q-act-form input[name="moderation_decision[_token]"]')->first()->attr('value');

        $client->request(
            'POST',
            '/moderate/decide',
            ['moderation_decision' => ['submission_id' => '1', 'decision' => 'approve', '_token' => $token]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/moderate'],
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{persisted:bool,reference:string,kind:string,decision:string,submission_id:string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($data['persisted']);
        self::assertStringStartsWith('CC-', $data['reference']);
        self::assertSame('moderation_decision', $data['kind']);
        self::assertSame('approve', $data['decision']);
        self::assertSame('1', $data['submission_id']);
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

        $crawler = $client->request('GET', '/moderate');
        $token = (string) $crawler->filter('form.q-act-form input[name="moderation_decision[_token]"]')->first()->attr('value');
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
