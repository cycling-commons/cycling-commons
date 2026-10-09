<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Moderation\StatementDecision;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasons;
use App\Support\ReportGround;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one wording of a DSA Article 17 statement of reasons: every element
 * Article 17(3) lists, from one builder, in the recipient's language.
 *
 * @see docs/specs/content-reports.md §7
 */
final class StatementOfReasonsTest extends KernelTestCase
{
    private const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    private function translator(): TranslatorInterface
    {
        self::bootKernel();

        return static::getContainer()->get(TranslatorInterface::class);
    }

    public function testItSurvivesTheMessageRowItIsStoredOn(): void
    {
        $s = new StatementOfReasons(
            StatementDecision::AccountSuspended,
            StatementGround::Abuse,
            'Three threats in one week.',
            'ACCOUNT',
            subject: 'Rider',
            fromReport: true,
            automated: false,
            until: new \DateTimeImmutable('2026-11-01 13:39:00 UTC'),
            factsKey: 'dsa_statement.facts.retired_duplicate',
            answerPath: '/report/x/answer',
        );

        $back = StatementOfReasons::fromArray(json_decode((string) json_encode($s->toArray()), true));

        self::assertEquals($s, $back);
        self::assertNull(StatementOfReasons::fromArray(['decision' => 'nonsense']));
        self::assertNull(StatementOfReasons::fromArray('not an array'));
    }

    public function testEveryArticle17ElementIsInTheStatement(): void
    {
        $s = new StatementOfReasons(
            StatementDecision::NotPublished,
            StatementGround::NotAccepted,
            'The tap is 200 m further on.',
            'SUB-42',
            subject: 'Col du Test',
        );

        $lines = $s->lines($this->translator(), 'en', replyByEmail: true);

        self::assertStringContainsString('Col du Test', (string) $lines['decision'], '(a) what was decided, about what');
        self::assertNotEmpty($lines['scope'], '(a) and its scope');
        self::assertSame('The tap is 200 m further on.', $lines['facts'], '(b) the facts, in the curator\'s own words');
        self::assertNotEmpty($lines['source'], '(b) whether a report started it');
        self::assertNotEmpty($lines['automated'], '(c) whether automated means were used');
        self::assertNotEmpty($lines['ground'], '(d)/(e) the ground');
        self::assertNotEmpty($lines['basis'], '(d)/(e) law or our terms');
        self::assertStringContainsString('reply to this email', (string) $lines['redress'], '(f) how to contest it');
        self::assertSame('SUB-42', $lines['reference']);
        self::assertNull($lines['answer']);
    }

    public function testTheLinesSayWhatActuallyHappened(): void
    {
        $t = $this->translator();
        $own = (new StatementOfReasons(StatementDecision::Removed, StatementGround::Abuse, 'x', 'SUB-1'))->lines($t, 'en', false);
        $reported = (new StatementOfReasons(StatementDecision::Removed, StatementGround::Copyright, 'x', 'r', fromReport: true))->lines($t, 'en', false);
        $machine = (new StatementOfReasons(StatementDecision::Hidden, StatementGround::IntimateOrChild, '', 'p', fromReport: true, automated: true, factsKey: 'dsa_statement.facts.hidden_urgent'))->lines($t, 'en', false);

        self::assertNotSame($own['source'], $reported['source'], 'a report and our own check read differently');
        self::assertNotSame($own['basis'], $reported['basis'], 'a legal ground and a rule of ours read differently');
        self::assertNotSame($own['automated'], $machine['automated'], 'software acting on its own is said');
        self::assertNotSame('', (string) $machine['facts_system'], 'a decision nobody wrote a note for still states its facts');
        self::assertNull($machine['facts'], 'and does not invent a curator\'s note');
        $byEmail = (new StatementOfReasons(StatementDecision::Removed, StatementGround::Abuse, 'x', 'SUB-1'))->lines($t, 'en', true);
        self::assertNotSame($own['redress'], $byEmail['redress'], 'without a reply address it points to the contact page');
    }

    /**
     * Every decision, by a person and by our checks alone: an automated
     * statement never says a curator decided, published, removed or looked
     * at it, in any language, and a person's statement says who decided.
     */
    public function testEveryDecisionLineAgreesWithWhetherItWasAutomated(): void
    {
        $t = $this->translator();
        // What a person's decision line says and an automated one must not.
        $byAPerson = [
            'en' => '/\\b(a curator|an administrator) (decided|published|removed|changed|took|suspended)\\b|looked at it as part of/i',
        ];
        foreach (StatementDecision::cases() as $decision) {
            foreach ([false, true] as $automated) {
                if ($automated && $decision->isAboutAccount()) {
                    try {
                        new StatementOfReasons($decision, StatementGround::Abuse, 'x', 'ACCOUNT-1', automated: true);
                        self::fail($decision->value.': an account decision is an administrator\'s, never automated');
                    } catch (\InvalidArgumentException) {
                    }
                    continue;
                }
                $s = new StatementOfReasons($decision, StatementGround::NotAccepted, '', 'R-1', subject: 'Col du Test', automated: $automated, factsKey: 'dsa_statement.facts.file_refused', until: new \DateTimeImmutable('+3 days'));
                foreach (self::LOCALES as $locale) {
                    $lines = $s->lines($t, $locale, true);
                    $what = $lines['decision'].' '.$lines['source'];
                    self::assertStringNotContainsString('dsa_statement.', $what, $decision->value.' '.$locale.' has its wording');
                    if (isset($byAPerson[$locale])) {
                        self::assertSame(!$automated, 1 === preg_match($byAPerson[$locale], $what), \sprintf('%s, automated=%s, %s: "%s"', $decision->value, $automated ? 'yes' : 'no', $locale, $what));
                    }
                    if ($automated) {
                        $person = (new StatementOfReasons($decision, StatementGround::NotAccepted, '', 'R-1', subject: 'Col du Test', factsKey: 'dsa_statement.facts.file_refused'))->lines($t, $locale, true);
                        // "We hid it": both hides are ours alone, so one line serves both.
                        if (!\in_array($decision, [StatementDecision::Hidden, StatementDecision::HiddenThenRestored], true)) {
                            self::assertNotSame($person['decision'], $lines['decision'], $decision->value.' '.$locale.': the automated line is its own');
                        }
                        self::assertNotSame($person['source'], $lines['source'], $decision->value.' '.$locale.': no curator looked at it as part of our checks');
                    }
                }
            }
        }
    }

