<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One person often holds a staging and a production account under one email.
 * With one issuer both show up in an authenticator app as the same entry, and
 * a staging code gets typed into production (owner 2026-09-25).
 */
final class TotpIssuerTest extends KernelTestCase
{
    private const string PROBE = 'Cycling Commons (probe)';

    /** @var array{env: mixed, server: mixed}|null null when this test left the variable alone */
    private ?array $saved = null;

    #[\Override]
    protected function tearDown(): void
    {
        if (null === $this->saved) {
            parent::tearDown();

            return;
        }
        // Put back what dotenv loaded; later tests boot kernels that need it.
        foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
            if (null === $this->saved[$key]) {
                unset($GLOBALS[$global]['TOTP_ISSUER']);
            } else {
                $GLOBALS[$global]['TOTP_ISSUER'] = $this->saved[$key];
            }
        }
        parent::tearDown();
    }

    public function testTheQrCodeNamesTheIssuerOfThisEnvironment(): void
    {
        $this->saved = ['env' => $_ENV['TOTP_ISSUER'] ?? null, 'server' => $_SERVER['TOTP_ISSUER'] ?? null];
        $_ENV['TOTP_ISSUER'] = $_SERVER['TOTP_ISSUER'] = self::PROBE;
        self::bootKernel();

        $user = (new User())->setEmail('rider@example.test');
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $qr = self::getContainer()->get(TotpAuthenticatorInterface::class)->getQRContent($user);

        self::assertStringContainsString('issuer='.rawurlencode(self::PROBE), $qr);
    }

    public function testStagingIsNamedApartFromProduction(): void
    {
        $default = self::issuerIn('.env');
        $staging = self::issuerIn('.env.staging');

        self::assertSame('Cycling Commons', $default);
        self::assertNotSame($default, $staging);
        self::assertStringContainsString('staging', (string) $staging);
    }

    /** The committed placeholder files only, one extracted line. */
    private static function issuerIn(string $file): ?string
    {
        $matched = preg_match('/^TOTP_ISSUER=(.*)$/m', (string) file_get_contents(\dirname(__DIR__, 2).'/'.$file), $m);

        return 1 === $matched ? trim($m[1], " \t\"'") : null;
    }
}
