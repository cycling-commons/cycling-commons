<?php
// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The roadmap item "Take a route with you" (Next) becomes "Plan a ride on
 * your bike" (Now): riders asked for a simple one-day planner, and the GPX
 * export the old item promised is part of it (docs/specs/route-planner.md
 * section 2). By migration, not on the desk, so the item lands on every
 * environment with the feature's code (roadmap-and-changelog.md section 8.1).
 */
final class Version20261014010000 extends AbstractMigration
{
    private const string OLD_TITLE = 'Take a route with you';

    /** The item's row, found by the English title it has until this migration. */
    private const string ITEM = "(SELECT item_id FROM roadmap_item_text WHERE locale = 'en' AND title = 'Take a route with you')";

    /** locale => [title, body]; English last, because the lookup is by the old English title. */
    private const array NEW_TEXTS = [
        'nl' => ['Een rit plannen op jouw fiets', 'Klik een start, een paar stops en een eindpunt, en de lijn volgt de wegen die bij jouw fiets passen. Voeg een kraan, een klim of een uitzicht van de kaart toe als stop, bekijk het hoogteprofiel, en neem de rit mee als GPX die hier weer opengaat.'],
        'fr' => ['Planifier une sortie sur votre vélo', 'Cliquez un départ, quelques arrêts et une arrivée, et la ligne suit les routes qui conviennent au vélo que vous roulez. Ajoutez une fontaine, un col ou un point de vue de la carte comme arrêt, voyez le profil d\'altitude, et emportez la sortie en GPX, qui se rouvre ici.'],
        'de' => ['Eine Fahrt auf deinem Rad planen', 'Klicke einen Start, ein paar Stopps und ein Ziel, und die Linie folgt den Straßen, die zu deinem Rad passen. Füge einen Brunnen, einen Anstieg oder eine Aussicht von der Karte als Stopp hinzu, sieh das Höhenprofil, und nimm die Fahrt als GPX mit, das sich hier wieder öffnet.'],
        'es' => ['Planifica una salida con tu bici', 'Pulsa un inicio, unas paradas y un final, y la línea sigue las carreteras que van con la bici que usas. Añade una fuente, un puerto o un mirador del mapa como parada, mira el perfil de altura, y llévate la salida como un GPX que se vuelve a abrir aquí.'],
        'en' => ['Plan a ride on your bike', 'Click a start, a few stops and an end, and the line follows the roads that suit the bike you ride. Add a tap, a climb or a view from the map as a stop, see the height profile, and take the ride with you as a GPX that opens here again.'],
    ];

    /** The texts as seeded by Version20261013010000, for down(); English last again. */
    private const array OLD_TEXTS = [
        'nl' => ['Neem een route mee', 'Vorm een route rond wat de kaart laat zien en stuur hem naar de planner of het toestel dat je al gebruikt.'],
        'fr' => ['Emportez un itinéraire', 'Esquissez-le d\'après ce que montre la carte, puis envoyez-le au planificateur ou à l\'appareil que vous utilisez déjà.'],
        'de' => ['Nimm eine Route mit', 'Forme sie anhand dessen, was die Karte zeigt, und schick sie an den Planer oder das Gerät, das du schon nutzt.'],
        'es' => ['Llévate una ruta', 'Dale forma según lo que muestra el mapa y envíala al planificador o dispositivo que ya usas.'],
        'en' => ['Take a route with you', 'Shape a route around what the map shows, then send it to the planner or device you already use.'],
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Roadmap: "Take a route with you" becomes "Plan a ride on your bike", in Now.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE roadmap_item SET status = :status, position = :position, updated_at = NOW() WHERE id = '.self::ITEM, ['status' => 'now', 'position' => 30]);
        foreach (self::NEW_TEXTS as $locale => [$title, $body]) {
            $this->addSql('UPDATE roadmap_item_text SET title = :title, body = :body WHERE locale = :locale AND item_id = '.self::ITEM, ['title' => $title, 'body' => $body, 'locale' => $locale]);
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $item = "(SELECT item_id FROM roadmap_item_text WHERE locale = 'en' AND title = 'Plan a ride on your bike')";
        $this->addSql('UPDATE roadmap_item SET status = :status, position = :position, updated_at = NOW() WHERE id = '.$item, ['status' => 'next', 'position' => 40]);
        foreach (self::OLD_TEXTS as $locale => [$title, $body]) {
            $this->addSql('UPDATE roadmap_item_text SET title = :title, body = :body WHERE locale = :locale AND item_id = '.$item, ['title' => $title, 'body' => $body, 'locale' => $locale]);
        }
    }
}
