<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Intl\Countries;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PriceInputListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ShippingPriceInputListener;
use Webwerkwien\ContaoConshoBundle\Pricing\HomeCountries;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceEntry;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

/**
 * Was beim Kopieren aus Entwürfen und Duplikaten wird — gegen eine echte
 * SQLite-Datenbank.
 *
 * Aus vendor/contao/core-bundle/contao/drivers/DC_Table.php gelesen:
 * copyChildren() stempelt jede kopierte Kindzeile mit time(), auch einen nie
 * gespeicherten Entwurf (tstamp 0), und ruft danach den oncopy_callback der
 * Kindtabelle mit (neue id, $dc mit id = Quellzeile). copyAll ruft copy(true)
 * und stempelt ebenso; ein einzelnes act=copy lässt tstamp 0.
 *
 * Warum die gestempelte Kopie eines Entwurfs zurückgesetzt und nicht gelöscht
 * wird: copyAll() reiht die nächste kopierte Zeile mit pid=<id dieser Kopie>
 * & mode=1 an diese Kopie an; DC_Table::getNewPosition() sucht dafür (ohne
 * eigene Sortierspalte) per `SELECT pid … WHERE id=<diese Kopie>` deren pid.
 * Fehlt die Zeile, bleibt $this->set['pid'] unverändert — die nächste Kopie
 * behält die pid, mit der sie entstanden ist, nämlich die der Quellzeile, und
 * landet zurück im Quellprodukt bzw. in der Quell-Versandart, jede weitere
 * Kopie in Kette. Ein Zurücksetzen auf tstamp 0 lässt die id als Anker
 * bestehen und hält die Zeile trotzdem unsichtbar (tstamp > 0-Regel).
 */
