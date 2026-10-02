<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CountryInterestService;
use App\Entity\User;
use App\Support\SupportMailer;
use App\Support\SupportRecipients;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The support address hears about curator applications and country requests
 * (owner 2026-10-02: "I need both those emails").
 *
 * End to end through `/join/{cc}`, the one page both arrive by, because the
 * properties that matter are the controller's: the rider's request succeeds
 * whatever the mail transport does, and the mail leaves only after the row is
 * committed.
 *
 * @see docs/specs/moderation-and-contribution.md §11.1, §11.2
 * @see docs/specs/contact-and-support.md §7
 */
final class RequestNotifyMailTest extends WebTestCase
{
    /** KernelBrowser rebuilds the container between requests otherwise, and drops a swapped mailer. */
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function user(string $email, bool $publicProfile = false): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName('Notify '.substr(md5($email), 0, 6));
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $u->setPublicProfile($publicProfile);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function seedCountryRegion(string $cc, string $slug): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 1000, ?, ?, 2, 'test', NOW(), NOW())",
            [$slug, strtoupper($slug), $cc, $cc],
        );
    }

    /** @param array<string, string> $fields */
    private function post(KernelBrowser $client, string $cc, string $form, array $fields): void
    {
        $crawler = $client->request('GET', '/join/'.$cc);
        $token = $crawler->filter('form[data-form="'.$form.'"] input[name="_token"]')->attr('value');

        $client->request('POST', '/join/'.$cc, ['form' => $form, '_token' => (string) $token] + $fields, [], [
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);
    }

    /**
     * The mails of the LAST request that went to the support address. The
     * applicant's own receipt leaves in the same request and is not one.
     *
     * @return list<Email>
     */
    private function supportMails(string $subjectPrefix): array
    {
        $out = [];
        foreach (self::getMailerMessages() as $mail) {
            if ($mail instanceof Email && str_starts_with((string) $mail->getSubject(), $subjectPrefix)) {
                $out[] = $mail;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function supportAddresses(): array
    {
        return array_map(
            static fn ($a): string => $a->getAddress(),
            self::getContainer()->get(SupportRecipients::class)->all(),
        );
    }

    /** A SupportMailer whose transport is down, swapped in before the first request. */
    private function breakTheMailer(RecordingLogger $logger): void
    {
        $c = self::getContainer();
        $c->set(SupportMailer::class, new SupportMailer(
            new DeadTransportMailer(),
            $c->get(TranslatorInterface::class),
            $logger,
            $c->get(SupportRecipients::class),
            $c->get(UrlGeneratorInterface::class),
            'https://cyclingcommons.test',
            'en',
            (string) $c->getParameter('cc.support.from_email'),
            'Cycling Commons',
        ));
    }

    public function testACuratorApplicationMailsTheSupportAddress(): void
    {
        $client = $this->client();
        $this->seedCountryRegion('IT', 'italy-notify-test');
        $u = $this->user('notify-apply@example.test', true);
        $client->loginUser($u, 'main');

        $this->post($client, 'IT', 'curator-application', [
            'about' => 'I ride the Dolomites every weekend',
            'social' => 'example.test/rider',
        ]);
        self::assertResponseRedirects('/join/IT');

        $mails = $this->supportMails('[Cycling Commons] Curator application');
        self::assertCount(1, $mails, 'one mail to the support address per application');
        $mail = $mails[0];

        self::assertSame('[Cycling Commons] Curator application: Italy', $mail->getSubject());
        self::assertSame(
            $this->supportAddresses(),
            array_map(static fn ($a): string => $a->getAddress(), $mail->getTo()),
            'it goes to the same recipients as the contact form and bug reports',
        );
        self::assertSame('notify-apply@example.test', $mail->getReplyTo()[0]->getAddress(), 'answering reaches the applicant');

        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('Italy', $html);
        self::assertStringContainsString($u->getDisplayName(), $html);
        self::assertStringContainsString('I ride the Dolomites every weekend', $html, 'the reviewer reads their words in the mail');
        self::assertStringContainsString('/riders/'.$u->getUuid()?->toRfc4122(), $html, 'a public profile is linked');
        self::assertMatchesRegularExpression('#href="https?://[^"]+/admin/curator-applications"#', $html, 'an absolute link to the desk that decides it');
    }

    public function testACountryRequestMailsTheSupportAddressWithTheRunningTotal(): void
    {
        $client = $this->client();
        $service = self::getContainer()->get(CountryInterestService::class);
        // Two riders asked before, one of them for an area: the desk counts both.
        $service->record($this->user('notify-earlier-1@example.test'), 'ES', false, '');
        $service->record($this->user('notify-earlier-2@example.test'), 'ES', false, '', 'Asturias');

        $u = $this->user('notify-request@example.test');
        $client->loginUser($u, 'main');
        $this->post($client, 'ES', 'country-interest', [
            'willing' => '1',
            'region_name' => 'Galicia',
            'note' => 'Nothing here is on the map yet',
        ]);
        self::assertResponseRedirects('/join/ES');

        $mails = $this->supportMails('[Cycling Commons] Country request');
        self::assertCount(1, $mails);
        $mail = $mails[0];

        self::assertSame('[Cycling Commons] Country request: Galicia, Spain (would curate)', $mail->getSubject());
        self::assertSame($this->supportAddresses(), array_map(static fn ($a): string => $a->getAddress(), $mail->getTo()));

        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('Spain', $html);
        self::assertStringContainsString('Galicia', $html);
        self::assertStringContainsString('Nothing here is on the map yet', $html);
        self::assertMatchesRegularExpression('#Requests for this country</td>\s*<td[^>]*>3</td>#', $html, 'the total counts this request and the two before it');
        self::assertMatchesRegularExpression('#href="https?://[^"]+/admin/country-requests"#', $html);
    }

    /**
     * The flood rule: a request mails only when it says something new. Asking
     * again for the same area is silent; offering to curate where before the
     * rider only asked is news, and mails once.
     */
    public function testOnlyANewRequestOrANewOfferToCurateMails(): void
    {
        $this->client();
        $service = self::getContainer()->get(CountryInterestService::class);
        $u = $this->user('notify-flood@example.test');

        $count = fn (): int => \count($this->supportMails('[Cycling Commons] Country request'));

        $service->record($u, 'PT', false, 'first');
        self::assertSame(1, $count(), 'a new request mails');

        $service->record($u, 'PT', false, 'asked again with a new note');
        self::assertSame(1, $count(), 'the same area again sends nothing');

        $service->record($u, 'PT', true, '');
        self::assertSame(2, $count(), 'a new offer to curate is news');

        $service->record($u, 'PT', true, '');
        $service->record($u, 'PT', false, '');
        self::assertSame(2, $count(), 'and is not news the second time, nor when the box is left unticked');

        $service->record($u, 'PT', false, '', 'Algarve');
        self::assertSame(3, $count(), 'a different area is a different request');
    }

    public function testADeadTransportDoesNotFailTheApplication(): void
    {
        $client = $this->client();
        $logger = new RecordingLogger();
        $this->breakTheMailer($logger);
        $this->seedCountryRegion('IT', 'italy-dead-mail-test');
        $u = $this->user('notify-dead-apply@example.test');
        $client->loginUser($u, 'main');

        $this->post($client, 'IT', 'curator-application', ['about' => 'I know these roads']);

        self::assertResponseRedirects('/join/IT', null, 'the rider sees success, not a 500');
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT status FROM curator_application WHERE user_id = ?', [$u->getId()]);
        self::assertIsArray($row, 'the application is committed');
        self::assertSame('pending', $row['status']);
        self::assertContains('Could not send a support mail (curator application notification).', $logger->errors);
    }

    public function testADeadTransportDoesNotFailTheCountryRequest(): void
    {
        $client = $this->client();
        $logger = new RecordingLogger();
        $this->breakTheMailer($logger);
        $u = $this->user('notify-dead-request@example.test');
        $client->loginUser($u, 'main');

        $this->post($client, 'ES', 'country-interest', ['willing' => '1']);

        self::assertResponseRedirects('/join/ES', null, 'the rider sees success, not a 500');
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT willing_to_curate FROM country_interest WHERE user_id = ?', [$u->getId()]);
        self::assertIsArray($row, 'the request is committed');
        self::assertContains('Could not send a support mail (country request notification).', $logger->errors);
    }
}

/** @internal */
final class DeadTransportMailer implements MailerInterface
{
    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new TransportException('smtp is down');
    }
}

/** @internal */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $errors = [];

    /** @param array<string, mixed> $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ('error' === $level) {
            $this->errors[] = (string) $message;
        }
    }
}
