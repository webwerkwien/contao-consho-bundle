<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartItemRow;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTree;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

/**
 * Ein Währungswechsel ist für den Warenkorb dasselbe wie ein Sprachwechsel:
 * Die Positionen bleiben stehen und werden neu gerechnet.
 *
 * Entschieden von Michael am 2026-09-21, festgehalten in
 * docs/block-a-fundament.md, Abschnitt 4.0 („Positionen wandern mit"). Die
 * Software kann das nur, weil `tl_consho_cart_item` Produkt, Menge und
 * Zeitpunkt kennt und **keinen Betrag** — gerechnet wird erst beim Anzeigen.
 *
 * Dieser Test hält beides zusammen fest: dass die Tabelle preisfrei ist und
 * dass derselbe Warenkorb in zwei Währungen dieselben Positionen und zwei
 * verschiedene Summen ergibt. Ohne ihn baut es irgendwann jemand zu — ein
 * gespeicherter Betrag an der Position fiele in keinem anderen Test auf und
 * machte den Wechsel unmöglich, ohne dass etwas rot würde.
 *
 * Das Heimatland bleibt in beiden Läufen AT: Es hängt am Shop, nicht am
 * Startpunkt, und deshalb sind es auch beide Male 20 %.
 */
class CartCurrencyTest extends TestCase
{
    use CartSchema;
    use CatalogSchema;
    use TaxFixture;

    private const SHOP = 1;
    private const STOCK = 1;
    private const BACKPACK = 2;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->createCatalogSchema($this->connection);

        $this->cartConnection = $this->connection;
        $this->createCartTables();

        $this->insertShop(['id' => self::SHOP, 'currency' => 'EUR', 'country' => 'AT', 'fallbackLanguage' => 'de']);

        // Zwei Artikel, je ein Preis in jeder Währung. Die Pfundpreise sind
        // bewusst keine umgerechneten Europreise — eine Währung ist im Shop
        // eine eigene Preisliste, kein Wechselkurs.
        $this->insertProduct(['id' => self::STOCK, 'title' => 'Wanderstock', 'published' => 1]);
        $this->insertProductShop(['pid' => self::STOCK, 'shop' => self::SHOP]);
        $this->insertPrice(['pid' => self::STOCK, 'net' => '10.0000', 'currency' => 'EUR']);
        $this->insertPrice(['pid' => self::STOCK, 'net' => '7.5000', 'currency' => 'GBP']);

        $this->insertProduct(['id' => self::BACKPACK, 'title' => 'Rucksack', 'published' => 1]);
        $this->insertProductShop(['pid' => self::BACKPACK, 'shop' => self::SHOP]);
        $this->insertPrice(['pid' => self::BACKPACK, 'net' => '20.0000', 'currency' => 'EUR']);
        $this->insertPrice(['pid' => self::BACKPACK, 'net' => '15.0000', 'currency' => 'GBP']);
    }

    public function testDieGespeicherteWarenkorbzeileTraegtKeinenBetrag(): void
    {
        /*
         * An der rohen Tabelle geprüft, nicht durch eine Klasse, die ohnehin
         * nur die Spalten liest, die sie kennt: Eine Betragsspalte, die
         * irgendwann dazukommt, fiele einem solchen Leser gar nicht auf.
         *
         * Die Namen stehen hier ausgeschrieben, damit auch eine zusätzliche
         * Spalte auffällt, die kein Geld ist — dann ist die Zeile beim Ändern
         * zu lesen, und genau das ist der Zweck.
         */
        $columns = array_column(
            $this->connection->fetchAllAssociative('PRAGMA table_info(tl_consho_cart_item)'),
            'name',
        );

        // subscriptionRule seit Abo-2 (P9): die Id des gewählten Modells, kein
        // Betrag — der Preis dazu wird wie jeder andere bei jeder Anfrage
        // nachgeschlagen. renews seit Abo-7b: die Id des verlängerten Abos,
        // ebenfalls kein Betrag.
        self::assertSame(['id', 'pid', 'tstamp', 'product', 'quantity', 'addedAt', 'subscriptionRule', 'renews'], $columns);
    }

    public function testDerselbeWarenkorbInZweiWaehrungenHatDieselbenPositionenUndZweiSummen(): void
    {
        $items = $this->filledCart();

        $inEuro = $this->pricer()->price($items, 'EUR', 'AT', 'AT', self::NOW);
        $inPfund = $this->pricer()->price($items, 'GBP', 'AT', 'AT', self::NOW);

        // Dieselben Positionen, in derselben Reihenfolge, mit denselben Mengen.
        self::assertSame(
            [self::STOCK, self::BACKPACK],
            array_map(static fn ($line): int => $line->articleId, $inEuro->lines),
        );
        self::assertSame(
            array_map(static fn ($line): int => $line->articleId, $inEuro->lines),
            array_map(static fn ($line): int => $line->articleId, $inPfund->lines),
        );
        self::assertSame(
            array_map(static fn ($line): string => $line->quantity, $inEuro->lines),
            array_map(static fn ($line): string => $line->quantity, $inPfund->lines),
        );

        // Zwei Beträge. 2 × 10,00 + 1 × 20,00 = 40,00 netto → 48,00 brutto;
        // 2 × 7,50 + 1 × 15,00 = 30,00 netto → 36,00 brutto. Beide Male 20 %,
        // weil das Heimatland am Shop hängt und sich nicht mitbewegt.
        self::assertSame('EUR', $inEuro->currency);
        self::assertSame('48.00', $inEuro->gross);

        self::assertSame('GBP', $inPfund->currency);
        self::assertSame('36.00', $inPfund->gross);
    }

    public function testNachDemWechselLiegtNochDasselbeInDerDatenbank(): void
    {
        // Die andere Hälfte derselben Aussage: Das Preisen ist eine reine
        // Leseoperation. Ein Warenkorb, den das Anzeigen in einer zweiten
        // Währung verändert, wäre kein Anzeigen mehr.
        $items = $this->filledCart();
        $vorher = $this->storedItems();

        $this->pricer()->price($items, 'EUR', 'AT', 'AT', self::NOW);
        $this->pricer()->price($items, 'GBP', 'AT', 'AT', self::NOW);

        self::assertSame($vorher, $this->storedItems());
    }

    /**
     * @return list<CartItemRow>
     */
    private function filledCart(): array
    {
        $repository = new DbalCartRepository($this->connection);
        $cartId = $repository->create(CartToken::hash(str_repeat('a', 43)), 0, self::SHOP, self::NOW);

        $repository->addItem($cartId, self::STOCK, '2.0000', self::NOW);
        $repository->addItem($cartId, self::BACKPACK, '1.0000', self::NOW);

        return $repository->itemsOf($cartId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedItems(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_cart_item ORDER BY id');
    }

    private function pricer(): CartPricer
    {
        $prices = new DbalPriceRepository($this->connection);

        return new CartPricer(
            new DbalArticleTree($this->connection),
            new ArticlePriceResolver(
                new PriceCalculator($prices),
                $prices,
                new BasePriceCalculator(),
                $this->grossCalculator(),
                new DbalArticleTaxClasses($this->connection),
            ),
            $this->grossCalculator(),
        );
    }
}
