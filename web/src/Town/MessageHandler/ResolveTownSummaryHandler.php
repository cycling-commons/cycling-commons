<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Town\MessageHandler;

use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsUnavailable;
use App\Media\Commons\WikidataImageRepository;
use App\Media\Message\ResolveWikidataImage;
use App\Town\Message\ResolveTownSummary;
use App\Town\OsmElementApi;
use App\Town\TownSourceUnavailable;
use App\Town\TownSummaryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Three hops, one row. OpenStreetMap names the Wikidata item; Wikidata names
 * the Wikipedia page in the reader's language, or English; Wikipedia gives the
 * first paragraph. A fourth, separate question asks Wikidata which cycling
 * races and routes start, finish or pass here. The photo takes the P18 path
 * every scenic POI already takes.
 *
 * "No page" and "no races" are answers, recorded; only a source that did not
 * reply lets go of the claim so a later reader asks again.
 *
 * @see docs/specs/map-and-search.md §6.5
 *
 * @phpstan-import-type CyclingEvent from TownSummaryRepository
 *
 * @api
 */
#[AsMessageHandler]
final readonly class ResolveTownSummaryHandler
{
    /** Wikipedias the site speaks; anything else falls back to English. */
    public const array LANGS = ['en', 'fr', 'nl', 'de', 'es'];

    /** A label that names a list article rather than the race. */
    private const string LIST_LABEL = '~^(Lijst van|Liste (des|der|de|van)|Lista de|List of)\b~iu';

    public function __construct(
        private OsmElementApi $osm,
        private CommonsApi $wiki,
        private TownSummaryRepository $towns,
        private WikidataImageRepository $images,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResolveTownSummary $message): void
    {
        $known = $this->towns->find($message->osmRef, $message->lang);
        if (null !== $known && $known['answered']) {
            return;   // a redelivery of a settled lookup changes nothing
        }
        [$type, $id] = explode('/', $message->osmRef, 2) + [1 => '0'];

        try {
            $tags = $this->osm->tags($type, (int) $id);
            $qidRaw = $tags['wikidata'] ?? null;
            if (null === $tags || !\is_string($qidRaw) || 1 !== preg_match('~^Q\d+$~', $qidRaw)) {
                $this->towns->record($message->osmRef, $message->lang, null, null, []);

                return;
            }
            $langs = \in_array($message->lang, self::LANGS, true) && 'en' !== $message->lang ? [$message->lang, 'en'] : ['en'];
            $text = $this->text($qidRaw, $langs);
            $cycling = $this->cycling($qidRaw, $langs);
            $facts = $this->facts($qidRaw);
        } catch (TownSourceUnavailable|CommonsUnavailable $e) {
            $this->towns->release($message->osmRef, $message->lang);
            $this->logger->warning('Town summary lookup failed', ['ref' => $message->osmRef, 'lang' => $message->lang, 'why' => $e->getMessage()]);

            return;
        }

        $this->towns->record($message->osmRef, $message->lang, $qidRaw, $text, $cycling, $facts);

        // The photo: the same P18 hop every scenic POI takes, so the town's
        // picture is a Commons file we hold, never a hotlink.
        if (null !== $message->continent && $this->images->claim($qidRaw)) {
            $this->bus->dispatch(new ResolveWikidataImage($qidRaw, $message->continent));
        }
    }

    /**
     * @param list<string> $langs
     *
     * @return array{title: string, extract: string, url: string, lang: string}|null
     *
     * @throws CommonsUnavailable
     */
    private function text(string $qid, array $langs): ?array
    {
        $links = $this->wiki->entities([$qid], $langs)[$qid]['links'] ?? [];
        foreach ($langs as $lang) {
            $title = $links[$lang] ?? null;
            if (null === $title) {
                continue;
            }
            $summary = $this->wiki->pageSummary($lang, $title);
            if (null !== $summary && '' !== $summary['extract']) {
                return $summary + ['lang' => $lang];
            }
        }

        return null;
    }

    /**
     * Founded and inhabitants (owner 2026-09-08). Same rule as the races:
     * this arm failing keeps the text.
     *
     * @return array{founded?: array{year: int, precision: int}, population?: array{n: int, year: ?int}}
     */
    private function facts(string $qid): array
    {
        try {
            return $this->wiki->townFacts($qid);
        } catch (CommonsUnavailable $e) {
            $this->logger->warning('Town facts lookup failed; text kept', ['qid' => $qid, 'why' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Races and routes, with a Wikipedia link where one exists. This arm
     * failing on its own costs the town its list, not its text: the query
     * service is the flakiest of the four sources, and a paragraph without a
     * race list is still worth showing.
     *
     * @param list<string> $langs
     *
     * @return list<CyclingEvent>
     */
    private function cycling(string $qid, array $langs): array
    {
        try {
            $events = $this->wiki->cyclingEventsAt($qid);
            if ([] === $events) {
                return [];
            }
            $named = $this->wiki->entities(array_column($events, 'qid'), $langs);
        } catch (CommonsUnavailable $e) {
            $this->logger->warning('Town cycling lookup failed; text kept', ['qid' => $qid, 'why' => $e->getMessage()]);

            return [];
        }
        $out = [];
        foreach ($events as $event) {
            $label = $named[$event['qid']]['label'] ?? null;
            if (null === $label) {
                continue;   // no name in any language we speak: nothing to show
            }
            // Some Wikipedias label a championship item by its list article
            // ("Lijst van wereldkampioenen wegrit elite mannen", seen on
            // Antwerp 2026-09-07). A list is not a race name; take English.
            if (1 === preg_match(self::LIST_LABEL, $label)) {
                $label = $named[$event['qid']]['label_en'] ?? $label;
            }
            $url = null;
            foreach ($langs as $lang) {
                $title = $named[$event['qid']]['links'][$lang] ?? null;
                if (null !== $title) {
                    $url = sprintf('https://%s.wikipedia.org/wiki/%s', $lang, rawurlencode(str_replace(' ', '_', $title)));
                    break;
                }
            }
            $out[] = ['qid' => $event['qid'], 'label' => $label, 'rels' => $event['rels'], 'n' => $event['n'], 'last' => $event['last'], 'url' => $url];
        }

        return $out;
    }
}
