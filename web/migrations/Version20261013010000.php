<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The roadmap in the database, with votes, follows and rider proposals
 * (docs/specs/roadmap-and-changelog.md §8).
 *
 * `roadmap_item` and `roadmap_item_text` hold the roadmap itself, one text
 * per item per language; they start with the fourteen items that were in
 * App\Content\ReleaseNotes::ROADMAP and its catalogue keys on 13 October
 * 2026, in the same groups and order. `roadmap_proposal` holds the ideas
 * riders send, `roadmap_proposal_image` the one picture an idea may carry,
 * public only when its rider allowed it and the desk chose to show it.
 * `roadmap_vote` and `roadmap_follow` hold one row per rider per ref, where a
 * ref is `item:<id>` or `proposal:<id>`.
 */
final class Version20261013010000 extends AbstractMigration
{
    /**
     * The roadmap file's items: [old key suffix, group, position, [locale => [title, body]]].
     *
     * @var list<array{0: string, 1: string, 2: int, 3: array<string, array{0: string, 1: string}>}>
     */
    private const array SEED = [
        ['going_live', 'now', 10, [
            'en' => ['The launch', 'The first weeks online: finding testers, quickly fixing what they run into, and making the Commons known among riders.'],
            'nl' => ['De lancering', 'De eerste weken online: testers vinden, snel oplossen waar zij tegenaan lopen, en de Commons bekend maken onder fietsers.'],
            'fr' => ['Le lancement', 'Les premières semaines en ligne : trouver des testeurs, corriger vite ce qu\'ils rencontrent, et faire connaître le Commons auprès des cyclistes.'],
            'de' => ['Der Start', 'Die ersten Wochen online: Tester finden, schnell beheben, worauf sie stoßen, und The Commons unter Radfahrenden bekannt machen.'],
            'es' => ['El lanzamiento', 'Las primeras semanas en línea: encontrar testers, arreglar rápido lo que encuentren y dar a conocer el Commons entre los ciclistas.'],
        ]],
        ['provider_refresh', 'now', 20, [
            'en' => ['More data providers, refreshing themselves', 'Official open sources, such as a national drinking-water register, added from the curator desk and refreshed on a schedule.'],
            'nl' => ['Meer databronnen die zichzelf bijwerken', 'Officiële open bronnen, zoals een nationaal drinkwaterregister, toegevoegd vanaf de moderatiedesk en volgens een vast schema ververst.'],
            'fr' => ['Plus de sources de données, qui se mettent à jour seules', 'Des sources officielles ouvertes, comme un registre national des points d\'eau potable, ajoutées depuis le bureau des curateurs et actualisées selon un calendrier.'],
            'de' => ['Mehr Datenquellen, die sich selbst aktualisieren', 'Offizielle offene Quellen wie ein nationales Trinkwasserregister, hinzugefügt vom Kuratoren-Desk und nach Zeitplan aufgefrischt.'],
            'es' => ['Más proveedores de datos, que se actualizan solos', 'Fuentes oficiales abiertas, como un registro nacional de agua potable, añadidas desde la mesa de curadores y actualizadas según un calendario.'],
        ]],
        ['roadmap_votes', 'next', 10, [
            'en' => ['Have a say in this list', 'Vote on what matters to you, follow an item, and propose one of your own.'],
            'nl' => ['Meepraten over deze lijst', 'Stemmen op wat jou uitmaakt, een punt volgen, en zelf iets voorstellen.'],
            'fr' => ['Avoir voix au chapitre', 'Voter pour ce qui compte pour vous, suivre un élément, et en proposer un.'],
            'de' => ['Bei dieser Liste mitreden', 'Abstimmen, was dir wichtig ist, einem Punkt folgen, und selbst einen vorschlagen.'],
            'es' => ['Opinar sobre esta lista', 'Votar lo que te importa, seguir un punto y proponer el tuyo.'],
        ]],
        ['more_countries', 'next', 20, [
            'en' => ['More countries and regions', 'The Commons opening in new countries, each with curators who know the roads. Ask for yours on the join page.'],
            'nl' => ['Meer landen en regio\'s', 'De Commons opent in nieuwe landen, elk met regiobeheerders die de wegen kennen. Vraag jouw land aan via de aanmeldpagina.'],
            'fr' => ['Plus de pays et de régions', 'Le Commons s\'ouvre à de nouveaux pays, chacun avec des curateurs qui connaissent les routes. Demandez le vôtre sur la page d\'inscription.'],
            'de' => ['Mehr Länder und Regionen', 'The Commons öffnet in neuen Ländern, jeweils mit Kuratoren, die die Straßen kennen. Fordere dein Land auf der Mitmachen-Seite an.'],
            'es' => ['Más países y regiones', 'El Commons se abre en nuevos países, cada uno con curadores que conocen las carreteras. Pide el tuyo en la página de participación.'],
        ]],
        ['scout_devices', 'next', 30, [
            'en' => ['Scout on more devices', 'Tag what you pass on Android first, then on Hammerhead Karoo and iPhone.'],
            'nl' => ['Scout op meer toestellen', 'Markeer wat je passeert, eerst op Android, daarna op Hammerhead Karoo en iPhone.'],
            'fr' => ['Scout sur plus d\'appareils', 'Signalez ce que vous croisez, d\'abord sur Android, puis sur Hammerhead Karoo et iPhone.'],
            'de' => ['Scout auf mehr Geräten', 'Markiere, woran du vorbeifährst, zuerst auf Android, dann auf Hammerhead Karoo und iPhone.'],
            'es' => ['Scout en más dispositivos', 'Marca lo que encuentras, primero en Android y después en Hammerhead Karoo e iPhone.'],
        ]],
        ['route_export', 'next', 40, [
            'en' => ['Take a route with you', 'Shape a route around what the map shows, then send it to the planner or device you already use.'],
            'nl' => ['Neem een route mee', 'Vorm een route rond wat de kaart laat zien en stuur hem naar de planner of het toestel dat je al gebruikt.'],
            'fr' => ['Emportez un itinéraire', 'Esquissez-le d\'après ce que montre la carte, puis envoyez-le au planificateur ou à l\'appareil que vous utilisez déjà.'],
            'de' => ['Nimm eine Route mit', 'Forme sie anhand dessen, was die Karte zeigt, und schick sie an den Planer oder das Gerät, das du schon nutzt.'],
            'es' => ['Llévate una ruta', 'Dale forma según lo que muestra el mapa y envíala al planificador o dispositivo que ya usas.'],
        ]],
        ['open_api', 'next', 50, [
            'en' => ['The open data API, complete', 'Every place, climb and route readable through one stable, documented API, plus bulk downloads of the data and the map tiles, so anyone can build on the Commons or host a copy.'],
            'nl' => ['De open-data-API, compleet', 'Elke plek, beklimming en route leesbaar via één stabiele, gedocumenteerde API, plus bulkdownloads van de data en de kaarttegels, zodat iedereen op de Commons kan bouwen of er een kopie van kan hosten.'],
            'fr' => ['L\'API de données ouvertes, complète', 'Chaque lieu, col et itinéraire lisible par une API stable et documentée, avec des téléchargements en masse des données et des tuiles de la carte, pour que chacun puisse bâtir sur le Commons ou en héberger une copie.'],
            'de' => ['Die Open-Data-API, vollständig', 'Jeder Ort, jeder Anstieg und jede Route über eine stabile, dokumentierte API lesbar, dazu Massendownloads der Daten und der Kartenkacheln, damit alle auf The Commons aufbauen oder eine Kopie betreiben können.'],
            'es' => ['La API de datos abiertos, completa', 'Cada lugar, puerto y ruta legible mediante una API estable y documentada, además de descargas masivas de los datos y de las teselas del mapa, para que cualquiera pueda construir sobre el Commons o alojar una copia.'],
        ]],
        ['osm_giveback', 'later', 10, [
            'en' => ['Giving back to OpenStreetMap', 'What riders confirm here, offered back upstream in a form OpenStreetMap can take.'],
            'nl' => ['Teruggeven aan OpenStreetMap', 'Wat fietsers hier bevestigen, stroomopwaarts aangeboden in een vorm die OpenStreetMap kan opnemen.'],
            'fr' => ['Rendre à OpenStreetMap', 'Ce que les cyclistes confirment ici, proposé en amont sous une forme qu\'OpenStreetMap peut accepter.'],
            'de' => ['An OpenStreetMap zurückgeben', 'Was Fahrer hier bestätigen, stromaufwärts angeboten in einer Form, die OpenStreetMap annehmen kann.'],
            'es' => ['Devolver a OpenStreetMap', 'Lo que los ciclistas confirman aquí, ofrecido río arriba en una forma que OpenStreetMap pueda aceptar.'],
        ]],
        ['region_portrait', 'later', 20, [
            'en' => ['A portrait of each region', 'The region page counts what is there. It should say what the riding is like: how hilly, how much is paved, the big climbs, the signed network, water and toilets per hundred kilometres, the routes riders recommend, and how fresh the data is.'],
            'nl' => ['Een portret van elke regio', 'De regiopagina telt wat er is. Ze zou moeten zeggen hoe het fietsen er is: hoe heuvelachtig, hoeveel verhard, de grote klimmen, het bewegwijzerde netwerk, water en toiletten per honderd kilometer, de routes die fietsers aanraden, en hoe vers de gegevens zijn.'],
            'fr' => ['Un portrait de chaque région', 'La page d\'une région compte ce qui s\'y trouve. Elle devrait dire à quoi ressemble le vélo là-bas : le relief, la part de routes revêtues, les grands cols, le réseau balisé, l\'eau et les toilettes aux cent kilomètres, les itinéraires que les cyclistes recommandent, et la fraîcheur des données.'],
            'de' => ['Ein Porträt jeder Region', 'Die Regionsseite zählt, was da ist. Sie sollte sagen, wie das Radfahren dort ist: wie hügelig, wie viel asphaltiert, die großen Anstiege, das beschilderte Netz, Wasser und Toiletten pro hundert Kilometer, die Routen, die Radfahrer empfehlen, und wie frisch die Daten sind.'],
            'es' => ['Un retrato de cada región', 'La página de una región cuenta lo que hay. Debería decir cómo se rueda allí: cuánto desnivel, cuánto asfalto, los grandes puertos, la red señalizada, agua y aseos por cada cien kilómetros, las rutas que recomiendan los ciclistas y lo frescos que son los datos.'],
        ]],
        ['reviews', 'later', 30, [
            'en' => ['Your own words on any place', 'A short note of your own on a tap, a climb, a stay or a route, next to the facts: what it was like when you were there. Several riders, several notes. Not a rating.'],
            'nl' => ['Je eigen woorden bij elke plek', 'Een korte notitie van jezelf bij een kraan, een klim, een overnachting of een route, naast de feiten: hoe het was toen jij er was. Meerdere fietsers, meerdere notities. Geen cijfer.'],
            'fr' => ['Vos propres mots sur n\'importe quel lieu', 'Une courte note à vous sur une fontaine, un col, un hébergement ou un itinéraire, à côté des faits : ce que c\'était quand vous y étiez. Plusieurs cyclistes, plusieurs notes. Pas une note chiffrée.'],
            'de' => ['Deine eigenen Worte zu jedem Ort', 'Eine kurze eigene Notiz zu einem Brunnen, einem Anstieg, einer Unterkunft oder einer Route, neben den Fakten: wie es war, als du dort warst. Mehrere Radfahrer, mehrere Notizen. Keine Bewertung.'],
            'es' => ['Tus propias palabras sobre cualquier lugar', 'Una nota breve tuya sobre una fuente, un puerto, un alojamiento o una ruta, junto a los datos: cómo era cuando estuviste. Varios ciclistas, varias notas. No es una puntuación.'],
        ]],
        ['favourites', 'later', 40, [
            'en' => ['Your favourite places', 'Save a tap, a climb, a stay or a route to a favourites list in your profile, so you find it again before the next ride.'],
            'nl' => ['Je favoriete plekken', 'Bewaar een tappunt, een klim, een overnachting of een route in een favorietenlijst in je profiel, zodat je hem voor de volgende rit terugvindt.'],
            'fr' => ['Vos lieux favoris', 'Enregistrez un point d’eau, une montée, un hébergement ou un itinéraire dans une liste de favoris de votre profil, pour le retrouver avant la prochaine sortie.'],
            'de' => ['Deine Lieblingsorte', 'Speichere einen Wasserhahn, einen Anstieg, eine Unterkunft oder eine Route in einer Favoritenliste in deinem Profil, damit du sie vor der nächsten Fahrt wiederfindest.'],
            'es' => ['Tus lugares favoritos', 'Guarda una fuente, una subida, un alojamiento o una ruta en una lista de favoritos de tu perfil, para encontrarlo antes de la próxima salida.'],
        ]],
        ['heatmap', 'later', 50, [
            'en' => ['Where riders like to go', 'A heat layer that lifts the places many riders value, such as views and climbs. Built from anonymous points only, and a spot only shows once enough different riders are behind it, so it never points to one person.'],
            'nl' => ['Waar fietsers graag komen', 'Een warmtelaag die de plekken uitlicht die veel fietsers waarderen, zoals uitzichten en beklimmingen. Alleen uit anonieme punten, en een plek verschijnt pas als genoeg verschillende fietsers erachter staan, zodat hij nooit naar één persoon wijst.'],
            'fr' => ['Là où les cyclistes aiment aller', 'Une couche de chaleur qui met en avant les lieux appréciés par beaucoup, comme les panoramas et les cols. Uniquement à partir de points anonymes, et un lieu n\'apparaît que lorsque assez de cyclistes différents le soutiennent, pour ne jamais désigner une seule personne.'],
            'de' => ['Wo Radfahrende gern hinfahren', 'Eine Wärmeebene, die Orte hervorhebt, die viele schätzen, etwa Aussichten und Anstiege. Nur aus anonymen Punkten, und ein Ort erscheint erst, wenn genug verschiedene Radfahrende dahinterstehen, damit er nie auf eine Person zeigt.'],
            'es' => ['Adónde les gusta ir a los ciclistas', 'Una capa de calor que destaca los lugares que muchos valoran, como miradores y puertos. Solo a partir de puntos anónimos, y un lugar aparece solo cuando hay suficientes ciclistas distintos detrás, para que nunca señale a una persona.'],
        ]],
        ['standing', 'later', 60, [
            'en' => ['Contributor standing', 'Riders whose work keeps being approved earn a lighter touch, such as edits going live at once, instead of being granted it by hand.'],
            'nl' => ['Aanzien van bijdragers', 'Fietsers wier werk steeds wordt goedgekeurd verdienen een lichtere hand, zoals bewerkingen die meteen live gaan, in plaats van het handmatig te krijgen.'],
            'fr' => ['La réputation du contributeur', 'Les cyclistes dont le travail est régulièrement approuvé gagnent une main plus légère, comme des modifications publiées aussitôt, au lieu de l\'obtenir à la main.'],
            'de' => ['Ansehen als Beitragender', 'Fahrer, deren Arbeit immer wieder angenommen wird, verdienen eine leichtere Hand, etwa Änderungen, die sofort live gehen, statt sie von Hand zu bekommen.'],
            'es' => ['Reputación del colaborador', 'Los ciclistas cuyo trabajo se aprueba una y otra vez se ganan una mano más ligera, como ediciones que se publican al instante, en vez de recibirla a mano.'],
        ]],
        ['map_a11y', 'later', 70, [
            'en' => ['Make the map usable without a mouse', 'The honest gap in our accessibility statement: keyboard and screen-reader access to the map itself, not only to the pages around it.'],
            'nl' => ['De kaart bruikbaar maken zonder muis', 'Het eerlijke gat in onze toegankelijkheidsverklaring: toetsenbord- en schermlezertoegang tot de kaart zelf, niet alleen tot de pagina\'s eromheen.'],
            'fr' => ['Rendre la carte utilisable sans souris', 'Le manque assumé de notre déclaration d\'accessibilité : l\'accès clavier et lecteur d\'écran à la carte elle-même, pas seulement aux pages autour.'],
            'de' => ['Die Karte ohne Maus benutzbar machen', 'Die ehrliche Lücke in unserer Barrierefreiheitserklärung: Tastatur- und Screenreader-Zugang zur Karte selbst, nicht nur zu den Seiten drumherum.'],
            'es' => ['Hacer el mapa usable sin ratón', 'El hueco que reconocemos en nuestra declaración de accesibilidad: acceso con teclado y lector de pantalla al mapa mismo, no solo a las páginas de alrededor.'],
        ]],
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'roadmap_item, roadmap_item_text (seeded), roadmap_proposal, roadmap_proposal_image, roadmap_vote, roadmap_follow';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE roadmap_item (
            id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            status VARCHAR(8) NOT NULL,
            state VARCHAR(8) DEFAULT 'live' NOT NULL,
            position INT NOT NULL,
            issue INT DEFAULT NULL,
            source_proposal_id BIGINT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            CONSTRAINT roadmap_item_status_check CHECK (status IN ('now', 'next', 'later')),
            CONSTRAINT roadmap_item_state_check CHECK (state IN ('live', 'shipped', 'removed')),
            CONSTRAINT roadmap_item_closed_check CHECK ((state = 'live') = (closed_at IS NULL))
        )");
        $this->addSql('CREATE INDEX idx_roadmap_item_list ON roadmap_item (state, status, position)');

        $this->addSql('CREATE TABLE roadmap_item_text (
            id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            item_id BIGINT NOT NULL,
            locale VARCHAR(8) NOT NULL,
            title VARCHAR(120) NOT NULL,
            body TEXT NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_roadmap_item_text ON roadmap_item_text (item_id, locale)');
        $this->addSql('ALTER TABLE roadmap_item_text ADD CONSTRAINT fk_roadmap_item_text_item FOREIGN KEY (item_id) REFERENCES roadmap_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE roadmap_proposal (
            id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            user_id BIGINT DEFAULT NULL,
            title VARCHAR(120) NOT NULL,
            problem TEXT NOT NULL,
            idea TEXT NOT NULL,
            benefit TEXT DEFAULT NULL,
            locale VARCHAR(8) NOT NULL,
            status VARCHAR(12) NOT NULL,
            promoted_item_id BIGINT DEFAULT NULL,
            public_title VARCHAR(120) DEFAULT NULL,
            public_body TEXT DEFAULT NULL,
            decision_note TEXT DEFAULT NULL,
            decided_by BIGINT DEFAULT NULL,
            decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT roadmap_proposal_status_check CHECK (status IN ('pending', 'open', 'promoted', 'declined')),
            CONSTRAINT roadmap_proposal_promoted_check CHECK (promoted_item_id IS NULL OR status = 'promoted')
        )");
        $this->addSql('CREATE INDEX idx_roadmap_proposal_status ON roadmap_proposal (status, created_at)');
        $this->addSql('CREATE INDEX idx_roadmap_proposal_user ON roadmap_proposal (user_id, created_at)');
        $this->addSql('ALTER TABLE roadmap_proposal ADD CONSTRAINT fk_roadmap_proposal_item FOREIGN KEY (promoted_item_id) REFERENCES roadmap_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE roadmap_item ADD CONSTRAINT fk_roadmap_item_proposal FOREIGN KEY (source_proposal_id) REFERENCES roadmap_proposal (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE roadmap_proposal_image (
            id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            proposal_id BIGINT NOT NULL,
            mime_type VARCHAR(40) NOT NULL,
            bytes BYTEA NOT NULL,
            byte_size INT NOT NULL,
            width INT NOT NULL,
            height INT NOT NULL,
            state VARCHAR(12) DEFAULT 'ready' NOT NULL,
            refusal VARCHAR(64) DEFAULT NULL,
            consented_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            shown BOOLEAN DEFAULT false NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT roadmap_proposal_image_shown_check CHECK (shown = false OR consented_at IS NOT NULL)
        )");
        $this->addSql('CREATE UNIQUE INDEX uniq_roadmap_proposal_image ON roadmap_proposal_image (proposal_id)');
        $this->addSql('ALTER TABLE roadmap_proposal_image ADD CONSTRAINT fk_roadmap_proposal_image_proposal FOREIGN KEY (proposal_id) REFERENCES roadmap_proposal (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN roadmap_proposal_image.bytes IS 'The image as the worker re-encoded it; never the uploaded file while ready. Served to its rider and the desk; public only when the rider allowed it (consented_at) and the desk chose to show it (shown).'");

        foreach (['roadmap_vote', 'roadmap_follow'] as $table) {
            $this->addSql("CREATE TABLE {$table} (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                user_id BIGINT NOT NULL,
                item_ref VARCHAR(80) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )");
            $this->addSql("CREATE UNIQUE INDEX uniq_{$table} ON {$table} (user_id, item_ref)");
            $this->addSql("CREATE INDEX idx_{$table}_item ON {$table} (item_ref)");
        }

        foreach (self::SEED as [, $status, $position, $texts]) {
            $this->addSql(
                'INSERT INTO roadmap_item (status, position, created_at, updated_at) VALUES (:status, :position, NOW(), NOW())',
                ['status' => $status, 'position' => $position],
            );
            foreach ($texts as $locale => [$title, $body]) {
                $this->addSql(
                    'INSERT INTO roadmap_item_text (item_id, locale, title, body)
                     SELECT MAX(id), :locale, :title, :body FROM roadmap_item',
                    ['locale' => $locale, 'title' => $title, 'body' => $body],
                );
            }
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE roadmap_follow');
        $this->addSql('DROP TABLE roadmap_vote');
        $this->addSql('DROP TABLE roadmap_proposal_image');
        $this->addSql('ALTER TABLE roadmap_item DROP CONSTRAINT fk_roadmap_item_proposal');
        $this->addSql('DROP TABLE roadmap_proposal');
        $this->addSql('DROP TABLE roadmap_item_text');
        $this->addSql('DROP TABLE roadmap_item');
    }
}
