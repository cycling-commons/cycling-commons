<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Security\FormGuard;
use App\Security\ProofOfWork;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Posts the guarded anonymous forms (sign-up, new confirmation link) the way a
 * browser does: honeypots empty, a stamp older than the minimum dwell, and a
 * proof of work solved for real.
 */
trait GuardedSignupTrait
{
    /**
     * @param array<string, string|bool> $overrides form fields by full name
     */
    private function signUp(KernelBrowser $client, string $email, string $displayName = 'New Rider', array $overrides = []): Crawler
    {
        $page = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $fields = [
            'registration_form[email]' => $email,
            'registration_form[displayName]' => $displayName,
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[confirmAge]' => '1',
            'registration_form[agreeTerms]' => '1',
        ];

        return $client->request('POST', '/register', $this->guarded($page, $overrides + $fields));
    }

    /**
     * The rendered form's hidden fields, then the guard fields, then $fields.
     *
     * @param array<string, string|bool> $fields
     *
     * @return array<string, mixed>
     */
    private function guarded(Crawler $page, array $fields): array
    {
        $hidden = [];
        foreach ($page->filter('#cc-guarded-form input[type="hidden"]') as $node) {
            \assert($node instanceof \DOMElement);
            $hidden[$node->getAttribute('name')] = $node->getAttribute('value');
        }

        $challenge = $this->powChallenge();
        $hidden['pow_challenge'] = $challenge;
        $hidden['pow_nonce'] = $this->solvePow($challenge, ProofOfWork::DIFFICULTY);
        $hidden[FormGuard::HONEYPOT_A] = '';
        $hidden[FormGuard::HONEYPOT_B] = '';
        $hidden[FormGuard::STAMP] = $this->agedStamp();

        // Flat `form[field]` names nest the way a browser post does.
        parse_str(http_build_query($fields + $hidden), $nested);

        /* @var array<string, mixed> $nested */
        return $nested;
    }

    /** Issued by the service, not the endpoint: a request would reset the test's limiter pools. */
    private function powChallenge(): string
    {
        return static::getContainer()->get(ProofOfWork::class)->issue(new \DateTimeImmutable());
    }

    /** Past the minimum dwell, signed by FormGuard so no test has to sleep. */
    private function agedStamp(int $seconds = 30): string
    {
        return static::getContainer()->get(FormGuard::class)->stamp(new \DateTimeImmutable("-{$seconds} seconds"));
    }

    private function solvePow(string $challenge, int $difficulty): string
    {
        for ($nonce = 0;; ++$nonce) {
            $digest = hash('sha256', $challenge.'.'.$nonce, true);
            $bits = 0;
            foreach (str_split($digest) as $byte) {
                $value = \ord($byte);
                for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                    if (0 !== ($value & $mask)) {
                        break 2;
                    }
                    if (++$bits >= $difficulty) {
                        return (string) $nonce;
                    }
                }
            }
        }
    }
}