    /**
     * The urgent hide is explained once a curator has looked (owner
     * 2026-10-09). When the curator put the photo back, the statement says
     * the hide is over: what was hidden, by our checks, and that it is back.
     */
    public function testAHideThatEndedSaysThePhotoIsBack(): void
    {
        $t = $this->translator();
        $s = new StatementOfReasons(StatementDecision::HiddenThenRestored, StatementGround::IntimateOrChild, '', 'p-1', subject: 'Fontaine', fromReport: true, automated: true, factsKey: 'dsa_statement.facts.hidden_restored', aboutPhoto: true);

        $en = $s->lines($t, 'en', true);
        self::assertStringContainsString('your photo of "Fontaine"', $en['decision']);
        self::assertStringContainsString('back on the map', $en['scope']);
        self::assertStringNotContainsString('stays hidden', $en['scope'], 'not the wording of a hide that still lasts');
        self::assertStringContainsString('automatically', (string) $en['automated']);
        self::assertSame($t->trans('dsa_statement.source.report', [], null, 'en'), $en['source']);
        self::assertNotSame($t->trans(StatementDecision::Hidden->headlineKey(), [], null, 'en'), $en['headline']);
        foreach (self::LOCALES as $locale) {
            $lines = $s->lines($t, $locale, true);
            foreach (['headline', 'decision', 'scope', 'facts_system'] as $line) {
                self::assertStringNotContainsString('dsa_statement.', (string) $lines[$line], $line.' in '.$locale);
            }
        }
    }

    public function testARefusedFileSaysOurChecksDidIt(): void
    {
        $lines = (new StatementOfReasons(StatementDecision::NotPublished, StatementGround::FileRefused, '', 'u-1', automated: true, factsKey: 'dsa_statement.facts.file_refused', aboutPhoto: true))
            ->lines($this->translator(), 'en', true);

        self::assertStringNotContainsStringIgnoringCase('curator decided', $lines['decision']);
        self::assertStringContainsString('your photo', $lines['decision']);
        self::assertStringContainsString('automatic', $lines['decision'].' '.$lines['automated']);
    }

    public function testASuspensionNamesItsEndInTwentyFourHourTime(): void
    {
        $s = new StatementOfReasons(
            StatementDecision::AccountSuspended,
            StatementGround::Misuse,
            'Scraping the map with a script.',
            'ACCOUNT',
            until: new \DateTimeImmutable('2026-11-01 18:43:00', new \DateTimeZone('UTC')),
        );

        $scope = (string) $s->lines($this->translator(), 'en', true, 'UTC')['scope'];

        self::assertStringContainsString('18:43', $scope);
        self::assertStringContainsString('2026', $scope);
        self::assertStringNotContainsString('PM', $scope);
    }

    public function testSpamIsTheOneGroundThatOwesNoStatement(): void
    {
        foreach (StatementGround::cases() as $ground) {
            self::assertSame(StatementGround::Spam !== $ground, $ground->owesStatement(), $ground->value);
        }
    }

    public function testEveryRuleAReporterCanPickHasItsGround(): void
    {
        foreach (ReportGround::cases() as $ground) {
            $mapped = StatementGround::fromReport($ground);
            self::assertSame($ground->isRule(), null !== $mapped, $ground->value);
            if (null !== $mapped) {
                self::assertSame($ground->isLegal(), $mapped->isLegal(), $ground->value.' is law in both lists or in neither');
            }
        }
    }

    public function testEveryWordingExistsInEveryLanguage(): void
    {
        $t = $this->translator();
        self::assertInstanceOf(TranslatorBagInterface::class, $t);
        $keys = [];
        foreach (StatementDecision::cases() as $d) {
            $keys[] = $d->headlineKey();
            $keys[] = 'dsa_statement.decision.'.$d->value;
            $keys[] = 'dsa_statement.scope.'.$d->value;
            if (!$d->isAboutAccount()) {
                $keys[] = 'dsa_statement.decision_automated.'.$d->value;
            }
        }
        $keys[] = 'dsa_statement.source.automated';
        foreach (StatementGround::cases() as $g) {
            $keys[] = $g->label();
        }
        foreach (self::LOCALES as $locale) {
            $catalogue = $t->getCatalogue($locale);
            foreach ($keys as $key) {
                self::assertTrue($catalogue->defines($key), $key.' in '.$locale);
            }
        }
    }
}
