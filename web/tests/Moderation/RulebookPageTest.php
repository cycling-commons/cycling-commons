<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The curator rulebook page: nineteen sections in six tab panels, every rule
 * carrying its data-rule id exactly once, every section id a working deep
 * link (the takedowns desk links /moderate/rulebook#takedowns), and no PDF.
 * The rulebook is the page itself; there is no file to download and no route
 * that streams one.
 *
 * The tab list renders hidden and every panel renders visible:
 * js/rulebook-tabs.js turns the page into tabs, so without it the page reads
 * as one long document (tests/js/rulebook-tabs.test.cjs covers the hash and
 * ?tab= handling).
 *
 * @see docs/specs/moderation-and-contribution.md §5
 */
final class RulebookPageTest extends WebTestCase
{
    private const TABS = ['start', 'places', 'routes', 'photos', 'desks', 'account'];

    private const SECTIONS = [
        'dashboard', 'verbs', 'before-approve', 'routes', 'data', 'regions', 'town-text',
        'takedowns', 'reports', 'bugs', 'translations', 'providers', 'markers', 'do', 'dont',
        'privacy', 'account', 'workload-view', 'deep-rules',
    ];

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

    private function rulebook(KernelBrowser $client, string $email): Crawler
    {
        $this->loginAs($client, $email, ['ROLE_CURATOR']);
        $crawler = $client->request('GET', '/moderate/rulebook');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function testTabsCarryTheTabRolesAndEachControlsItsPanel(): void
    {
        $client = static::createClient();
        $crawler = $this->rulebook($client, 'rulebook-tabs@example.test');

        $list = $crawler->filter('.rb [role="tablist"]');
        self::assertCount(1, $list);
        self::assertNotNull($list->attr('aria-label'));
        // Hidden until the script runs, so the page without it is one long page.
        self::assertNotNull($list->attr('hidden'));

        $tabs = $list->filter('[role="tab"]');
        self::assertSame(\count(self::TABS), $tabs->count());
        $selected = 0;
        foreach (self::TABS as $i => $key) {
            $tab = $tabs->eq($i);
            self::assertSame('button', $tab->nodeName());
            self::assertSame('rbt-'.$key, $tab->attr('id'));
            self::assertSame('rbp-'.$key, $tab->attr('aria-controls'));
            self::assertNotSame('', trim($tab->text()));
            if ('true' === $tab->attr('aria-selected')) {
                ++$selected;
                self::assertSame('0', $tab->attr('tabindex'));
            } else {
                self::assertSame('false', $tab->attr('aria-selected'));
                self::assertSame('-1', $tab->attr('tabindex'));
            }

            $panel = $crawler->filter('#rbp-'.$key);
            self::assertCount(1, $panel);
            self::assertSame('tabpanel', $panel->attr('role'));
            self::assertSame('rbt-'.$key, $panel->attr('aria-labelledby'));
            self::assertNull($panel->attr('hidden'), 'every panel renders visible');
            self::assertGreaterThan(0, $panel->filter('section[id]')->count());
        }
        self::assertSame(1, $selected);
        self::assertSame(\count(self::TABS), $crawler->filter('[role="tabpanel"]')->count());
        self::assertCount(1, $crawler->filter('script[src*="rulebook-tabs"]'));
    }

    public function testEverySectionSitsInExactlyOnePanelAndEveryInPageLinkLands(): void
    {
        $client = static::createClient();
        $crawler = $this->rulebook($client, 'rulebook-sections@example.test');

        foreach (self::SECTIONS as $id) {
            self::assertCount(1, $crawler->filter('[id="'.$id.'"]'), '#'.$id);
            self::assertCount(1, $crawler->filter('[role="tabpanel"] section[id="'.$id.'"]'), '#'.$id.' is in a panel');
        }
        self::assertSame(\count(self::SECTIONS), $crawler->filter('[role="tabpanel"] section[id]')->count());

        // The takedowns desk links /moderate/rulebook#takedowns.
        self::assertCount(1, $crawler->filter('#rbp-photos section#takedowns'));

        foreach ($crawler->filter('.rb a[href^="#"]') as $a) {
            \assert($a instanceof \DOMElement);
            $target = substr($a->getAttribute('href'), 1);
            self::assertCount(1, $crawler->filter('[role="tabpanel"] [id="'.$target.'"]'), 'in-page link #'.$target);
        }
    }

    public function testEveryRuleIdRendersExactlyOnce(): void
    {
        $client = static::createClient();
        $crawler = $this->rulebook($client, 'rulebook-rules@example.test');

        $rendered = $crawler->filter('[data-rule]')->each(static fn (Crawler $n): string => (string) $n->attr('data-rule'));

        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/templates/moderate/rulebook.html.twig');
        $source = (string) preg_replace('/\{#.*?#\}/s', '', $source);
        preg_match_all('/data-rule="([^"]+)"/', $source, $m);
        $declared = $m[1];

        self::assertNotEmpty($declared);
        self::assertSame([], array_keys(array_filter(array_count_values($rendered), static fn (int $n): bool => $n > 1)), 'duplicate rule ids');
        sort($rendered);
        sort($declared);
        self::assertSame($declared, $rendered);
        foreach ($rendered as $id) {
            self::assertMatchesRegularExpression('/^RB-[A-Z]+-\d{2}$/', $id);
        }

        // Only the lead sits above the tabs; every other rule is in a panel.
        self::assertSame(\count($rendered) - 1, $crawler->filter('[role="tabpanel"] [data-rule]')->count());
        self::assertCount(1, $crawler->filter('.rb > .lead[data-rule="RB-LEAD-01"]'));
    }

    public function testThereIsNoPdf(): void
    {
        $client = static::createClient();
        $crawler = $this->rulebook($client, 'rulebook-nopdf@example.test');

        self::assertSame(0, $crawler->filter('a[href$=".pdf"], a[href*=".pdf?"], .rb-pdf')->count());
        self::assertStringNotContainsStringIgnoringCase('pdf', $crawler->filter('.rb')->text());

        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);
        self::assertNull($router->getRouteCollection()->get('moderate_rulebook_pdf'));

        $client->request('GET', '/moderate/rulebook.pdf');
        self::assertResponseStatusCodeSame(404);
    }
}
