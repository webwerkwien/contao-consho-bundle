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
        // C2: the order is the one in the checkout, the topmost is the default.
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
        // A duplicate can come from a restored version (task 7):
        // fetchAllKeyValue() keeps the last row per pid, ORDER BY id makes
        // "last" mean "the one with the highest id" instead of the storage
        // engine's chance.
        // Without this PRAGMA SQLite returns rows in rowid order anyway; the
        // test could then never fail even without the ORDER BY in the
        // repository.
        $this->connection->executeStatement('PRAGMA reverse_unordered_selects = ON');

        $this->method(3, 'Post');
        $this->shop(['3']);
        $this->price(3, 'EUR', '4.0000');
        $this->price(3, 'EUR', '5.0000');

        self::assertSame('5.0000', $this->repository()->forShop(1, 'EUR')[0]->config['net']);
    }

    /**
     * E-4: All rows of the currency with their weight limit, sorted by
     * weight — the steps of the weight scale. Not other currencies and not
     * rows that were never saved.
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

        self::assertSame(['AT', 'DE'], $method->countries, 'in der kanonischen Schreibweise');
        self::assertSame(['120.0000', '300.0000', '31.5000'], [$method->limits->maxLength, $method->limits->maxGirth, $method->limits->maxPieceWeight]);
    }

    public function testEinNieGespeicherterBetragZaehltNicht(): void
    {
        // Contao creates the row with tstamp 0 when the form is opened (C-1b, task 2b).
        $this->method(3, 'Post');
        $this->shop(['3']);
        $this->price(3, 'EUR', '4.0833', tstamp: 0);

        self::assertNull($this->repository()->forShop(1, 'EUR')[0]->config['net']);
    }

    public function testModuleinstellungenKommenAusJsonDataDerKernschluesselGewinnt(): void
    {
        // `net` is reserved (ShippingMethodTypeInterface): a module field of the
        // same name must not cover the amount.
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
        // act=toggle skips the form's mandatory checks. A row that was never
        // saved therefore has type '' or taxClass 0, but can still be
        // 'published'.
        $this->method(3, 'Ohne Typ', type: '', taxClass: 1);
        $this->method(4, 'Ohne Steuerklasse', type: 'flat_rate', taxClass: 0);
        $this->method(5, 'Post', type: 'flat_rate', taxClass: 1);
        $this->shop(['3', '4', '5']);

        self::assertSame([5], $this->ids($this->repository()->forShop(1, 'EUR')));
    }

    public function testDerTitelKommtInDerSpracheDerSeite(): void
    {
        // Titles are in the main language, translated per page
        // (TranslatableFields). Without this test a missing translation in the
        // checkout would go unnoticed — the German title would simply be there.
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
