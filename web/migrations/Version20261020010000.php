<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A region's "History, nature and culture" text (RegionStory,
 * moderation-and-contribution.md §3.1c): one entry per language, beside the
 * geographic lead in `context` / `context_curated`.
 *
 * North Holland gets its first text, in English, with its sources cited in
 * place (`[words](address)`). It is a system default
 * (no writer, no approver), so its credit line names the region's curators.
 * A region that is not on this database is left alone.
 */
final class Version20261020010000 extends AbstractMigration
{
    private const string NORTH_HOLLAND_TEXT = <<<'TXT'
Almost everything you ride across here was once wet. Around 1600, the northern part of Holland consisted of more water than land, covered by vast inland lakes, tidal inlets, and sodden bogs. Wind power drove the transformation, and the Beemster is the true showpiece: [drained between 1609 and 1612](https://www.hhnk.nl/de-beemster) using 43 windmills, it was declared a [UNESCO World Heritage site in 1999](https://whc.unesco.org/en/list/899). Its rational, mathematical grid of canals, tree-lined roads, and farm parcels was laid out as an intentional [Renaissance landscape](https://erfgoedmonitor.cultureelerfgoed.nl/mosaic/dashboard/werelderfgoed/droogmakerij-de-beemster).

The same wind powered the world's [first major industrial zone along the Zaan River](https://zaanwiki.nl/encyclopedie/doku.php?id=eco:economische_ontwikkeling_1580-1800). While [over a thousand mills](https://archief.zaanstad.nl/expositie/detail/3a14bbaa-39f5-4f52-b4f1-8e057ea11203) were constructed here over the centuries, with as many as [600 operating simultaneously](https://www.zaans-industrieel-erfgoed.nl/pages_2/toerisme_fietsrt1_achtergrond.html) at the region's peak in the 18th century, hundreds were already humming by the Dutch Golden Age, sawing Baltic timber, pressing oil, and milling paper.

Water was not only tamed; it was also weaponized. The Stelling van Amsterdam is a 135-kilometer defensive ring built between 1883 and 1920. It incorporates 46 forts and batteries, ingeniously linked by a controlled network of polders, dikes, and sluices designed to [intentionally flood low-lying fields](https://www.stelling-amsterdam.nl/stelling/inunderen/index.php) just deep enough to stop enemy soldiers and artillery, but too shallow for boats to cross. This defensive flood plan was actually put into action: during the German invasion in May 1940, the [inundation sluice in the Beemster ring dike](https://www.hhnk.nl/inundatiesluis-zuidelijke-beemsterringdijk) was opened to create the water barrier, though the country surrendered just days later before frontline combat reached the ring.

Three distinct landscapes alternate as you ride through this reclaimed terrain: sweeping North Sea dunes guarding the western coast, lush peat meadows (veenweiden) in the center where [black-tailed godwits](https://www.vogelbescherming.nl/ontdek-vogels/kennis-over-vogels/vogelgids/vogel/grutto) nest in spring, and open maritime vistas alongside the Markermeer and the Wadden Sea to the east and north. Stretching from Den Helder all the way to Denmark, the Wadden Sea forms the [largest unbroken intertidal sand and mudflat system on Earth](https://whc.unesco.org/en/list/1314), internationally renowned for its rich coastal biodiversity.
TXT;

    #[\Override]
    public function getDescription(): string
    {
        return 'region.story: History, nature and culture text per language; North Holland in English';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region ADD story JSONB DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN region.story IS 'History, nature and culture text per locale: {text, userId, approvedBy, submissionId, at}; sources linked in the text. Written through Text proposals (RegionStory).'");
        $this->addSql(
            'UPDATE region SET story = CAST(:story AS JSONB) WHERE slug = :slug',
            [
                'slug' => 'noord-holland',
                'story' => json_encode(['en' => [
                    'text' => self::NORTH_HOLLAND_TEXT,
                    'userId' => null,
                    'approvedBy' => null,
                    'submissionId' => null,
                    'at' => '2026-10-20T00:00:00+00:00',
                ]], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            ],
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP story');
    }
}
