<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalMarketRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Since C-1c the market pair comes from the link root page → shop: the currency
 * from the root page, the home country from the shop (specification 4.0). Before that this
 * class read both from `tl_consho_shop` — a shop was thus exactly one market.
 *
 * The effect of the missing `published` filter — the price form accepts a
 * currency that only an unpublished root page carries — is covered in
 * PriceInputListenerTest, where the form lives.
 */
class DbalMarketRepositoryTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
    }

    public function testJedeWaehrungEinesStartpunktsErgibtMitDemHeimatlandDesShopsEinenMarkt(): void
    {
        // One shop, two root pages: the Austrian company sells via
        // /en/ in pounds. That is ONE invoicing entity and still two markets.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'en', 1, 'GBP');

        self::assertEquals([new Market('EUR', 'AT'), new Market('GBP', 'AT')], $this->markets());
    }

    public function testZweiStartpunkteDerselbenWaehrungSindEinMarkt(): void
    {
        // The usual case of a bilingual shop: de and en, both in euros.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'en', 1, 'EUR');

        self::assertEquals([new Market('EUR', 'AT')], $this->markets());
    }

    public function testEinStartpunktOhneWaehrungBringtKeinenMarktUndNimmtKeinenWeg(): void
    {
        // It cannot display anything — a price without a currency is no statement.
        // The other root pages of the same shop are unaffected.
        // It is reported on the back-end start page as RootWithoutCurrency
        // (DbalShopScopeIssueSource) — here it just silently drops out of the markets,
        // and that is exactly the intended volume.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'en', 1, '');

        self::assertEquals([new Market('EUR', 'AT')], $this->markets());
    }

    public function testOhneHeimatlandKeinMarkt(): void
    {
        // There is nothing to calculate a gross amount with.
        $this->insertShop(['id' => 1, 'country' => '']);
        $this->insertRootPage(1, 'de', 1, 'EUR');

        self::assertSame([], $this->markets());
    }

    public function testDieselbeWaehrungAusZweiLaendernSindZweiMaerkte(): void
    {
        // C25: the same net price, two tax rates, two gross amounts.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertShop(['id' => 2, 'country' => 'DE']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'de', 2, 'EUR');

        self::assertEquals([new Market('EUR', 'AT'), new Market('EUR', 'DE')], $this->markets());
    }

    public function testEineSeiteDieKeinStartpunktIstZaehltNicht(): void
    {
        // consho_currency is on every page. A leftover value on a page
        // that was once a root page must not become a sales signal — the same
        // condition as in RootCurrencyMigration.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'regular', 'consho_shop' => 1, 'consho_currency' => 'EUR']);

        self::assertSame([], $this->markets());
    }

    public function testEinStartpunktOhneShopZaehltNicht(): void
    {
        // Another website in the same installation.
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'consho_shop' => 0, 'consho_currency' => 'EUR']);

        self::assertSame([], $this->markets());
    }

    public function testEinStartpunktMitGeloeschtemShopZaehltNicht(): void
    {
        // The link is an INNER JOIN: without a shop there is no home country
        // and thus no gross amount.
        $this->insertRootPage(1, 'de', 404, 'EUR');

        self::assertSame([], $this->markets());
    }

    public function testDieMaerkteStehenNachWaehrungUndLandSortiert(): void
    {
        $this->insertShop(['id' => 1, 'country' => 'DE']);
        $this->insertShop(['id' => 2, 'country' => 'AT']);
        $this->insertShop(['id' => 3, 'country' => 'CH']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->insertRootPage(2, 'de', 2, 'EUR');
        $this->insertRootPage(3, 'de', 3, 'CHF');

        self::assertEquals([new Market('CHF', 'CH'), new Market('EUR', 'AT'), new Market('EUR', 'DE')], $this->markets());
    }

    public function testEinUnveroeffentlichterStartpunktBringtSeinenMarktTrotzdem(): void
    {
        // 🎯 This is how a currency is prepared before anyone sees it: create the
        // root page, choose GBP, maintain prices, then publish. A
        // published filter would take the GBP price rows out of the projection as soon as
        // someone unpublishes the root page — the same mistake Block A once made
        // and discarded with the derived delivery countries
        // (Block A, section 7).
        $this->insertShop(['id' => 1, 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1, 'EUR');
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'language' => 'en', 'consho_shop' => 1, 'consho_currency' => 'GBP', 'published' => 0]);

        self::assertEquals([new Market('EUR', 'AT'), new Market('GBP', 'AT')], $this->markets());
    }

    /**
     * @return list<Market>
     */
    private function markets(): array
    {
        return (new DbalMarketRepository($this->connection))->findMarkets();
    }
}
