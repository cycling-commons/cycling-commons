<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation\DeepL;

use App\Translation\Exception\DeepLAuthenticationException;
use App\Translation\Exception\DeepLNetworkException;
use App\Translation\Exception\DeepLQuotaExceededException;
use App\Translation\Exception\DeepLRateLimitedException;
use App\Translation\Exception\DeepLRequestException;
use App\Translation\Exception\InvalidLocaleException;
use App\Translation\TranslationLimits;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Drafts a catalogue string through DeepL, for the dev-only tool
 * {@see DeepLAvailability} gates.
 *
 * Source is always English; targets are only the four rider locales
 * (translations.md §7.2). Only the one catalogue string for the row being
 * drafted is ever sent, key and English together, never anything else
 * about the app or a person (translations.md §7.2, §7.5).
 *
 * **Endpoint selection.** DeepL runs two API hosts, and a key's own shape
 * says which one answers it: a free-tier key ends with the literal suffix
 * `:fx`, and calls `api-free.deepl.com`; any other key calls `api.deepl.com`.
 * The developer configures nothing beyond the key itself
 * (translations.md §7.1).
 *
 * **Failures are surfaced, never swallowed.** The developer holds the key
 * and is the only person who can act on a failure, so each cause throws a
 * distinct, named exception with a message that says what to do next,
 * rather than a single generic failure or (worse) a silent empty result.
 *
 * @see docs/specs/translations.md §7.1, §7.2, §7.5
 *
 * @api
 */
final class DeepLClient
{
    private const string FREE_HOST = 'https://api-free.deepl.com';
    private const string PAID_HOST = 'https://api.deepl.com';
    private const string TRANSLATE_PATH = '/v2/translate';

    /** The suffix DeepL appends to every free-tier key. */
    private const string FREE_KEY_SUFFIX = ':fx';

    /**
     * A developer waiting on a hung request with no feedback is a bug in
     * itself. Ten seconds is generous for a single short catalogue string
     * (well past DeepL's typical latency) while still failing fast enough
     * that a stalled connection reads as "something is wrong", not as a
     * frozen button.
     */
    private const int TIMEOUT_SECONDS = 10;

