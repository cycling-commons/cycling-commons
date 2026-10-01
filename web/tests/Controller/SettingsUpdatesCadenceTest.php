<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Controller;

use App\Account\UpdatesCadence;
use App\Account\UpdatesConsent;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The release-list switch on /account/settings and its "how often" radios:
 * Big news is pre-selected, and a save through the real form stores the
 * cadence and records the consent.
 *
 * @see docs/specs/roadmap-and-changelog.md §4
 */
final class SettingsUpdatesCadenceTest extends WebTestCase
{
    private const string PASSWORD = 'securepass12345!';

    public function testTheSwitchCarriesTwoRadiosWithBigNewsChosen(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'cadence-view@example.com');

        $crawler = $client->request('GET', '/account/settings');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Keep me up to date.', $html);
        self::assertSelectorExists('input[name="settings[updatesOptIn]"][data-updates-toggle]');

        $radios = $crawler->filter('fieldset[data-updates-cadence] input[type="radio"][name="settings[updatesCadence]"]');
        self::assertCount(2, $radios);
        self::assertSame(['big', 'every'], $radios->each(static fn ($r): string => (string) $r->attr('value')));
        self::assertSame('big', $crawler->filter('input[name="settings[updatesCadence]"][checked]')->attr('value'));

        $labels = $crawler->filter('fieldset[data-updates-cadence] label')->each(static fn ($l): string => trim($l->text()));
        self::assertSame(['Big news only: at most 4 times a year', 'Every update: at most 2 times a month'], $labels);
        // Shown without script; the script hides it while the switch is off.
        self::assertNull($crawler->filter('fieldset[data-updates-cadence]')->attr('hidden'));
    }

    public function testOptingInForEveryUpdateStoresAndRecordsIt(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'cadence-save@example.com');

        $this->save($client, optIn: true, cadence: UpdatesCadence::Every);

        $user = $this->fetch('cadence-save@example.com');
        self::assertTrue($user->isUpdatesOptIn());
        self::assertSame(UpdatesCadence::Every, $user->getUpdatesCadence());
        self::assertSame(['v2-every'], $this->versions($user));

        // The saved choice comes back checked.
        $crawler = $client->request('GET', '/account/settings');
        self::assertSame('every', $crawler->filter('input[name="settings[updatesCadence]"][checked]')->attr('value'));

        // Changing how often while on is a new consent.
        $this->save($client, optIn: true, cadence: UpdatesCadence::Big);
        $user = $this->fetch('cadence-save@example.com');
        self::assertSame(UpdatesCadence::Big, $user->getUpdatesCadence());
        self::assertSame(['v2-big', 'v2-every'], $this->versions($user));

        // Turning it off keeps the records and adds none.
        $this->save($client, optIn: false, cadence: UpdatesCadence::Big);
        $user = $this->fetch('cadence-save@example.com');
        self::assertFalse($user->isUpdatesOptIn());
        self::assertSame(['v2-big', 'v2-every'], $this->versions($user));
    }

    private function save(KernelBrowser $client, bool $optIn, UpdatesCadence $cadence): void
    {
        $crawler = $client->request('GET', '/account/settings');
        $form = $crawler->selectButton('Save profile')->form();
        $box = $form['settings[updatesOptIn]'];
        self::assertInstanceOf(ChoiceFormField::class, $box);
        $optIn ? $box->tick() : $box->untick();
        $form['settings[updatesCadence]'] = $cadence->value;
        $client->submit($form);

        self::assertResponseRedirects('/account/settings');
    }

    private function signIn(KernelBrowser $client, string $email): void
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('Cadence Rider')
            ->setEmailVerified(true)
            ->setEmailVerifiedAt(new \DateTimeImmutable())
            ->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    private function fetch(string $email): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @return list<string> sorted: records written in one second have no order */
    private function versions(User $user): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $versions = array_map(
            static fn (ConsentRecord $r): string => $r->getVersion(),
            $em->getRepository(ConsentRecord::class)->findBy(['userId' => $user->getId(), 'kind' => UpdatesConsent::KIND]),
        );
        sort($versions);

        return $versions;
    }
}
