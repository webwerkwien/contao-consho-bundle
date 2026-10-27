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
 * What becomes of drafts and duplicates when copying — against a real
 * SQLite database.
 *
 * Read from vendor/contao/core-bundle/contao/drivers/DC_Table.php:
 * copyChildren() stamps every copied child row with time(), even a draft
 * that was never saved (tstamp 0), and then calls the oncopy_callback of the
 * child table with (new id, $dc with id = source row). copyAll calls
 * copy(true) and stamps likewise; a single act=copy leaves tstamp 0.
 *
 * Why the stamped copy of a draft is reset rather than deleted: copyAll()
 * queues the next copied row with pid=<id of this copy> & mode=1 after this
 * copy; DC_Table::getNewPosition() looks up its pid for that (without a
 * sorting column of its own) via `SELECT pid … WHERE id=<this copy>`. If the
 * row is missing, $this->set['pid'] stays unchanged — the next copy keeps
 * the pid it was created with, namely that of the source row, and lands
 * back in the source product or source shipping method, every further copy
 * in a chain. Resetting to tstamp 0 keeps the id as an anchor and still
 * keeps the row invisible (tstamp > 0 rule).
 */
class PriceCopyDraftTest extends TestCase
{
    use TaxFixture;

    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Only the columns the listeners read or write — types as in the DCA.
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
        // A price opened with "New" and left, then the product copied: the copy
        // would otherwise be a valid price of 0. Not deleted but reset to
        // tstamp 0 — copyAll appends the next copy to this id (see the class
        // docblock).
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
        // act=copy: the copy has tstamp 0 and opens for editing — "Back" cleans
        // it up itself. The core passes the id as a string here.
        $this->connection->insert('tl_consho_price', ['id' => 1, 'pid' => 1, 'tstamp' => 0]);
        $this->connection->insert('tl_consho_price', ['id' => 2, 'pid' => 1, 'tstamp' => 0]);

        $this->priceListener()->onCopy('2', $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_price'));
    }

    public function testVersandbetragGestempelteKopieEinesEntwurfsWirdWiederEntwurf(): void
    {
        // As with the product price: reset, not deleted (class docblock).
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 3, 'tstamp' => 0]);
        $this->connection->insert('tl_consho_shipping_price', ['id' => 2, 'pid' => 30, 'tstamp' => self::NOW]);

        $this->shippingListener()->onCopy(2, $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_shipping_price'));
        self::assertSame(0, $this->tstamp('tl_consho_shipping_price', 2));
    }

    public function testVersandbetragEinzelkopieEinerGespeichertenZeileBleibtUnveraendertTrotzBelegterWaehrung(): void
    {
        // act=copy: DC_Table::copy() leaves a single copy with tstamp 0 — it is
        // already a draft. afterCopy()'s own tstamp<1 check takes effect first
        // and leaves the row untouched, even if the currency is already taken in
        // the target shipping method (pins the `< 1` early return).
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 3, 'tstamp' => 5, 'net' => '4.0000']);
        $this->connection->insert('tl_consho_shipping_price', ['id' => 2, 'pid' => 3, 'tstamp' => 0, 'net' => '4.0000']);

        $this->shippingListener()->onCopy(2, $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_shipping_price'));
        self::assertSame(0, $this->tstamp('tl_consho_shipping_price', 2));
        self::assertSame(5, $this->tstamp('tl_consho_shipping_price', 1));
    }

    public function testVersandbetragQuellzeileExistiertNichtMehrLaesstDieGestempelteKopieUnangetastet(): void
    {
        // The source row has been deleted in the meantime (e.g. edited at the same
        // time). onCopy() must then not reset the copy — afterCopy() runs as usual
        // and leaves it saved if the currency is free.
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 4, 'tstamp' => self::NOW, 'net' => '4.0000']);

        $this->shippingListener()->onCopy(1, $this->dc(999));

        self::assertSame([1], $this->ids('tl_consho_shipping_price'));
        self::assertSame(self::NOW, $this->tstamp('tl_consho_shipping_price', 1));
    }

    public function testVersandbetragGestempelteKopieMitBereitsBelegterWaehrungWirdEntwurf(): void
    {
        // "Copy multiple" into the same shipping method: two saved amounts in EUR
        // would be a random shipping price. The copy only counts once saved.
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 3, 'tstamp' => 5, 'net' => '4.0000']);
        $this->connection->insert('tl_consho_shipping_price', ['id' => 2, 'pid' => 3, 'tstamp' => self::NOW, 'net' => '4.0000']);

        $this->shippingListener()->onCopy(2, $this->dc(1));

        self::assertSame([1, 2], $this->ids('tl_consho_shipping_price'));
        self::assertSame(0, $this->tstamp('tl_consho_shipping_price', 2));
        self::assertSame(5, $this->tstamp('tl_consho_shipping_price', 1));
    }

    public function testVersandbetragGestempelteKopieInEinerFreienWaehrungBleibtGespeichert(): void
    {
        // The target shipping method has only an amount in CHF — EUR is free there.
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
     * As the core passes it: id is the source row.
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
