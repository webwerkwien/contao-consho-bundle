<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shipping\DbalShippingMethodRepository;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethod;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalShippingMethodRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['tl_consho_shop', 'tl_consho_shipping_method', 'tl_consho_translation', 'tl_consho_shipping_price'] as $table) {
            DcaSchema::create($this->connection, $table);
        }
    }

    public function testDieArtenKommenInDerReihenfolgeDerAuswahl(): void
    {
        // C2: die Reihenfolge ist die im Checkout, die oberste ist Standard.
        $this->method(3, 'Post');
        $this->method(5, 'Abholung');
        $this->shop(['5', '3']);

        self::assertSame([5, 3], $this->ids($this->repository()->forShop(1, 'EUR')));
    }

    public function testUnveroeffentlichteUndGeloeschteArtenFallenWeg(): void
    {
        $this->method(3, 'Post');
        $this->method(4, 'Spedition', published: 0);
        $this->shop(['3', '99', '4']);

        self::assertSame([3], $this->ids($this->repository()->forShop(1, 'EUR')));
    }

    public function testOhneAuswahlGibtEsKeineArten(): void
    {
        $this->method(3, 'Post');
        $this->connection->insert('tl_consho_shop', ['id' => 1]);

        self::assertSame([], $this->repository()->forShop(1, 'EUR'));
        self::assertSame([], $this->repository()->forShop(77, 'EUR'));
    }

    public function testDerBetragDerWaehrungKommtUnterNetInDieEinstellungen(): void
    {
        $this->method(3, 'Post');
        $this->shop(['3']);
        $this->price(3, 'EUR', '4.0833');
        $this->price(3, 'CHF', '5.5');

        self::assertSame('4.0833', $this->repository()->forShop(1, 'EUR')[0]->config['net']);
        self::assertSame('5.5000', $this->repository()->forShop(1, 'CHF')[0]->config['net']);
        self::assertNull($this->repository()->forShop(1, 'GBP')[0]->config['net']);
    }

    public function testBeiZweiZeilenDerselbenWaehrungGewinntDieNeueste(): void
    {
        // Ein Duplikat kann aus einer wiederhergestellten Version entstehen
        // (Task 7, Review): fetchAllKeyValue() behält die letzte Zeile je pid,
        // ORDER BY id macht "letzte" zu "die mit der höchsten id" statt
        // Zufall der Speicher-Engine.
        // Ohne diese PRAGMA liefert SQLite Zeilen ohnehin in rowid-Reihenfolge
        // zurück; der Test könnte dann auch ohne das ORDER BY im Repository
        // niemals fehlschlagen.
        $this->connection->executeStatement('PRAGMA reverse_unordered_selects = ON');

        $this->method(3, 'Post');
        $this->shop(['3']);
        $this->price(3, 'EUR', '4.0000');
        $this->price(3, 'EUR', '5.0000');

        self::assertSame('5.0000', $this->repository()->forShop(1, 'EUR')[0]->config['net']);
    }

    /**
     * E-4: Alle Zeilen der Währung mit ihrer Gewichtsgrenze, nach Gewicht
     * sortiert — die Stufen der Gewichtsstaffel. Andere Währungen und nie
     * gespeicherte Zeilen nicht.
     */
    public function testDieStufenKommenUnterRatesNachGewicht(): void
    {
        $this->method(3, 'Post');
        $this->shop(['3']);
        $this->connection->insert('tl_consho_shipping_price', ['pid' => 3, 'tstamp' => 5, 'currency' => 'EUR', 'net' => '7.5', 'maxWeight' => '10']);
        $this->connection->insert('tl_consho_shipping_price', ['pid' => 3, 'tstamp' => 5, 'currency' => 'EUR', 'net' => '4', 'maxWeight' => '2']);
        $this->connection->insert('tl_consho_shipping_price', ['pid' => 3, 'tstamp' => 5, 'currency' => 'CHF', 'net' => '9', 'maxWeight' => '2']);
        $this->connection->insert('tl_consho_shipping_price', ['pid' => 3, 'tstamp' => 0, 'currency' => 'EUR', 'net' => '1', 'maxWeight' => '1']);

        self::assertSame(
            [['maxWeight' => '2.0000', 'net' => '4.0000'], ['maxWeight' => '10.0000', 'net' => '7.5000']],
            $this->repository()->forShop(1, 'EUR')[0]->config['rates'],
        );
    }

    public function testLaenderUndGrenzenKommenMit(): void
    {
        $this->method(3, 'Post');
        $this->connection->update('tl_consho_shipping_method', ['countries' => serialize(['at', 'DE']), 'maxLength' => '120', 'maxGirth' => '300', 'maxPieceWeight' => '31.5'], ['id' => 3]);
        $this->shop(['3']);

        $method = $this->repository()->forShop(1, 'EUR')[0];

        self::assertSame(['AT', 'DE'], $method->countries, 'in der kanonischen Schreibweise (Spec A 707)');
        self::assertSame(['120.0000', '300.0000', '31.5000'], [$method->limits->maxLength, $method->limits->maxGirth, $method->limits->maxPieceWeight]);
    }

    public function testEinNieGespeicherterBetragZaehltNicht(): void
    {
        // Contao legt die Zeile beim Öffnen der Maske mit tstamp 0 an (C-1b, Task 2b).
        $this->method(3, 'Post');
        $this->shop(['3']);
        $this->price(3, 'EUR', '4.0833', tstamp: 0);

        self::assertNull($this->repository()->forShop(1, 'EUR')[0]->config['net']);
    }

    public function testModuleinstellungenKommenAusJsonDataDerKernschluesselGewinnt(): void
    {
        // `net` ist reserviert (ShippingMethodTypeInterface): ein Modulfeld gleichen
        // Namens darf den Betrag nicht überdecken.
        $this->method(3, 'DHL', type: 'dhl', jsonData: '{"zone":"EU","net":"999"}');
        $this->shop(['3']);

        $method = $this->repository()->forShop(1, 'EUR')[0];

        self::assertSame(['zone' => 'EU', 'net' => null, 'rates' => []], $method->config);
        self::assertSame('dhl', $method->type);
    }

    public function testSteuerregelUndBezugsklasseWerdenMitgeliefert(): void
    {
        $this->method(3, 'Post', taxMode: 'fixed', taxClass: 2);
        $this->shop(['3']);

        $method = $this->repository()->forShop(1, 'EUR')[0];

        self::assertSame(ShippingTaxMode::Fixed, $method->taxMode);
        self::assertSame(2, $method->taxClassId);
        self::assertSame('Post', $method->title);
    }

    public function testEineNieGespeicherteArtWirdUebersprungenAuchWennVeroeffentlicht(): void
    {
        // Nachtrag zum Review von Task 4: act=toggle überspringt die
        // Pflichtprüfungen der Maske. Eine nie gespeicherte Zeile hat also
        // type '' bzw. taxClass 0, kann aber trotzdem 'published' sein.
        $this->method(3, 'Ohne Typ', type: '', taxClass: 1);
        $this->method(4, 'Ohne Steuerklasse', type: 'flat_rate', taxClass: 0);
        $this->method(5, 'Post', type: 'flat_rate', taxClass: 1);
        $this->shop(['3', '4', '5']);

        self::assertSame([5], $this->ids($this->repository()->forShop(1, 'EUR')));
    }

    public function testDerTitelKommtInDerSpracheDerSeite(): void
    {
        // Titel liegen in der Hauptsprache, je Seite übersetzt
        // (TranslatableFields). Ohne diesen Test bliebe die Übersetzung im
        // Checkout unbemerkt aus — es stünde einfach der deutsche Titel da.
        $this->method(3, 'Paketversand');
        $this->price(3, 'EUR', '5.0000');
        $this->shop(['3']);
        $this->connection->insert('tl_consho_translation', [
            'pid' => 3,
            'ptable' => 'tl_consho_shipping_method',
            'language' => 'en',
            'title' => 'Parcel post',
        ]);

        self::assertSame('Parcel post', $this->repository()->forShop(1, 'EUR', 'en', 'de')[0]->title);
        self::assertSame('Paketversand', $this->repository()->forShop(1, 'EUR', 'de', 'de')[0]->title);
    }

    public function testOhneUebersetzungBleibtDerTitelDerHauptsprache(): void
    {
        $this->method(3, 'Paketversand');
        $this->price(3, 'EUR', '5.0000');
        $this->shop(['3']);

        self::assertSame('Paketversand', $this->repository()->forShop(1, 'EUR', 'fr', 'de')[0]->title);
    }

    private function repository(): DbalShippingMethodRepository
    {
        return new DbalShippingMethodRepository($this->connection);
    }

    /**
     * @param list<string> $ids
     */
    private function shop(array $ids): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'shippingMethods' => serialize($ids)]);
    }

    private function method(int $id, string $title, int $published = 1, string $type = 'flat_rate', string|null $jsonData = null, string $taxMode = 'proportional', int $taxClass = 1): void
    {
        $this->connection->insert('tl_consho_shipping_method', [
            'id' => $id, 'tstamp' => 5, 'type' => $type, 'title' => $title, 'taxMode' => $taxMode,
            'taxClass' => $taxClass, 'published' => $published, 'jsonData' => $jsonData,
        ]);
    }

    private function price(int $pid, string $currency, string $net, int $tstamp = 5): void
    {
        $this->connection->insert('tl_consho_shipping_price', ['pid' => $pid, 'tstamp' => $tstamp, 'currency' => $currency, 'net' => $net]);
    }

    /**
     * @param list<ShippingMethod> $methods
     *
     * @return list<int>
     */
    private function ids(array $methods): array
    {
        return array_map(static fn (ShippingMethod $m): int => $m->id, $methods);
    }
}