class PriceCopyDraftTest extends TestCase
{
    use TaxFixture;

    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Nur die Spalten, die die Listener lesen oder schreiben — Typen wie im DCA.
        foreach (['tl_consho_price', 'tl_consho_shipping_price'] as $table) {
            $this->connection->executeStatement(
                'CREATE TABLE '.$table.' (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    pid INTEGER NOT NULL DEFAULT 0,
                    tstamp INTEGER NOT NULL DEFAULT 0,
                    currency VARCHAR(3) NOT NULL DEFAULT \'\',
                    net NUMERIC(12,4) NOT NULL DEFAULT \'0.0000\',
                    maxWeight NUMERIC(10,4) NOT NULL DEFAULT \'0.0000\'
                )',
            );
        }
    }

    public function testProduktpreisGestempelteKopieEinesEntwurfsWirdWiederEntwurf(): void
    {
        // Ein mit „Neu" geöffneter und verlassener Preis, danach das Produkt kopiert:
        // Die Kopie wäre sonst ein gültiger Preis von 0. Nicht gelöscht, sondern auf
        // tstamp 0 zurückgesetzt — copyAll hängt die nächste Kopie an diese id an
        // (siehe Klassen-Docblock).
        $this->connection->insert('tl_consho_price', ['id' => 1, 'pid' => 1, 'tstamp' => 0]);
        $this->connection->insert('tl_consho_price', ['id' => 2, 'pid' => 20, 'tstamp' => self::NOW]);

        $this->priceListener()->onCopy(2, $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_price'));
        self::assertSame(0, $this->tstamp('tl_consho_price', 2));
    }

    public function testProduktpreisKopieEinerGespeichertenZeileBleibt(): void
    {
        $this->connection->insert('tl_consho_price', ['id' => 1, 'pid' => 1, 'tstamp' => 5, 'net' => '10.0000']);
        $this->connection->insert('tl_consho_price', ['id' => 2, 'pid' => 20, 'tstamp' => self::NOW, 'net' => '10.0000']);

        $this->priceListener()->onCopy(2, $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_price'));
        self::assertSame(self::NOW, $this->tstamp('tl_consho_price', 2));
    }

    public function testEinzelkopieEinesEntwurfsBleibt(): void
    {
        // act=copy: Die Kopie hat tstamp 0 und öffnet sich zum Bearbeiten —
        // „Zurück" räumt sie selbst ab. Der Kern reicht die id hier als String.
        $this->connection->insert('tl_consho_price', ['id' => 1, 'pid' => 1, 'tstamp' => 0]);
        $this->connection->insert('tl_consho_price', ['id' => 2, 'pid' => 1, 'tstamp' => 0]);

        $this->priceListener()->onCopy('2', $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_price'));
    }

    public function testVersandbetragGestempelteKopieEinesEntwurfsWirdWiederEntwurf(): void
    {
        // Wie beim Produktpreis: zurückgesetzt, nicht gelöscht (Klassen-Docblock).
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 3, 'tstamp' => 0]);
        $this->connection->insert('tl_consho_shipping_price', ['id' => 2, 'pid' => 30, 'tstamp' => self::NOW]);

        $this->shippingListener()->onCopy(2, $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_shipping_price'));
        self::assertSame(0, $this->tstamp('tl_consho_shipping_price', 2));
    }

    public function testVersandbetragEinzelkopieEinerGespeichertenZeileBleibtUnveraendertTrotzBelegterWaehrung(): void
    {
        // act=copy: DC_Table::copy() lässt eine einzelne Kopie mit tstamp 0 —
        // sie ist schon ein Entwurf. afterCopy()s eigene tstamp<1-Prüfung greift
        // zuerst und lässt die Zeile unangetastet, auch wenn die Währung in der
        // Zielversandart schon belegt ist (pins the `< 1` early return).
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 3, 'tstamp' => 5, 'net' => '4.0000']);
        $this->connection->insert('tl_consho_shipping_price', ['id' => 2, 'pid' => 3, 'tstamp' => 0, 'net' => '4.0000']);

        $this->shippingListener()->onCopy(2, $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_shipping_price'));
        self::assertSame(0, $this->tstamp('tl_consho_shipping_price', 2));
        self::assertSame(5, $this->tstamp('tl_consho_shipping_price', 1));
    }

    public function testVersandbetragQuellzeileExistiertNichtMehrLaesstDieGestempelteKopieUnangetastet(): void
    {
        // Quellzeile wurde inzwischen gelöscht (z. B. gleichzeitig bearbeitet).
        // onCopy() darf die Kopie dann nicht zurücksetzen — afterCopy() läuft
        // wie gewohnt und lässt sie bei freier Währung gespeichert.
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 4, 'tstamp' => self::NOW, 'net' => '4.0000']);

        $this->shippingListener()->onCopy(1, $this->dc(999));

        self::assertSame([1], $this->ids('tl_consho_shipping_price'));
        self::assertSame(self::NOW, $this->tstamp('tl_consho_shipping_price', 1));
    }

    public function testVersandbetragGestempelteKopieMitBereitsBelegterWaehrungWirdEntwurf(): void
    {
        // „Mehrere kopieren" in dieselbe Versandart: zwei gespeicherte Beträge in EUR
        // wären ein zufälliger Versandpreis. Die Kopie gilt erst nach dem Speichern.
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 3, 'tstamp' => 5, 'net' => '4.0000']);
        $this->connection->insert('tl_consho_shipping_price', ['id' => 2, 'pid' => 3, 'tstamp' => self::NOW, 'net' => '4.0000']);

        $this->shippingListener()->onCopy(2, $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_shipping_price'));
        self::assertSame(0, $this->tstamp('tl_consho_shipping_price', 2));
        self::assertSame(5, $this->tstamp('tl_consho_shipping_price', 1));
    }

    public function testVersandbetragGestempelteKopieInEinerFreienWaehrungBleibtGespeichert(): void
    {
        // Die Zielversandart hat nur einen Betrag in CHF — EUR ist dort frei.
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 3, 'tstamp' => 5, 'net' => '4.0000']);
        $this->connection->insert('tl_consho_shipping_price', ['id' => 2, 'pid' => 4, 'tstamp' => 5, 'currency' => 'CHF', 'net' => '5.0000']);
        $this->connection->insert('tl_consho_shipping_price', ['id' => 3, 'pid' => 4, 'tstamp' => self::NOW, 'net' => '4.0000']);

        $this->shippingListener()->onCopy(3, $this->dc(1));

        self::assertSame(self::NOW, $this->tstamp('tl_consho_shipping_price', 3));
    }

    private function priceListener(): PriceInputListener
    {
        return new PriceInputListener(...$this->dependencies());
    }

    private function shippingListener(): ShippingPriceInputListener
    {
        return new ShippingPriceInputListener(...$this->dependencies());
    }

    /**
     * @return array{HomeCountries, CountryProvider, Connection, PriceEntry, RequestStack, \Closure(): int}
     */
    private function dependencies(): array
    {
        $repository = $this->createMock(MarketRepositoryInterface::class);
        $repository->method('findMarkets')->willReturn([new Market('EUR', 'AT'), new Market('CHF', 'CH')]);

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountries')->willReturn(['AT' => 'Österreich', 'CH' => 'Schweiz']);

        $requests = new RequestStack();
        $requests->push(new Request());

        return [
            new HomeCountries($repository),
            new CountryProvider($countries),
            $this->connection,
            new PriceEntry($this->grossCalculator(['AT' => '20.0000'])),
            $requests,
            static fn (): int => self::NOW,
        ];
    }

    /**
     * Wie der Kern ihn übergibt: id ist die Quellzeile.
     */
    private function dc(int $sourceId): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnMap([['id', $sourceId]]);

        return $dc;
    }

    /**
     * @return list<int>
     */
    private function ids(string $table): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM '.$table.' ORDER BY id'));
    }

    private function tstamp(string $table, int $id): int
    {
        return (int) $this->connection->fetchOne('SELECT tstamp FROM '.$table.' WHERE id = ?', [$id]);
    }
}
