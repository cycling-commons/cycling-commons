<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\DeepL\DeepLClient;
use App\Translation\Exception\DeepLAuthenticationException;
use App\Translation\Exception\DeepLNetworkException;
use App\Translation\Exception\DeepLQuotaExceededException;
use App\Translation\Exception\DeepLRateLimitedException;
use App\Translation\Exception\DeepLRequestException;
use App\Translation\Exception\InvalidLocaleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The DeepL drafting client and the dev-only gate in front of it
 * (translations.md §7.1, §7.2, §7.5).
 *
 * No test here makes a real network call: every request goes through
 * MockHttpClient, either a single canned MockResponse or a callback that
 * inspects the request and answers from a queue.
 */
final class DeepLClientTest extends TestCase
{
    private const string FREE_HOST = 'https://api-free.deepl.com';
    private const string PAID_HOST = 'https://api.deepl.com';

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses, string $key = 'test-key'): DeepLClient
    {
        return new DeepLClient(new MockHttpClient($responses), $key);
    }

    // --- Endpoint selection (translations.md §7.1) ---------------------

    public function testAFreeKeyCallsTheFreeHost(): void
    {
        $requestedUrls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $url;

            return new MockResponse(json_encode(['translations' => [['text' => 'Hallo']]], \JSON_THROW_ON_ERROR));
        });

        (new DeepLClient($http, 'abc123:fx'))->translate('Hello', ['nl']);

        self::assertCount(1, $requestedUrls);
        self::assertStringStartsWith(self::FREE_HOST.'/v2/translate', $requestedUrls[0]);
    }

    public function testAPaidKeyCallsThePaidHost(): void
    {
        $requestedUrls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $url;

            return new MockResponse(json_encode(['translations' => [['text' => 'Hallo']]], \JSON_THROW_ON_ERROR));
        });

        (new DeepLClient($http, 'abc123'))->translate('Hello', ['nl']);

        self::assertCount(1, $requestedUrls);
        self::assertStringStartsWith(self::PAID_HOST.'/v2/translate', $requestedUrls[0]);
    }

    // --- Empty target list (translations.md §7.2 handoff, gap 2) ---------

    public function testAnEmptyTargetListReturnsEmptyWithoutAnyHttpCall(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            self::fail('DeepL must not be called when there is nothing to translate.');
        });
        $client = new DeepLClient($http, 'a-key');

        self::assertSame([], $client->translate('Hello', []));
    }

    public function testAMissingKeyIsRefusedEvenWithAnEmptyTargetList(): void
    {
        // The key check still runs before anything else, so an empty list
        // does not silently mask a missing key.
        $client = $this->client([], '');

        $this->expectException(DeepLAuthenticationException::class);
        $client->translate('Hello', []);
    }

    // --- A successful call with several targets -------------------------

    public function testASuccessfulCallReturnsEveryLocaleAndSendsTheEnglishAndTheRightTargets(): void
    {
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $seen */
        $seen = [];
        $texts = ['fr' => 'Bonjour', 'nl' => 'Hallo', 'de' => 'Hallo', 'es' => 'Hola'];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen, $texts): MockResponse {
            $body = json_decode((string) $options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $seen[] = [$method, $url, $body];
            $target = strtolower((string) $body['target_lang']);

            return new MockResponse(json_encode(['translations' => [['text' => $texts[$target]]]], \JSON_THROW_ON_ERROR));
        });
        $client = new DeepLClient($http, 'a-key');

        $result = $client->translate('Hello', ['fr', 'nl', 'de', 'es']);

        self::assertSame($texts, $result);
        self::assertCount(4, $seen);
        foreach ($seen as [$method, $url, $body]) {
            self::assertSame('POST', $method);
            self::assertStringStartsWith(self::PAID_HOST.'/v2/translate', $url);
            self::assertSame(['Hello'], $body['text']);
            self::assertSame('EN', $body['source_lang']);
            self::assertContains($body['target_lang'], ['FR', 'NL', 'DE', 'ES']);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function tagHandlingProvider(): iterable
    {
        yield 'a plain sentence gets no hint' => ['Read the rules first.', false];
        yield 'an inline tag does' => ['Read the <b>rules</b> first.', true];
        yield 'a closing tag alone does' => ['Rules</b> first.', true];
        yield 'a link does' => ['Read the <a href="/rules">rules</a>.', true];
        yield 'a bare comparison does not' => ['Gradients < 5% are flat.', false];
    }

    /**
     * Catalogue values carry inline markup, and DeepL's default plain-text
     * mode is free to reformat, move or translate what is inside a tag. The
     * hint is sent ONLY when there is markup: in html mode DeepL also
     * interprets entities, which is the wrong reading of an ordinary
     * sentence that happens to contain an ampersand.
     */
    #[DataProvider('tagHandlingProvider')]
    public function testTagHandlingIsSentOnlyWhenTheEnglishCarriesMarkup(string $english, bool $expected): void
    {
        $body = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode((string) $options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode(['translations' => [['text' => 'Hallo']]], \JSON_THROW_ON_ERROR));
        });

        (new DeepLClient($http, 'a-key'))->translate($english, ['nl']);

        self::assertSame($expected, \array_key_exists('tag_handling', $body));
        if ($expected) {
            self::assertSame('html', $body['tag_handling']);
        }
    }

    public function testTheAuthorizationHeaderCarriesTheConfiguredKey(): void
    {
        $capturedHeaders = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['normalized_headers'] ?? [];

            return new MockResponse(json_encode(['translations' => [['text' => 'Hallo']]], \JSON_THROW_ON_ERROR));
        });
        $client = new DeepLClient($http, 'my-secret-key:fx');

        $client->translate('Hello', ['nl']);

        self::assertNotEmpty($capturedHeaders['authorization'] ?? []);
        self::assertStringContainsString('DeepL-Auth-Key my-secret-key:fx', $capturedHeaders['authorization'][0]);
    }

    // --- Refused target locales (translations.md §7.2) -------------------

    public function testEnglishIsRefusedAsATargetBecauseItIsAlwaysTheSource(): void
    {
        $client = $this->client([]);

        $this->expectException(InvalidLocaleException::class);
        $client->translate('Hello', ['en']);
    }

    public function testAnUnknownLocaleIsRefused(): void
    {
        $client = $this->client([]);

        $this->expectException(InvalidLocaleException::class);
        $client->translate('Hello', ['it']);
    }

    public function testARefusedLocaleAmongValidOnesStillRefusesTheWholeCallBeforeAnyRequest(): void
    {
        // Validation happens up front so an invalid target never spends quota.
        $client = $this->client([]);

        $this->expectException(InvalidLocaleException::class);
        $client->translate('Hello', ['fr', 'en']);
    }

    // --- Failure modes (translations.md §7.1, "failures are surfaced") ---

    public function testNoKeyConfiguredRaisesAnActionableAuthenticationError(): void
    {
        $client = $this->client([], '');

        try {
            $client->translate('Hello', ['nl']);
            self::fail('expected DeepLAuthenticationException');
        } catch (DeepLAuthenticationException $e) {
            self::assertStringContainsString('DEEPL_API_KEY', $e->getMessage());
            self::assertStringContainsString('.env.local', $e->getMessage());
        }
    }

    public function testARejectedKeyRaisesAnActionableAuthenticationError(): void
    {
        $client = $this->client([new MockResponse('{"message":"Wrong endpoint. Use https://api.deepl.com"}', ['http_code' => 403])]);

        try {
            $client->translate('Hello', ['nl']);
            self::fail('expected DeepLAuthenticationException');
        } catch (DeepLAuthenticationException $e) {
            self::assertStringContainsString('403', $e->getMessage());
            self::assertStringContainsString('DEEPL_API_KEY', $e->getMessage());
        }
    }

    public function testAnExhaustedQuotaRaisesAnActionableError(): void
    {
        $client = $this->client([new MockResponse('{"message":"Quota exceeded"}', ['http_code' => 456])]);

        try {
            $client->translate('Hello', ['nl']);
            self::fail('expected DeepLQuotaExceededException');
        } catch (DeepLQuotaExceededException $e) {
            self::assertStringContainsString('quota', strtolower($e->getMessage()));
        }
    }

    public function testRateLimitingRaisesAnActionableError(): void
    {
        $client = $this->client([new MockResponse('{"message":"Too many requests"}', ['http_code' => 429])]);

        try {
            $client->translate('Hello', ['nl']);
            self::fail('expected DeepLRateLimitedException');
        } catch (DeepLRateLimitedException $e) {
            self::assertStringContainsString('429', $e->getMessage());
        }
    }

    public function testANetworkFailureRaisesAnActionableError(): void
    {
        // MockResponse's 'error' option is what actually surfaces as a
        // Symfony TransportExceptionInterface, the same as a real dropped
        // connection would; a callback that merely throws \RuntimeException
        // is not a transport error and would not exercise this catch clause.
        $client = $this->client([new MockResponse('', ['error' => 'connection refused'])]);

        try {
            $client->translate('Hello', ['nl']);
            self::fail('expected DeepLNetworkException');
        } catch (DeepLNetworkException $e) {
            self::assertStringContainsString('connection refused', $e->getMessage());
        }
    }

    public function testATimeoutRaisesTheSameNetworkError(): void
    {
        $client = $this->client([new MockResponse('', ['error' => 'timed out'])]);

        $this->expectException(DeepLNetworkException::class);
        $client->translate('Hello', ['nl']);
    }

    public function testAnUnexpectedStatusCodeRaisesAGenericRequestError(): void
    {
        $client = $this->client([new MockResponse('{"message":"server error"}', ['http_code' => 503])]);

        $this->expectException(DeepLRequestException::class);
        $client->translate('Hello', ['nl']);
    }

    public function testAResponseMissingTheTranslatedTextRaisesARequestError(): void
    {
        $client = $this->client([new MockResponse(json_encode(['translations' => []], \JSON_THROW_ON_ERROR))]);

        $this->expectException(DeepLRequestException::class);
        $client->translate('Hello', ['nl']);
    }

    public function testUnreadableResponseBodyRaisesARequestError(): void
    {
        $client = $this->client([new MockResponse('not json at all', ['http_code' => 200])]);

        $this->expectException(DeepLRequestException::class);
        $client->translate('Hello', ['nl']);
    }
}
