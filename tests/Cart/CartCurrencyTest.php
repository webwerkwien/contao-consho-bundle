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
 * For the cart, a currency switch is the same as a language switch:
 * the line items stay and are recalculated.
 *
 * Laid down in Block A, section 4.0 (line items move along). The
 * software can only do that because `tl_consho_cart_item` knows product,
 * quantity and time and **no amount** — the calculation happens only on display.
 *
 * This test pins both together: that the table holds no prices and that
 * the same cart in two currencies yields the same line items and two
 * different totals. Without it, someone eventually builds it in — a
 * stored amount on the line item would show up in no other test and
 * would make the switch impossible without anything turning red.
 *
 * The home country stays AT in both runs: it belongs to the shop, not to
 * the root page, and that is why it is 20 % both times.
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

        $this->insertShop(['id' => self::SHOP, 'country' => 'AT', 'fallbackLanguage' => 'de']);

        // Two articles, one price each in every currency. The pound prices are
        // deliberately not converted euro prices — in the shop a currency is
        // a price list of its own, not an exchange rate.
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
         * Checked on the raw table, not through a class that reads only the
         * columns it knows anyway: an amount column added at some point would
         * not even catch the eye of such a reader.
         *
         * The names are spelled out here so that an additional column that is
         * not money stands out as well — then the line has to be read when it
         * is changed, and that is exactly the point.
         */
        $columns = array_column(
            $this->connection->fetchAllAssociative('PRAGMA table_info(tl_consho_cart_item)'),
            'name',
        );

        // subscriptionRule since Abo-2 (P9): the id of the chosen model, not an
        // amount — its price is looked up on every request like any other.
        // renews since Abo-7b: the id of the renewed subscription, also not an
        // amount.
        self::assertSame(['id', 'pid', 'tstamp', 'product', 'quantity', 'addedAt', 'subscriptionRule', 'renews'], $columns);
    }

    public function testDerselbeWarenkorbInZweiWaehrungenHatDieselbenPositionenUndZweiSummen(): void
    {
        $items = $this->filledCart();

        $inEuro = $this->pricer()->price($items, 'EUR', 'AT', 'AT', self::NOW);
        $inPfund = $this->pricer()->price($items, 'GBP', 'AT', 'AT', self::NOW);

        // The same line items, in the same order, with the same quantities.
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

        // Two amounts. 2 × 10.00 + 1 × 20.00 = 40.00 net → 48.00 gross;
        // 2 × 7.50 + 1 × 15.00 = 30.00 net → 36.00 gross. 20 % both times,
        // because the home country belongs to the shop and does not move along.
        self::assertSame('EUR', $inEuro->currency);
        self::assertSame('48.00', $inEuro->gross);

        self::assertSame('GBP', $inPfund->currency);
        self::assertSame('36.00', $inPfund->gross);
    }

    public function testNachDemWechselLiegtNochDasselbeInDerDatenbank(): void
    {
        // The other half of the same statement: pricing is a pure read
        // operation. A cart that is changed by displaying it in a second
        // currency would no longer be displaying.
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
