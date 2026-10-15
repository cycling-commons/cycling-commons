<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Links;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Links\WebsiteChecker;
use App\Links\WebsiteVerdict;
use App\Links\WebsiteVerdictStore;
use App\Service\ContributionStubInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The wizard's gate (docs/specs/website-liveness.md section 2): a `web`
 * value is checked at submit, from the stored verdict within its period,
 * else by one fetch; dead and parked refuse the field, unknown is accepted,
 * and a website the edit does not touch is not looked at.
 */
final class WebsiteGateAtSubmitTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<string> the addresses the mock web was asked for */
    private array $fetched = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->fetched[] = $url;
            if (str_contains($url, 'parked')) {
                return new MockResponse('<title>ConnectYourDomain Error | Wix.com</title>', ['http_code' => 404]);
            }
            if (str_contains($url, 'dead')) {
                return new MockResponse('', ['error' => 'Could not resolve host: dead.test']);
            }
            if (str_contains($url, 'slow')) {
                return new MockResponse('', ['error' => 'Idle timeout reached for "'.$url.'"']);
            }

            return new MockResponse('<title>Camping</title>', ['http_code' => 200]);
        });
        static::getContainer()->set(WebsiteChecker::class, new WebsiteChecker($http, new NullLogger()));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAParkedWebsiteIsRefusedAndItsVerdictKept(): void
    {
        $item = $this->item();

        try {
            $this->submit(['_item_id' => $item->getId(), 'details' => ['web' => 'https://parked.test/']]);
            self::fail('a parked website must be refused');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('contribute.error.website_parked', (string) $e->getViolations());
        }
        self::assertSame(['https://parked.test/'], $this->fetched);
        self::assertSame('parked', $this->db()->fetchOne("SELECT verdict FROM website_check WHERE host = 'parked.test'"));
    }

    public function testADeadWebsiteIsRefused(): void
    {
        $item = $this->item();

        try {
            $this->submit(['_item_id' => $item->getId(), 'details' => ['web' => 'https://dead.test/']]);
            self::fail('a dead website must be refused');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('contribute.error.website_dead', (string) $e->getViolations());
        }
    }

    public function testAWebsiteThatDidNotAnswerInTimeIsAccepted(): void
    {
        $item = $this->item();

        $receipt = $this->submit(['_item_id' => $item->getId(), 'details' => ['web' => 'https://slow.test/']]);

        self::assertNotNull($receipt->submissionId, 'an outage on our side must not cost a rider a correct address');
        self::assertSame('unknown', $this->db()->fetchOne("SELECT verdict FROM website_check WHERE host = 'slow.test'"));
    }

    public function testAVerdictWithinItsPeriodIsUsedWithoutAFetch(): void
    {
        $item = $this->item();
        static::getContainer()->get(WebsiteVerdictStore::class)
            ->record('parked.test', 'https://parked.test/', WebsiteVerdict::of(200, '<title>Camping</title>'), new \DateTimeImmutable());

        $receipt = $this->submit(['_item_id' => $item->getId(), 'details' => ['web' => 'https://parked.test/rooms']]);

        self::assertNotNull($receipt->submissionId);
        self::assertSame([], $this->fetched, 'the stored verdict answers');
    }

    public function testAWebsiteTheEditDoesNotTouchIsNotLookedAt(): void
    {
        $item = $this->item(['web' => 'https://parked.test/']);

        $receipt = $this->submit(['_item_id' => $item->getId(), 'details' => ['web' => 'https://parked.test/', 'condition' => 'Out of order']]);

        self::assertNotNull($receipt->submissionId, 'the form posts the prefilled address back unchanged');
        self::assertSame([], $this->fetched);
    }

    // --- helpers ----------------------------------------------------------

    /** @param array<string, mixed> $payload */
    private function submit(array $payload): \App\Service\ContributionReceipt
    {
        return static::getContainer()->get(ContributionStubInterface::class)->submit('improve', $payload, $this->rider());
    }

    /** @param array<string, mixed> $attributes */
    private function item(array $attributes = []): Item
    {
        $item = (new Item())->setLetter('B')->setName('Fontaine')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Verified)->setSource(ItemSource::User)->setSourceRef('test')
            ->setAttributes($attributes);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function rider(): User
    {
        $user = (new User())->setEmail('web-gate-'.uniqid('', true).'@test.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function db(): Connection
    {
        return $this->em->getConnection();
    }
}
