<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `/.well-known/security.txt` (RFC 9116).
 *
 * `SECURITY.md` has named this URL since it was written, and it answered 404:
 * the only file was the one beside the old static atlas, whose `Canonical:`
 * line pointed at an address the application did not serve. A canonical URL
 * that 404s is worse than no file, because a researcher who checks it concludes
 * there is nowhere to report and goes public instead.
 *
 * The expiry is the part worth a test. RFC 9116 requires it, requires it to be
 * under a year out, and says a file past it is invalid. A hand-written date is
 * a time bomb that goes off silently, which is the usual way security.txt
 * fails.
 */
final class SecurityTxtTest extends WebTestCase
{
    private function body(): string
    {
        $client = static::createClient();
        $client->request('GET', '/.well-known/security.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=utf-8');

        return (string) $client->getResponse()->getContent();
    }

    public function testItIsServedAndNamesAWayToReachUs(): void
    {
        $body = $this->body();

        self::assertStringContainsString('Contact: mailto:development@cyclingcommons.org', $body);
        // A second channel, for somebody with no mail client: the same reason
        // the contact page exists (contact-and-support.md §4).
        self::assertStringContainsString('Contact: http', $body);
        self::assertStringContainsString('/contact', $body);
    }

    public function testTheExpiryIsPresentValidAndUnderAYear(): void
    {
        $body = $this->body();

        self::assertSame(1, preg_match('/^Expires: (\S+)$/m', $body, $m), 'RFC 9116 requires exactly one Expires');

        $expires = new \DateTimeImmutable($m[1]);
        $now = new \DateTimeImmutable();

        self::assertGreaterThan($now, $expires, 'a security.txt past its expiry is invalid');
        self::assertLessThan($now->modify('+1 year'), $expires, 'RFC 9116: less than a year out');
    }

    /**
     * The canonical line must name the URL that actually serves this file.
     * Getting this wrong is what the old atlas copy did.
     */
    public function testTheCanonicalUrlIsThisUrl(): void
    {
        self::assertSame(1, preg_match('/^Canonical: (\S+)$/m', $this->body(), $m));
        self::assertStringEndsWith('/.well-known/security.txt', $m[1]);
    }

    /**
     * `SECURITY.md` and the served file must not drift apart.
     *
     * They already did once. There were THREE places stating the security
     * contact, and within a day they disagreed on the expiry, the languages and
     * the policy URL. A security contact that contradicts itself is worse than
     * one that is merely old, because a researcher cannot tell which is
     * current. The static copy is gone; this test keeps the two survivors
     * honest.
     */
    public function testTheHumanPolicyAndTheMachineFileAgree(): void
    {
        $md = (string) file_get_contents(\dirname(__DIR__, 3).'/SECURITY.md');
        $txt = $this->body();

        self::assertSame(1, preg_match('/^Contact: mailto:(\S+)$/m', $txt, $c));
        self::assertStringContainsString($c[1], $md, 'SECURITY.md must name the same address');

        self::assertSame(1, preg_match('/^Preferred-Languages: (.+)$/m', $txt, $l));
        $mdLanguages = [];
        if (1 === preg_match('/\*\*Languages:\*\* (.+)$/m', $md, $ml)) {
            foreach (explode(',', rtrim($ml[1], '.')) as $name) {
                $mdLanguages[] = strtolower(trim($name));
            }
        }
        self::assertNotSame([], $mdLanguages, 'SECURITY.md must state its languages');

        // "en" <-> "English", "nl" <-> "Nederlands": compare counts and let the
        // names carry the detail, rather than hard-coding a language table.
        $tags = array_map(trim(...), explode(',', $l[1]));
        self::assertCount(
            \count($tags),
            $mdLanguages,
            \sprintf('security.txt offers %s; SECURITY.md lists %s', $l[1], implode(', ', $mdLanguages)),
        );
    }

    /** Crawlers must be able to read it. */
    public function testRobotsDoesNotBlockIt(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('well-known', (string) $client->getResponse()->getContent());
    }
}
