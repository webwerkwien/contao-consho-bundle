<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModes;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Typ 1 ist digital, Typ 2 körperlich, Typ 3 eine Dienstleistung. Regeln 5 und 9 gibt es, 7 ist gelöscht.
 */
class SaleModesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // Aus der DCA; saleOnce mit der Vorgabe an (P1).
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        DcaSchema::create($this->connection, 'tl_consho_subscription_rule');

        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'physical']);
        $this->connection->insert('tl_consho_product_type', ['id' => 3, 'kind' => 'service']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 5, 'title' => 'Monatsabo']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 9, 'title' => 'Jahresabo']);
    }

    public function testEinBestehendesProduktIstEinEinmalkauf(): void
    {
        // P1: Die Vorgabe der Spalte macht aus jedem Produkt beim Update einen Einmalkauf.
        $this->connection->insert('tl_consho_product', ['id' => 1, 'productType' => 1]);

        $settings = (new SaleModes($this->connection))->of(1);

        self::assertTrue($settings->once);
        self::assertFalse($settings->subscription());
        self::assertSame([0], $settings->priceRules());
    }

    public function testNurImAboInDerReihenfolgeDerAuswahl(): void
    {
        $this->product(1, rules: ['9', '5'], once: false);

        $settings = (new SaleModes($this->connection))->of(1);

        self::assertFalse($settings->once);
        self::assertSame([9, 5], $settings->rules);
        self::assertSame([9, 5], $settings->priceRules());
    }

    public function testEinmalkaufUndAbo(): void
    {
        $this->product(1, rules: ['5']);

        self::assertSame([0, 5], (new SaleModes($this->connection))->of(1)->priceRules());
    }

    public function testEineGeloeschteRegelFaelltHeraus(): void
    {
        $this->product(1, rules: ['5', '7', '9']);

        self::assertSame([5, 9], (new SaleModes($this->connection))->of(1)->rules);
    }

    public function testOhneHakenBeimAboZaehltDieGespeicherteAuswahlNicht(): void
    {
        // Das Häkchen entfernt, die Auswahl bleibt in der Spalte stehen.
        $this->product(1, rules: ['5'], subscription: false);

        self::assertSame([], (new SaleModes($this->connection))->of(1)->rules);
    }

    public function testKoerperlicheWareIstImmerEinEinmalkauf(): void
    {
        // Ein Paket ohne Häkchen wäre sonst unverkäuflich.
        $this->product(1, rules: ['5'], once: false, type: 2);

        $settings = (new SaleModes($this->connection))->of(1);

        self::assertTrue($settings->once);
        self::assertSame([], $settings->rules);
    }

    public function testOhneTypGiltKoerperlich(): void
    {
        // Wie DbalArticleShippingData: das Merkmal schaltet Pflichten ein.
        $this->product(1, rules: ['5'], once: false, type: 0);

        self::assertSame([0], (new SaleModes($this->connection))->of(1)->priceRules());
    }

    public function testDieVarianteLiestDasGrundprodukt(): void
    {
        // P2: eigene Werte der Variante zählen nicht.
        $this->product(1, rules: ['5'], once: false, type: 3);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'productType' => 1, 'saleOnce' => 1, 'saleSubscription' => 0]);

        $settings = (new SaleModes($this->connection))->of(2);

        self::assertFalse($settings->once);
        self::assertSame([5], $settings->rules);
    }

    public function testEineDienstleistungDarfEinAboSein(): void
    {
        // DL1: Mitgliedschaft im Fitnesscenter.
        $this->product(1, rules: ['5'], once: false, type: 3);

        $settings = (new SaleModes($this->connection))->of(1);

        self::assertFalse($settings->once);
        self::assertSame([5], $settings->rules);
    }

    public function testDieVarianteIstKoerperlichWennEsDasGrundproduktIst(): void
    {
        $this->product(1, rules: ['5'], once: false, type: 2);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'productType' => 1]);

        self::assertSame([0], (new SaleModes($this->connection))->of(2)->priceRules());
    }

    public function testEinUnbekannterArtikelIstEinEinmalkauf(): void
    {
        self::assertSame([0], (new SaleModes($this->connection))->of(99)->priceRules());
    }

    /**
     * @param list<string> $rules
     */
    private function product(int $id, array $rules = [], bool $once = true, bool $subscription = true, int $type = 1): void
    {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'productType' => $type,
            'saleOnce' => (int) $once,
            'saleSubscription' => (int) $subscription,
            'subscriptionRules' => serialize($rules),
        ]);
    }
}
