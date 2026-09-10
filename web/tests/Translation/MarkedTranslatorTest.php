<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\MarkedTranslator;
use App\Translation\MarkerCodec;
use App\Translation\MarkerIndex;
use App\Translation\TranslateMode;
use App\Translation\TranslationCaches;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * The two guards in {@see MarkedTranslator::trans()} (translations.md §4.1).
 *
 * Both are one line, both are load-bearing, and neither had a test. The
 * decorator is built here by hand around a real {@see MarkerIndex} so the
 * guards are exercised with the mode genuinely ON: an assertion made through
 * a page would prove nothing about them, because a page that renders no
 * marks at all passes for a dozen other reasons.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class MarkedTranslatorTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    /**
     * A translator with the mode forced on for `nl`.
     *
     * {@see TranslateMode} reads its answer off the main request attribute,
     * and only {@see TranslateMode::decide()} touches security, so setting
     * the attribute is the whole of "the mode is on" here.
     */
    private function marked(Translator $inner): MarkedTranslator
    {
        $request = Request::create('/nl/over-ons');
        $request->setLocale('nl');
        $request->attributes->set(TranslateMode::ATTR, true);
        $requests = new RequestStack();
        $requests->push($request);

        /** @var Security $security */
        $security = static::getContainer()->get(Security::class);
        /** @var MarkerIndex $markers */
        $markers = static::getContainer()->get(MarkerIndex::class);

        return new MarkedTranslator($inner, new TranslateMode($security, $requests), $markers);
    }

    public function testOnlyTheMessagesDomainIsMarkedAndAKeyWithNoLiveRowIsNot(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'marked.guard.live', 'About');
        $em->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();
        $id = (int) $entry->getId();

        $inner = new Translator('nl');
        $inner->addLoader('array', new ArrayLoader());
        $inner->addResource('array', [
            'marked.guard.live' => 'Over ons',
            'marked.guard.no_row' => 'Geen rij',
        ], 'nl');
        $inner->addResource('array', ['marked.guard.live' => 'Ongeldig'], 'nl', 'validators');

        $translator = $this->marked($inner);

        // The control: with the mode on, a live `messages` key IS marked, so
        // the two assertions below cannot pass for want of marking anywhere.
        self::assertSame(
            MarkerCodec::wrap($id, false, 'Over ons'),
            $translator->trans('marked.guard.live', [], 'messages', 'nl'),
        );

        // Guard 1: another domain comes back untouched. This is what keeps
        // the validator, security and form domains clean, and those strings
        // reach places (a constraint message reused in a JSON body, a form
        // label attribute) where a mark is not merely useless.
        self::assertSame('Ongeldig', $translator->trans('marked.guard.live', [], 'validators', 'nl'));

        // Guard 2: a key with no live catalogue row comes back untouched.
        // There is nothing to open a form for, so a mark would be a click
        // that could only 404.
        self::assertSame('Geen rij', $translator->trans('marked.guard.no_row', [], 'messages', 'nl'));
    }
}
