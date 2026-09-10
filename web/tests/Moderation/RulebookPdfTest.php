<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The moderator rulebook PDF is streamed by the app to curators only
 * (owner 2026-08-26): a file under public/ is readable by anyone who guesses
 * the URL, and the rulebook is a script for talking a curator into a removal.
 *
 * CC_RULEBOOK_PDF_PATH names the file on the server (outside public/, never in
 * the repository). The route 404s when it is unset or names nothing, and the
 * rulebook page shows the download link only when the file is really there.
 *
 * @see docs/specs/moderation-and-contribution.md §5
 */
final class RulebookPdfTest extends WebTestCase
{
    private ?string $pdf = null;

    #[\Override]
    protected function tearDown(): void
    {
        if (null !== $this->pdf && is_file($this->pdf)) {
            unlink($this->pdf);
        }
        $this->pdf = null;
        unset($_SERVER['CC_RULEBOOK_PDF_PATH'], $_ENV['CC_RULEBOOK_PDF_PATH']);
        putenv('CC_RULEBOOK_PDF_PATH');
        parent::tearDown();
    }

    private function configurePdf(?string $path): void
    {
        if (null === $path) {
            $path = sys_get_temp_dir().'/cc-rulebook-test-'.bin2hex(random_bytes(4)).'.pdf';
            file_put_contents($path, "%PDF-1.4\n% rulebook test fixture\n");
            $this->pdf = $path;
        }
        $_SERVER['CC_RULEBOOK_PDF_PATH'] = $path;
        $_ENV['CC_RULEBOOK_PDF_PATH'] = $path;
        putenv('CC_RULEBOOK_PDF_PATH='.$path);
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(KernelBrowser $client, string $email, array $roles): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('rulebook-test');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        // Elevated roles must be TOTP-enrolled or TwoFactorSetupEnforcer redirects.
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $user;
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $this->configurePdf(null);
        $client = static::createClient();

        $client->request('GET', '/moderate/rulebook.pdf');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testPlainRiderIsRefused(): void
    {
        $this->configurePdf(null);
        $client = static::createClient();
        $this->loginAs($client, 'rulebook-rider@example.test', ['ROLE_USER']);

        $client->request('GET', '/moderate/rulebook.pdf');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCuratorGetsThePdfStreamedPrivately(): void
    {
        $this->configurePdf(null);
        $client = static::createClient();
        $this->loginAs($client, 'rulebook-curator@example.test', ['ROLE_CURATOR']);

        $client->request('GET', '/moderate/rulebook.pdf');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        self::assertStringStartsWith('%PDF-', $body);
    }

    public function testRulebookPageLinksTheStreamWhenTheFileExists(): void
    {
        $this->configurePdf(null);
        $client = static::createClient();
        $this->loginAs($client, 'rulebook-curator2@example.test', ['ROLE_CURATOR']);

        $client->request('GET', '/moderate/rulebook');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.rb-pdf a[href$="/moderate/rulebook.pdf"]');
    }

    public function testMissingFileMeansNoLinkAndNotFound(): void
    {
        $this->configurePdf('/nonexistent/cc-rulebook-'.bin2hex(random_bytes(4)).'.pdf');
        $client = static::createClient();
        $this->loginAs($client, 'rulebook-curator3@example.test', ['ROLE_CURATOR']);

        $client->request('GET', '/moderate/rulebook');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.rb-pdf');

        $client->request('GET', '/moderate/rulebook.pdf');
        self::assertResponseStatusCodeSame(404);
    }
}