    /**
     * Does this English string carry markup? The same test
     * `TranslateController::edit()` uses to decide whether to show the
     * "keep the same tags" note, so the client and the page agree about
     * what counts as a marked-up string.
     */
    private const string MARKUP_PATTERN = '/<[a-zA-Z\/]/';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey = '',
    ) {
    }

    /**
     * @param list<string> $targetLocales only `fr`, `nl`, `de`, `es`; never `en` (translations.md §7.2)
     *
     * @return array<string, string> locale => translated text
     *
     * @throws InvalidLocaleException       a target is not one of the four rider locales, `en` included
     * @throws DeepLAuthenticationException no key is configured, or DeepL rejected it
     * @throws DeepLQuotaExceededException  the key's character quota is exhausted
     * @throws DeepLRateLimitedException    DeepL asked the client to slow down
     * @throws DeepLNetworkException        DeepL could not be reached at all
     * @throws DeepLRequestException        DeepL answered with something this client cannot use
     */
    public function translate(string $english, array $targetLocales): array
    {
        // Validate every target before the first HTTP call: a request half
        // sent is worse than one never started, and a caller passing `en`
        // by mistake should never spend quota finding that out.
        foreach ($targetLocales as $locale) {
            if (!TranslationLimits::isTranslatableLocale($locale)) {
                throw new InvalidLocaleException(sprintf('Refusing to ask DeepL for locale "%s": only %s are translatable targets (translations.md §7.2). English is the source, never a target.', $locale, implode(', ', TranslationLimits::LOCALES)));
            }
        }

        if ('' === trim($this->apiKey)) {
            throw new DeepLAuthenticationException('No DEEPL_API_KEY is configured. Set your own key in your gitignored web/.env.local (translations.md §7.1); an empty key means the feature is off.');
        }

        $host = str_ends_with($this->apiKey, self::FREE_KEY_SUFFIX) ? self::FREE_HOST : self::PAID_HOST;

        // Issue every request before reading any of them. Symfony's
        // HttpClientInterface is asynchronous by design: request() only
        // opens the connection and returns immediately, and the actual
        // wait happens lazily, the first time something reads the
        // response (getStatusCode(), toArray(), ...). A loop that both
        // issues and reads inside the same iteration therefore waits for
        // each locale in turn, so four targets at TIMEOUT_SECONDS each pay
        // up to 4x that on a stalled connection. Firing all four here and
        // only then reading them below overlaps the four waits instead of
        // serialising them, so the worst case stays close to one timeout
        // no matter how many locales are asked for.
        //
        // All or nothing: the first target whose response fails to read
        // throws and aborts the loop below, so translate() either returns
        // every requested locale or throws one exception, never a partial
        // array. This keeps the return type's contract (every requested
        // locale present) and keeps a caller's error handling to one
        // catch block instead of a partial-results shape it would have to
        // reconcile locale by locale. The already-issued, now-abandoned
        // requests for the remaining locales are simply left unread; nothing
        // in this class further depends on them completing.
        // Tell DeepL when it is looking at markup. Catalogue values carry
        // inline tags (`<b>`, `<code>`, `<a href>`); handed to the default
        // plain-text mode, DeepL is free to reformat, move or translate
        // what is inside them, and the result reaches a source file with
        // only the acceptance check between it and a reader. `html` tag
        // handling makes it treat tags as structure to carry across rather
        // than words to translate. Sent ONLY when there is markup: in that
        // mode DeepL also interprets entities, which is the wrong reading
        // of a plain sentence that happens to contain an ampersand.
        //
        // This does NOT cover `%name%` placeholders, which DeepL has no way
        // to recognise. Those are caught after the fact, by the placeholder
        // parity check every write on this path runs
        // (TranslateController::assertDevSubmitAcceptable()).
        $htmlTags = 1 === preg_match(self::MARKUP_PATTERN, $english);

        $responses = [];
        foreach ($targetLocales as $locale) {
            $responses[$locale] = $this->startRequest($host, $english, $locale, $htmlTags);
        }

        $out = [];
        foreach ($responses as $locale => $response) {
            $out[$locale] = $this->readTranslation($response, $locale);
        }

        return $out;
    }

    /**
     * DeepL's `/v2/translate` accepts exactly one `target_lang` per call,
     * so drafting all four locales for one key is four requests, each
     * carrying only that key's English string, plus the tag-handling hint
     * when that string carries markup. Returns immediately: the
     * HTTP client is asynchronous, so this only opens the request, it does
     * not wait for it (see the comment in translate() above).
     */
    private function startRequest(string $host, string $english, string $locale, bool $htmlTags): ResponseInterface
    {
        $payload = [
            'text' => [$english],
            'source_lang' => 'EN',
            'target_lang' => strtoupper($locale),
        ];
        if ($htmlTags) {
            $payload['tag_handling'] = 'html';
        }

        return $this->http->request('POST', $host.self::TRANSLATE_PATH, [
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key '.$this->apiKey,
            ],
            'json' => $payload,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);
    }

    /**
     * Waits for one already-issued request and turns it into the
     * translated string, or one of this client's named exceptions.
     */
    private function readTranslation(ResponseInterface $response, string $locale): string
    {
        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new DeepLNetworkException(sprintf('Could not reach DeepL: %s. Check your network connection and try again.', $e->getMessage()), previous: $e);
        }

        if (403 === $statusCode) {
            throw new DeepLAuthenticationException('DeepL rejected the configured DEEPL_API_KEY (HTTP 403). Check the key in your web/.env.local, or that it has not been revoked in your DeepL account (translations.md §7.1).');
        }

        if (456 === $statusCode) {
            throw new DeepLQuotaExceededException('The configured DeepL key has exhausted its character quota (HTTP 456). Wait for the next usage period, or use a different key.');
        }

        if (429 === $statusCode || 529 === $statusCode) {
            throw new DeepLRateLimitedException('DeepL is rate-limiting the configured key (HTTP '.$statusCode.'). Wait a moment and try again.');
        }

        // toArray(false) never throws for a non-2xx status; every status this
        // client treats specially has already been handled above, so what
        // remains here is either a genuine 200 or an unexpected code this
        // client has no dedicated handling for.
        try {
            /** @var array{translations?: list<array{text?: string}>, message?: string} $data */
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new DeepLRequestException(sprintf('DeepL returned a response that could not be read (HTTP %d): %s', $statusCode, $e->getMessage()), previous: $e);
        }

        if (200 !== $statusCode) {
            $message = \is_string($data['message'] ?? null) ? $data['message'] : 'no further detail was given';
            throw new DeepLRequestException(sprintf('DeepL returned HTTP %d for locale "%s": %s', $statusCode, $locale, $message));
        }

        $text = $data['translations'][0]['text'] ?? null;
        if (!\is_string($text)) {
            throw new DeepLRequestException(sprintf('DeepL\'s response for locale "%s" did not carry a translated string.', $locale));
        }

        return $text;
    }
}
